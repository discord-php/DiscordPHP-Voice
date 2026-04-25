# Voice Protocol Reference

Authoritative PHP source: [`src/Discord/WebSockets/OpEnum.php`](../src/Discord/WebSockets/OpEnum.php).
Official Discord documentation: <https://discord.com/developers/docs/topics/opcodes-and-status-codes>.

## Table of Contents

- [Voice Gateway Opcodes (0–20)](#voice-gateway-opcodes-020)
- [DAVE E2EE Opcodes (21–31)](#dave-e2ee-opcodes-2131)
- [Voice Close Codes (4011–4016)](#voice-close-codes-40114016)
- [Gateway Opcodes (0–11, 31)](#gateway-opcodes-011-31)
- [Gateway Close Codes (4000–4014)](#gateway-close-codes-40004014)
- [Usage Notes](#usage-notes)

---

## Voice Gateway Opcodes (0–20)

These opcodes are used on the **voice** WebSocket connection (separate from the main gateway).

| Opcode | Constant | Direction | Description | When fired |
|--------|----------|-----------|-------------|------------|
| 0 | `VOICE_IDENTIFY` | client → server | Begin a voice WebSocket connection. | First message sent after WS connect; carries `server_id`, `user_id`, `session_id`, and `token`. |
| 1 | `VOICE_SELECT_PROTO` | client → server | Select the voice protocol and encryption mode. | Sent by the client after receiving `VOICE_READY` (op 2). |
| 2 | `VOICE_READY` | server → client | Complete the WebSocket handshake. | Server responds to `VOICE_IDENTIFY`; carries `ssrc`, `ip`, `port`, and supported encryption `modes`. |
| 3 | `VOICE_HEARTBEAT` | client → server | Keep the WebSocket connection alive. | Sent periodically at the interval given by `VOICE_HELLO`. |
| 4 | `VOICE_DESCRIPTION` | server → client | Describe the session (secret key for encryption). | Sent by the server after `VOICE_SELECT_PROTO`; carries the `secret_key` used by libsodium. |
| 5 | `VOICE_SPEAKING` | both | Identify which users are speaking. | Client sends before/after transmitting audio; server relays for all connected users. |
| 6 | `VOICE_HEARTBEAT_ACK` | server → client | Acknowledge a heartbeat. | Server replies to every `VOICE_HEARTBEAT`. |
| 7 | `VOICE_RESUME` | client → server | Resume a dropped voice connection. | Sent instead of `VOICE_IDENTIFY` when reconnecting with an existing session. |
| 8 | `VOICE_HELLO` | server → client | Pass the heartbeat interval. | First message from the server on a new connection; carries `heartbeat_interval`. |
| 9 | `VOICE_RESUMED` | server → client | Acknowledge a successful session resume. | Server reply to `VOICE_RESUME` when the session is still valid. |
| 11 | `VOICE_CLIENTS_CONNECT` | server → client | One or more clients have connected to the voice channel. | Also exposed as the deprecated alias `VOICE_CLIENT_CONNECT`. |
| 13 | `VOICE_CLIENT_DISCONNECT` | server → client | A client has disconnected from the voice channel. | Carries the `user_id` of the departing user. |
| 15 | `VOICE_CLIENT_UNKNOWN_15` | unknown | Undocumented opcode. | Observed in production but not in Discord's official documentation. |
| 18 | `VOICE_CLIENT_UNKNOWN_18` | unknown | Undocumented opcode. | Observed in production but not in Discord's official documentation. |
| 20 | `VOICE_CLIENT_PLATFORM` | server → client | Platform type of a connected user. | Not officially documented; assumed to carry the user's platform string. |

---

## DAVE E2EE Opcodes (21–31)

These opcodes extend the voice gateway to support Discord's **DAVE** (Discord Audio/Video E2EE) protocol via MLS (Messaging Layer Security). All are transmitted as binary frames (`BinaryFrame::encode()`). Opcodes marked **handled** have a dedicated handler in [`src/Discord/Voice/Client/WS.php`](../src/Discord/Voice/Client/WS.php).

| Opcode | Constant | Direction | Description | Handled in `WS.php` |
|--------|----------|-----------|-------------|---------------------|
| 21 | `VOICE_DAVE_PREPARE_TRANSITION` | server → client | A downgrade from the DAVE protocol is upcoming. | ✅ `handleDavePrepareTransition` |
| 22 | `VOICE_DAVE_EXECUTE_TRANSITION` | server → client | Execute a previously announced protocol transition. | ✅ `handleDaveExecuteTransition` |
| 23 | `VOICE_DAVE_TRANSITION_READY` | client → server | Acknowledge readiness for a previously announced transition. | ✅ `handleDaveTransitionReady` (sends reply) |
| 24 | `VOICE_DAVE_PREPARE_EPOCH` | server → client | A DAVE protocol version or MLS group change is upcoming. | ✅ `handleDavePrepareEpoch` |
| 25 | `VOICE_DAVE_MLS_EXTERNAL_SENDER` | server → client | Credential and public key for the MLS external sender. | ✅ `handleDaveMlsExternalSender` |
| 26 | `VOICE_DAVE_MLS_KEY_PACKAGE` | client → server | MLS Key Package for a pending group member. | ✅ `handleDaveMlsKeyPackage` (sends reply) |
| 27 | `VOICE_DAVE_MLS_PROPOSALS` | server → client | MLS Proposals to be appended or revoked. | ✅ `handleDaveMlsProposals` |
| 28 | `VOICE_DAVE_MLS_COMMIT_WELCOME` | both | MLS Commit with optional MLS Welcome messages. | ✅ `handleDaveMlsCommitWelcome` (sends reply) |
| 29 | `VOICE_DAVE_MLS_ANNOUNCE_COMMIT_TRANSITION` | server → client | MLS Commit to be processed for an upcoming transition. | ✅ `handleDaveMlsAnnounceCommitTransition` |
| 30 | `VOICE_DAVE_MLS_WELCOME` | server → client | MLS Welcome to group for an upcoming transition. | ✅ `handleDaveMlsWelcome` |
| 31 | `VOICE_DAVE_MLS_INVALID_COMMIT_WELCOME` | client → server | Flag an invalid commit or welcome and request re-add. | ✅ `handleDaveMlsInvalidCommitWelcome` (sends reply) |

> **Note:** libdave is mandatory as of March 1st, 2026. Both `Manager` and `WS` throw `LibDaveNotFoundException` immediately if `DaveRuntime::isAvailable()` returns false.

---

## Voice Close Codes (4011–4016)

Codes returned when a **voice** WebSocket closes. Critical codes should not trigger a reconnect attempt.

| Code | Constant | Meaning | Reconnect? |
|------|----------|---------|------------|
| 4011 | `CLOSE_VOICE_SERVER_NOT_FOUND` | Can't find the voice server. | ❌ No (critical) |
| 4012 | `CLOSE_VOICE_UNKNOWN_PROTO` | Unknown protocol. | ❌ No (critical) |
| 4014 | `CLOSE_VOICE_DISCONNECTED` | Disconnected from channel (e.g. kicked). | ✅ Yes |
| 4015 | `CLOSE_VOICE_SERVER_CRASH` | Voice server crashed. | ✅ Yes |
| 4016 | `CLOSE_VOICE_UNKNOWN_ENCRYPT` | Unknown encryption mode. | ❌ No (critical) |

Critical voice close codes are enumerated by `OpEnum::getCriticalVoiceCloseCodes()`.

---

## Gateway Opcodes (0–11, 31)

These opcodes are used on the **main** Discord gateway connection (not the voice WebSocket).

| Opcode | Constant | Description |
|--------|----------|-------------|
| 0 | `OP_DISPATCH` | Dispatches an event. |
| 1 | `OP_HEARTBEAT` | Used for ping checking. |
| 2 | `OP_IDENTIFY` | Used for client handshake. |
| 3 | `OP_PRESENCE_UPDATE` | Used to update the client presence. |
| 4 | `OP_VOICE_STATE_UPDATE` | Used to join/move/leave voice channels. |
| 5 | `OP_VOICE_SERVER_PING` | Used for voice ping checking. |
| 6 | `OP_RESUME` | Used to resume a closed connection. |
| 7 | `OP_RECONNECT` | Used to redirect clients to a new gateway. |
| 8 | `OP_GUILD_MEMBER_CHUNK` | Used to request member chunks. |
| 9 | `OP_INVALID_SESSION` | Used to notify clients when they have an invalid session. |
| 10 | `OP_HELLO` | Used to pass through the heartbeat interval. |
| 11 | `OP_HEARTBEAT_ACK` | Used to acknowledge heartbeats. |
| 31 | `OP_REQUEST_SOUNDBOARD_SOUNDS` | Request soundboard sounds. |

---

## Gateway Close Codes (4000–4014)

Codes returned when the **main** gateway WebSocket closes.

| Code | Constant | Meaning | Reconnect? |
|------|----------|---------|------------|
| 1000 | `CLOSE_NORMAL` | Normal close or heartbeat is invalid. | ✅ Yes |
| 1006 | `CLOSE_ABNORMAL` | Abnormal close. | ✅ Yes |
| 4000 | `CLOSE_UNKNOWN_ERROR` | Unknown error. | ✅ Yes |
| 4001 | `CLOSE_INVALID_OPCODE` | Unknown opcode was sent. | ✅ Yes |
| 4002 | `CLOSE_INVALID_MESSAGE` | Invalid message was sent. | ✅ Yes |
| 4003 | `CLOSE_NOT_AUTHENTICATED` | Not authenticated. | ✅ Yes |
| 4004 | `CLOSE_INVALID_TOKEN` | Invalid token on IDENTIFY. | ❌ No (critical) |
| 4005 | `CONST_ALREADY_AUTHD` | Already authenticated. | ✅ Yes |
| 4006 | `CLOSE_INVALID_SESSION` | Session is invalid. | ✅ Yes |
| 4007 | `CLOSE_INVALID_SEQ` | Invalid RESUME sequence. | ✅ Yes |
| 4008 | `CLOSE_TOO_MANY_MSG` | Too many messages sent. | ✅ Yes |
| 4009 | `CLOSE_SESSION_TIMEOUT` | Session timeout. | ✅ Yes |
| 4010 | `CLOSE_INVALID_SHARD` | Invalid shard. | ❌ No (critical) |
| 4011 | `CLOSE_SHARDING_REQUIRED` | Sharding required. | ❌ No (critical) |
| 4012 | `CLOSE_INVALID_VERSION` | Invalid API version. | ❌ No (critical) |
| 4013 | `CLOSE_INVALID_INTENTS` | Invalid intents. | ❌ No (critical) |
| 4014 | `CLOSE_DISALLOWED_INTENTS` | Disallowed intents. | ❌ No (critical) |

Critical gateway close codes are enumerated by `OpEnum::getCriticalCloseCodes()`.

---

## Usage Notes

### Helper methods on `OpEnum`

| Method | Description |
|--------|-------------|
| `OpEnum::isVoiceCode(int $code)` | Returns `true` if the integer is a known voice opcode. |
| `OpEnum::isGatewayCode(int $code)` | Returns `true` if the integer is a known gateway opcode. |
| `OpEnum::isValidCode(int $code)` | Returns `true` if the integer is any known opcode. |
| `OpEnum::isCriticalCloseCode(int $code)` | Returns `true` if the code is a critical gateway close code (no reconnect). |
| `OpEnum::isCriticalVoiceCloseCode(int $code)` | Returns `true` if the code is a critical voice close code (no reconnect). |
| `OpEnum::getVoiceCodes()` | Returns all voice opcodes as an array of enum cases. |
| `OpEnum::getGatewayCodes()` | Returns all gateway opcodes as an array of enum cases. |
| `OpEnum::voiceCodeToString(?self $code, bool $snakeCase, bool $pluckVoicePrefix)` | Converts a voice opcode enum value to a human-readable string. |

### Undocumented opcodes

Opcodes **15**, **18**, and **20** (`VOICE_CLIENT_UNKNOWN_15`, `VOICE_CLIENT_UNKNOWN_18`, `VOICE_CLIENT_PLATFORM`) are observed in production traffic but are not covered by Discord's official documentation. The library accepts them without error but does not dispatch specific handlers for them.

### DAVE binary frames

DAVE opcodes (21–31) are transmitted as binary WebSocket frames encoded by `Discord\Voice\Dave\BinaryFrame`:

```
[1 byte opcode] [2 bytes sequence] [N bytes payload]
```

The sequence number is stored in `Dave\State::$seq_ack` and reused in heartbeat and resume payloads to signal the last acknowledged gateway sequence to the server.
