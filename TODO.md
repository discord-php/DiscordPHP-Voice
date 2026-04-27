# TODO

## Phase 3 validation follow-ups

- [ ] Install/configure `phplint` if CI expects it; the local validation run reported `phplint` as unavailable (`status 127`) and no Composer lint script exists.
- [ ] Document intentional BC-impacting changes in release notes or upgrade guidance:
  - `VoiceClient::$speakingStatus` and `VoiceClient::$ssrcToUserId` changed from `public` to `protected`.
  - `VoiceClient::$voiceDecoders` changed from untyped/null default to `public array $voiceDecoders = []`.
- [ ] Address Composer/PHP 8.4 deprecation noise separately from the audit remediation work.
