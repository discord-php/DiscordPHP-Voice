# TODO

## Phase 3 validation follow-ups

- [x] Install/configure `phplint`: added `overtrue/phplint ^9.7` to `require-dev`, `composer lint` script, folded into `composer check`. CI updated to use `composer lint` instead of global tool.
- [ ] Document intentional BC-impacting changes in release notes or upgrade guidance:
  - `VoiceClient::$speakingStatus` and `VoiceClient::$ssrcToUserId` changed from `public` to `protected`.
  - `VoiceClient::$voiceDecoders` changed from untyped/null default to `public array $voiceDecoders = []`.
  - `Client\UDP` no longer `final` (kept effectively final by convention; only public usage is via `SocketFactory`).
- [ ] Address Composer/PHP 8.4 deprecation noise separately from the audit remediation work.

## Coverage follow-ups

Current coverage: **83.26%** statements (with legacy BC files excluded). Real remaining gaps require either real subprocesses or real `libdave` handles:

- [ ] `VoiceClient::start` / `readDCAOpus` / `createDecoder` / `monitorProcessExit` / `handleAudioData` recording branches — all spawn ffmpeg or DCA subprocesses. Best covered by extending the live integration "full session" test (`tests/Integration/VoiceConnectionTest.php`) rather than by mocking subprocesses out.
- [x] `Dave/Runtime` direct-FFI paths — `tests/Unit/Dave/RuntimeNativeFfiTest.php` added (6 tests, guarded by `DISCORDPHP_DAVE_LIBRARY`): `setSessionProtocolVersion`, `setExternalSender`, `getKeyRatchet` null path, `configureDecryptorPassthrough(false)`, `encryptWithEncryptor` without key ratchet, `decryptWithDecryptor` on garbage frame.
- [x] `Client/Packet::initBufferEncryption` / `initBufferNoEncryption` — deleted (zero callers, dead code confirmed). No tests needed.

## Security review notes

- The `file` protocol is intentionally retained in `Ffmpeg::encode`'s `-protocol_whitelist` because removing it broke local playback entirely. SSRF defence is enforced at the `VoiceClient::playFile` layer (URL scheme allowlist + private/reserved/loopback IP block + `localhost` block). If a stricter policy is desired, route local paths through `fopen`+`pipe:0` instead so the protocol whitelist can drop `file`.
- `MediaCryptoService::decrypt` returns `false` on DAVE auth failure rather than leaking the ciphertext as plaintext Opus. The legacy "passthrough on auth failure" behaviour is gone; do not reinstate it without a written threat-model justification.
