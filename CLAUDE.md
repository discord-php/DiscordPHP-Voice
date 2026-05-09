# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## First Step

Before doing anything else in a session on this repo, invoke the **`/caveman ultra`** skill. This is the project-mandated entry point for any work here — do not skip it, even for small edits.

## Project Snapshot

- PHP voice library for [DiscordPHP](https://github.com/discord-php/DiscordPHP/), package `discord-php-helpers/voice`, namespace `Discord\` (PSR-4, `src/Discord`).
- PHP `^8.3`. Hard requirements: `ext-ffi`, `ext-sodium`, `ext-json`, `libopus`, `ffmpeg`, and `libdave` (mandatory — Discord requires DAVE E2EE for all voice/video since 2026-03-01; `Manager` and `Client\WS` throw `LibDaveNotFoundException` if libdave is unavailable).
- More authoritative project docs that should be consulted instead of duplicated here:
  - `.github/copilot-instructions.md` — the most detailed architecture + conventions reference. Read it before any non-trivial change.
  - `docs/AUDIO_PIPELINE.md`, `docs/DAVE.md`, `docs/PROTOCOL.md`, `docs/TROUBLESHOOTING.md` — Mermaid diagrams and protocol details.
  - `CONTRIBUTING.md` — setup, code style, pre-push checklist.
  - `.github/skills/discord-voice-spec/SKILL.md` — the protocol-lookup skill for verifying opcodes / DAVE behaviour against Discord's official docs.

## Common Commands

Setup (one-time):

```bash
COMPOSER_ROOT_VERSION=dev-main composer install --no-interaction --prefer-dist
./scripts/setup-libdave.sh
export DISCORDPHP_DAVE_LIBRARY="$PWD/.cache/libdave/lib/libdave.so"   # .dylib on macOS, bin/libdave.dll on Windows
```

The `COMPOSER_ROOT_VERSION` override is required by the `team-reflex/discord-php` dev dependency. `setup-libdave.sh` auto-detects OS/arch (Linux, macOS, Windows on x64/ARM64).

Daily commands:

| Task | Command |
| --- | --- |
| Run full Pest suite (TestDox) | `composer unit` |
| Run Pest suite in parallel | `composer pest` |
| Run a single test file | `./vendor/bin/pest tests/Unit/Voice/WSSequenceAckTest.php` |
| Run tests by description substring | `./vendor/bin/pest --filter="heartbeat uses last received gateway sequence"` (Pest filters on the human-readable description, not the method name) |
| Coverage HTML report | `composer coverage` (sets `XDEBUG_MODE=coverage`, writes to `coverage/`) |
| Format with PHP-CS-Fixer | `composer cs` |
| Style check (no rewrite) | `composer cs:check` |
| Format with Pint | `composer pint` |
| PHPStan (level 5) | `composer phpstan` |
| Aggregate pre-push check | `composer check` (cs:check + unit) |
| Mutation testing (slow, local) | `composer infection` |
| PHPLint | `phplint` (CI installs as standalone tool, uses `.phplint.yml`) |

Live integration tests in `tests/Integration/VoiceConnectionTest.php` need `DISCORD_BOT_TOKEN` and `CHANNEL_ID` env vars; everything else mocks sockets.

## Architecture (Big Picture)

The full breakdown lives in `.github/copilot-instructions.md` — the points below are the ones you are likely to get wrong if you don't already know them.

**Connection lifecycle:** `Manager` validates channel permissions, opens one `VoiceClient` per guild, listens for `VOICE_STATE_UPDATE` / `VOICE_SERVER_UPDATE` from the main gateway, and only resolves the join promise after `ready`. `VoiceClient::setData()` is the actual boot trigger — it runs once `token`, `endpoint`, `session`, and `dnsConfig` are all present. Any change to the join flow has to update `Manager`, `VoiceClient`, and `Client\WS` together so the handshake still reaches `ready`.

**Outbound audio:** `playFile()` / `playRawStream()` → `Processes\Ffmpeg::encode()` (always emits Opus on stdout) → `OggStream` page reads → `UDP::sendBuffer()` → `Client\Packet::encrypt()`. Send timing is established in `playOggStream()`: it sets speaking state, delays first send by 500 ms, then `readOggOpus()` schedules one packet per frame on the React loop and owns the 16-bit sequence / 32-bit timestamp rollover and EOF/reset behaviour.

**Inbound audio:** `record()` attaches a UDP message listener; `handleAudioData()` maps SSRCs through `speakingStatus`, lazily creates per-user `ReceiveStream` instances, decodes Opus to PCM, and emits `channel-opus` / `channel-pcm`. The `record(RecordingFormat $format, callable $outputPath)` overload writes per-user WAV (pure PHP via `Recording\WavWriter`) or OGG (ffmpeg) files automatically; the bare `record()` call stays backwards compatible.

**DAVE (E2EE):** split across `Client\WS`, `Dave\State`, and `Dave\Runtime`. `Runtime` is a singleton that wraps `ext-ffi` + the platform `libdave.{so,dylib,dll}` and auto-discovers it under `.cache/libdave/{lib,bin}/`. FFI C declarations are read from the installed `dave.h` header (no inline CDEF fallback — missing/unparseable header throws). DAVE frame transforms are injected into `Client\Packet` callbacks and routed through `VoiceClient::encryptDaveFrame()` / `decryptDaveFrame()`. Every change to packet handling must preserve both RTP encryption *and* the optional DAVE media layer.

**Gateway sequence bookkeeping:** `Client\WS` records the last gateway sequence in `Dave\State` and reuses it as `seq_ack` in both heartbeats and resume payloads. Don't break this when touching reconnect, resume, or binary-frame handling.

## Conventions That Bite

- **Backwards-compatibility shims are load-bearing.** `Discord\Voice\Client` is an alias subclass for `VoiceClient`; `ReceiveStream` subclasses the legacy misspelled `RecieveStream`. Don't remove or rename these.
- **`VoiceClient::$ssrcToUserId` and `$speakingStatus` are `public` on purpose.** `WS::handleSpeaking` writes to `ssrcToUserId` from outside `VoiceClient`'s class scope — narrowing visibility is a fatal runtime error.
- **`ByteBuffer` lives in two places.** `Discord\Voice\ByteBuffer` is the active implementation. `Discord\Voice\Helpers\ByteBuffer` is a legacy duplicate kept for BC — use the non-`Helpers` namespace for new code. PHPStan excludes `src/Discord/Voice/Helpers` and `OpEnum.php` for this reason.
- **Playback state machine is strict.** Most public playback methods reject when not ready or already speaking. `pause()` keeps cadence by refreshing silence frames; `stop()` drains the buffer and inserts silence; `setVolume()` / `setAudioApplication()` are intentionally blocked while audio is playing.
- **DCA is legacy.** Prefer the Ogg/Opus path (`playOggStream()` / `playRawStream()` / `Ffmpeg::encode()`); `playDCAStream()` stays for compatibility only.
- **Library exceptions use static factories for runtime context.** Subclasses of `Exceptions\Libraries\` (e.g. `LibDaveNotFoundException`) are bare `\Exception` subclasses — when the message depends on runtime state, add `public static function fromRuntimeError(): self` and throw via the factory; don't build messages at the call site. All voice exceptions implement the `VoiceException` marker interface so callers can `catch (VoiceException $e)`.
- **`declare(strict_types=1);` is required at the top of every PHP file** (enforced by Pint via `pint.json`).

## Test Patterns

- `tests/Unit/` is pure logic (no sockets, no async). `tests/Feature/Voice/` covers gateway/WebSocket behaviour with mocked sockets and injected Discord objects. `tests/Integration/` is live-network only.
- No shared helper file — helpers live inline at the bottom of each test file under a `// Helpers` comment, named after the file's subject (e.g. `makeWsForSessionInitTest`).
- Complex constructors (`Discord`, `Channel`, `WS`, `VoiceClient`) are instantiated via `(new \ReflectionClass(Foo::class))->newInstanceWithoutConstructor()`; private state is poked with `\ReflectionProperty`. Mocking is PHPUnit's `getMockBuilder()` — Mockery is not used.
- Capture WS payloads by passing an array by reference into the mock `WebSocket::send()` closure (`$ws->send = function (string $data) use (&$sent) { $sent[] = json_decode($data, true); };`).
- **DAVE stubbing has three modes** — pick the right one:
  1. `Runtime::configureCallbacks(availabilityOverride: false)` to test the libdave-unavailable code path without unloading the real library.
  2. `Runtime::configureCallbacks(availabilityOverride: true, ...)` to inject fake native callbacks for MLS state-machine tests.
  3. Real `libdave.so` — **only** `tests/Unit/Dave/RuntimeTest.php` uses this; nothing else may depend on it.
  Every file that calls `configureCallbacks()` must include `afterEach(function (): void { Runtime::reset(); });` at the top level. Skip with `$this->markTestSkipped('Requires libdave to be available.')` (PHPUnit-style) when a test needs the real library.
- Verify new test files with `./vendor/bin/pest --compact <file>`, then run the full `composer unit` before pushing.

## Useful Cross-References

- Voice protocol questions (opcode numbers, payload shapes, DAVE/MLS, encryption modes, `seq_ack`) → use the `discord-voice-spec` skill in `.github/skills/discord-voice-spec/`. It queries Discord's official docs via MCP and is the authoritative answer for protocol details.
- Library docs / API references for third-party deps (ReactPHP, libdave, etc.) → use the Context7 MCP server rather than guessing.
