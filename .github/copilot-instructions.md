# Copilot Instructions

## Commands

| Purpose | Command | Notes |
| --- | --- | --- |
| Install dependencies | `COMPOSER_ROOT_VERSION=dev-main composer install --no-interaction --prefer-dist` | The root version override is required by the `team-reflex/discord-php` dev dependency. |
| Run the default Pest suite | `composer unit` | Expands to `pest --testdox`. |
| Run the parallel Pest suite | `composer pest` | Expands to `pest --parallel`. |
| Run one Pest test file | `./vendor/bin/pest tests/Unit/Voice/WSSequenceAckTest.php` | Use the file path when you want an exact class/file. Pest reads the same `tests/` tree and `phpunit.xml` config. |
| Run one Pest test by name | `./vendor/bin/pest --filter="heartbeat uses last received gateway sequence"` | Pest filters by the human-readable test description, so use a quoted description substring. |
| Run coverage | `composer coverage` | Requires Xdebug because the script sets `XDEBUG_MODE=coverage` and Pest writes the HTML report to `coverage/`. |
| Format with php-cs-fixer | `composer cs` | This is the formatter called out in `CONTRIBUTING.md`. |
| Check php-cs-fixer without rewriting files | `./vendor/bin/php-cs-fixer fix --dry-run --diff` | Use this when you only want a style check. |
| Run Pint | `composer pint` | Uses `pint.json` and enforces `declare(strict_types=1)`. |
| Run PHPLint | `phplint` | CI installs this as a standalone tool and uses `.phplint.yml`. |

For native DAVE coverage on Linux x64, run `./scripts/setup-libdave.sh`, then export `DISCORDPHP_DAVE_LIBRARY="$PWD/.cache/libdave/lib/libdave.so"` before running the DAVE tests.

## High-level architecture

- `Discord\Voice\Manager` is the entry point for joining voice. It validates channel permissions, creates one voice client per guild, listens for the main gateway `VOICE_STATE_UPDATE` and `VOICE_SERVER_UPDATE` events, and only resolves the join promise after the voice client emits `ready`. Session IDs are tracked in `Discord::$voice_sessions`.
- `Discord\Voice\Client` is a backwards-compatible subclass of `VoiceClient`. Nearly all behavior lives in `VoiceClient`.
- `VoiceClient` is the high-level state machine for playback and recording. It owns speaking state, sequence/timestamp counters, receive streams, decoder processes, and public operations like `playFile()`, `playRawStream()`, `playOggStream()`, `record()`, and `stopRecording()`.
- `Discord\Voice\Client\WS` owns the voice gateway connection: identify/resume, heartbeats, reconnects, speaking events, client connect/disconnect events, session description handling, and all DAVE gateway opcodes. `Discord\Factory\SocketFactory` is used from here to create the UDP client.
- `Discord\Voice\Client\UDP` owns IP discovery, UDP heartbeats, and RTP packet send/receive. It wraps outbound and inbound voice frames in `Discord\Voice\Client\Packet`, which handles RTP headers plus libsodium encryption/decryption.
- Outbound audio flow is `playFile()` / `playRawStream()` -> `Processes\Ffmpeg::encode()` -> `OggStream` packet reads -> `UDP::sendBuffer()` -> `Packet::encrypt()`. `playFile()` accepts local files or URLs, `playRawStream()` feeds PCM into ffmpeg with `-f s16le`, and `Ffmpeg::encode()` always emits Opus on stdout for `playOggStream()` to consume.
- `playOggStream()` is where send timing is established. It buffers the Ogg stream, sets speaking state, delays the first send by 500 ms, and then `readOggOpus()` schedules one packet per frame on the React loop. That method also owns the 16-bit sequence rollover, 32-bit timestamp rollover, and EOF/reset behavior.
- Inbound audio flow starts when `record()` attaches a UDP message listener. `handleAudioData()` maps SSRCs through `speakingStatus`, creates per-user `ReceiveStream` instances on demand, decodes Opus to PCM, and emits `channel-opus` / `channel-pcm` events.
- DAVE support is split across `Discord\Voice\Client\WS`, `Discord\Voice\Dave\State`, and `Discord\Voice\Dave\Runtime`. The gateway can negotiate DAVE protocol versions and MLS transitions, but real native behavior only exists when `ext-ffi` can load `libdave.so`. Without that runtime, the connection falls back to protocol version `0` and media frames stay in passthrough mode.

## Key conventions

- Preserve the public backwards-compatibility shims. `Discord\Voice\Client` still exists as an alias subclass for `VoiceClient`, and `ReceiveStream` still subclasses the legacy misspelled `RecieveStream`. Do not remove or rename these surfaces without keeping compatibility.
- `VoiceClient::setData()` is the boot trigger once `token`, `endpoint`, `session`, and `dnsConfig` are present. If you change join/setup flow, update `Manager`, `VoiceClient`, and `Client\WS` together so the handshake still reaches `ready`.
- Playback state changes are strict. Most public playback methods reject when the client is not ready or is already speaking; `pause()` keeps cadence by refreshing silence frames, `stop()` drains the buffer and inserts silence, and `setVolume()` / `setAudioApplication()` are intentionally blocked while audio is playing.
- DAVE frame transforms are injected through `Client\Packet` callbacks and routed through `VoiceClient::encryptDaveFrame()` / `decryptDaveFrame()`. Changes to packet handling need to preserve both RTP encryption and the optional DAVE media layer.
- `Client\WS` records the last gateway sequence in `Dave\State` and reuses it in both heartbeat and resume payloads as `seq_ack`. Changes around reconnect, resume, or binary voice frames need to keep that bookkeeping intact.
- DCA support is legacy. New work should prefer the Ogg/Opus path (`playOggStream()` / `playRawStream()` / `Ffmpeg::encode()`), while `playDCAStream()` remains for compatibility.
- Tests for DAVE and voice gateway behavior usually avoid live sockets. The existing tests build `WS` and `VoiceClient` instances with reflection, inject mocked Discord/WebSocket objects, and use `Runtime::configureCallbacks()` to simulate native DAVE behavior. Only the explicit runtime coverage in `tests/Unit/Dave/RuntimeTest.php` expects a real `libdave` library.
- New PHP source follows the repository-wide pattern: `declare(strict_types=1);`, PSR-4 `Discord\` namespaces, and the standard DiscordPHP file header. Use the existing php-cs-fixer/Pint configuration instead of hand-formatting.
