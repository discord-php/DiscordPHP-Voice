# TODO

## Phase 3 validation follow-ups

- [ ] Install/configure `phplint` if CI expects it; the local validation run reported `phplint` as unavailable (`status 127`) and no Composer lint script exists.
- [ ] Document intentional BC-impacting changes in release notes or upgrade guidance:
  - `VoiceClient::$speakingStatus` and `VoiceClient::$ssrcToUserId` changed from `public` to `protected`.
  - `VoiceClient::$voiceDecoders` changed from untyped/null default to `public array $voiceDecoders = []`.
  - `Client\UDP` no longer `final` (kept effectively final by convention; only public usage is via `SocketFactory`).
- [ ] Address Composer/PHP 8.4 deprecation noise separately from the audit remediation work.

## Coverage follow-ups

Current coverage: **83.26%** statements (with legacy BC files excluded). Real remaining gaps require either real subprocesses or real `libdave` handles:

- [ ] `VoiceClient::start` / `readDCAOpus` / `createDecoder` / `monitorProcessExit` / `handleAudioData` recording branches — all spawn ffmpeg or DCA subprocesses. Best covered by extending the live integration "full session" test (`tests/Integration/VoiceConnectionTest.php`) rather than by mocking subprocesses out.
- [ ] `Dave/Runtime` direct-FFI paths (`encryptWithEncryptor`, `setSessionProtocolVersion`, `setExternalSender`, `getKeyRatchet` non-callback path, etc.) — only reachable with a real `libdave.so` and live `SessionHandle`/`EncryptorHandle` pointers. Add a guarded suite that skips unless `DISCORDPHP_DAVE_LIBRARY` is set and produces real handles via `Runtime::createSession()` + `Runtime::createEncryptor()`.
- [ ] `Client/Packet::initBufferEncryption` / `initBufferNoEncryption` are deprecated XSalsa20Poly1305 paths kept only for back-compat — verify they still need to ship before adding tests for them.

## Security review notes

- The `file` protocol is intentionally retained in `Ffmpeg::encode`'s `-protocol_whitelist` because removing it broke local playback entirely. SSRF defence is enforced at the `VoiceClient::playFile` layer (URL scheme allowlist + private/reserved/loopback IP block + `localhost` block). If a stricter policy is desired, route local paths through `fopen`+`pipe:0` instead so the protocol whitelist can drop `file`.
- `MediaCryptoService::decrypt` returns `false` on DAVE auth failure rather than leaking the ciphertext as plaintext Opus. The legacy "passthrough on auth failure" behaviour is gone; do not reinstate it without a written threat-model justification.
