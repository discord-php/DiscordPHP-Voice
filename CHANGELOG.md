# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `VoiceException` marker interface — all library exceptions now implement `Discord\Voice\Exceptions\VoiceException` for unified catch blocks
- `examples/play-file.php` and `examples/record.php` — working code examples
- `docs/AUDIO_PIPELINE.md` — Mermaid diagrams for outbound/inbound audio pipeline, playback state machine, and audio format chain
- `docs/TROUBLESHOOTING.md` — platform-specific fix guide for all runtime exceptions (libdave, ffmpeg, opus, libsodium, permission errors)
- PHPStan level 5 static analysis (`composer phpstan`)
- `composer check` aggregate script (pint + cs:check + unit)
- `composer cs:check` dry-run style check
- EditorConfig CI validation job
- Comprehensive test suite expansion (402 tests / 1094 assertions): new files cover voice exceptions, VoiceClient playback guards, VoiceClient receive flow, Manager join-channel permission checks, WS gateway handlers, WS heartbeat/resume seq_ack bookkeeping, UDP transport (IP discovery + sendBuffer), Packet encrypt/decrypt roundtrip, Ogg edge cases (BOS/EOS/continued/multi-segment), Dave Runtime callback overrides, small-parts (User / HeaderValuesEnum / Dave handles / UserConnected), and process wrappers (Ffmpeg / DCA / OpusFfi)
- `tests/Integration/VoiceConnectionTest.php` — live voice gateway connection tests (require `DISCORD_BOT_TOKEN` + `CHANNEL_ID` env vars)

### Fixed

- `VoiceClient`: incorrect `DCA` class reference (was `Dca`)
- `VoiceClient`: float-to-int cast on timestamp calculation
- `RecieveStream`: `write()` and `pipe()` missing return values
- `VoiceClient::$ssrcToUserId` — changed visibility from `protected` to `public` so `WS::handleSpeaking` can write SSRC→user_id mappings from outside the class (previously threw a fatal error whenever a SPEAKING payload included an `ssrc`)
- `Client\WS` — binary voice gateway frames now emit the `ws-binary-message` event so DAVE binary opcodes reach the application layer
- `Dave\State::$groupId` — fixed resolution via `isset()` on a magic `__get` property (always returned `false`); now reads directly from the backing store
- `Client\WS` — stale MLS proposals no longer cause an infinite `INVALID_COMMIT_WELCOME` loop; three consecutive proposal failures close the socket with a descriptive error instead
- `Client\WS` — first voice WebSocket connections send Identify while true reconnects send Resume with `seq_ack` when available
- `Client\WS` — outbound DAVE MLS packets are sent as binary WebSocket frames; Session Description now initializes libdave state and sends Opcode 26 key package
- `Client\WS` — DAVE transition ID `0` executes locally without waiting for Opcode 22; remote decryptors retain key ratchets and use passthrough grace during setup
- `Client\Packet` — inbound RTP extension payload is stripped after transport decryption and before DAVE frame decryption so libdave receives the expected media frame bytes

### Changed

- CI now triggers on push and pull_request (previously workflow_dispatch only)
- PHP 8.0 removed from CI matrix (requires `^8.1.2`)
- `actions/checkout` bumped to v4
- `scripts/setup-libdave.sh` documented for Linux, macOS, Windows (x64 + ARM64)
- `CONTRIBUTING.md` expanded with full contributor guide

## [v8.0.5] - 2026-01-15

See [GitHub releases](https://github.com/discord-php/DiscordPHP-Voice/releases/tag/v8.0.5) for details.

## [v8.0.4] - 2026-01-15

See [GitHub releases](https://github.com/discord-php/DiscordPHP-Voice/releases/tag/v8.0.4) for details.

## [v8.0.3] - 2026-01-15

See [GitHub releases](https://github.com/discord-php/DiscordPHP-Voice/releases/tag/v8.0.3) for details.

## [v8.0.2] - 2026-01-14

See [GitHub releases](https://github.com/discord-php/DiscordPHP-Voice/releases/tag/v8.0.2) for details.

## [v8.0.1] - 2025-12-22

See [GitHub releases](https://github.com/discord-php/DiscordPHP-Voice/releases/tag/v8.0.1) for details.

## [v8.0.0] - 2025-12-08

See [GitHub releases](https://github.com/discord-php/DiscordPHP-Voice/releases/tag/v8.0.0) for details.

[Unreleased]: https://github.com/discord-php/DiscordPHP-Voice/compare/v8.0.5...HEAD
[v8.0.5]: https://github.com/discord-php/DiscordPHP-Voice/compare/v8.0.4...v8.0.5
[v8.0.4]: https://github.com/discord-php/DiscordPHP-Voice/compare/v8.0.3...v8.0.4
[v8.0.3]: https://github.com/discord-php/DiscordPHP-Voice/compare/v8.0.2...v8.0.3
[v8.0.2]: https://github.com/discord-php/DiscordPHP-Voice/compare/v8.0.1...v8.0.2
[v8.0.1]: https://github.com/discord-php/DiscordPHP-Voice/compare/v8.0.0...v8.0.1
[v8.0.0]: https://github.com/discord-php/DiscordPHP-Voice/releases/tag/v8.0.0
