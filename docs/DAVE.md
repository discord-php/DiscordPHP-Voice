# DAVE — Discord Audio/Video End-to-End Encryption

This document provides visual diagrams of how DiscordPHP-Voice implements the [DAVE E2EE protocol](https://discord.com/developers/docs/topics/voice-connections#end-to-end-encryption-dave-protocol). All diagrams use [Mermaid](https://mermaid.js.org/) syntax, which GitHub renders natively.

For the authoritative protocol specification, see the [DAVE Protocol Whitepaper](https://daveprotocol.com/) and [libdave](https://github.com/discord/libdave).

---

## Table of Contents

- [Architecture Overview](#architecture-overview)
- [Connection & DAVE Initialization](#connection--dave-initialization)
- [MLS Group Lifecycle](#mls-group-lifecycle)
  - [Initial Group Creation (Epoch 1)](#initial-group-creation-epoch-1)
  - [Member Join (Welcome)](#member-join-welcome)
  - [Member Change (Commit)](#member-change-commit)
  - [Downgrade to Protocol v0](#downgrade-to-protocol-v0)
  - [Error Recovery](#error-recovery)
- [Media Frame Encryption Pipeline](#media-frame-encryption-pipeline)
- [DAVE State Machine](#dave-state-machine)
- [Internal Flow](#internal-flow)
  - [Media encryption/decryption](#media-encryptiondecryption)
- [Voice Gateway DAVE Opcodes](#voice-gateway-dave-opcodes)

---

## Architecture Overview

How the DAVE-related classes relate to each other within the library.

```mermaid
graph TD
    Manager["Manager<br/><small>Entry point — validates libdave<br/>on construction</small>"]
    VoiceClient["VoiceClient<br/><small>Playback/recording state machine.<br/>Owns encryptDaveFrame() /<br/>decryptDaveFrame()</small>"]
    Client["Client<br/><small>Backwards-compat subclass<br/>of VoiceClient</small>"]
    WS["Client\WS<br/><small>Voice gateway connection.<br/>Handles all DAVE opcodes (21–31).<br/>Owns DaveState instance.</small>"]
    UDP["Client\UDP<br/><small>IP discovery, UDP heartbeats,<br/>RTP send/receive</small>"]
    Packet["Client\Packet<br/><small>RTP header + libsodium<br/>transport encryption.<br/>Injects DAVE frame callbacks.</small>"]
    DaveState["Dave\State<br/><small>Per-connection MLS state:<br/>protocol version, epoch,<br/>transitions, recognized users,<br/>encryptor/decryptors</small>"]
    DaveRuntime["Dave\Runtime<br/><small>FFI singleton wrapping libdave.<br/>Session, encryptor, decryptor,<br/>key ratchet, MLS operations.</small>"]
    SessionH["Dave\SessionHandle"]
    EncryptorH["Dave\EncryptorHandle"]
    DecryptorH["Dave\DecryptorHandle"]
    KeyRatchetH["Dave\KeyRatchetHandle"]
    NativeHandle["Dave\NativeHandle<br/><small>Abstract base — destroy()<br/>via Runtime</small>"]
    BinaryFrame["Dave\BinaryFrame<br/><small>Parse/serialise binary<br/>DAVE gateway frames</small>"]

    Manager -->|creates| VoiceClient
    Client -.->|extends| VoiceClient
    VoiceClient -->|owns| WS
    VoiceClient -->|owns| UDP
    UDP -->|uses| Packet
    WS -->|owns| DaveState
    WS -->|uses| DaveRuntime
    WS -->|uses| BinaryFrame
    VoiceClient -->|calls| DaveRuntime

    DaveState -->|holds| SessionH
    DaveState -->|holds| EncryptorH
    DaveState -->|holds per-user| DecryptorH

    DaveRuntime -->|creates| SessionH
    DaveRuntime -->|creates| EncryptorH
    DaveRuntime -->|creates| DecryptorH
    DaveRuntime -->|creates| KeyRatchetH

    SessionH -.->|extends| NativeHandle
    EncryptorH -.->|extends| NativeHandle
    DecryptorH -.->|extends| NativeHandle
    KeyRatchetH -.->|extends| NativeHandle

    Packet -->|outbound callback| VoiceClient
    Packet -->|inbound callback| VoiceClient

    style Manager fill:#4a9eff,color:#fff
    style WS fill:#ff9f43,color:#fff
    style DaveState fill:#ee5a24,color:#fff
    style DaveRuntime fill:#6c5ce7,color:#fff
    style VoiceClient fill:#00b894,color:#fff
```

---

## Connection & DAVE Initialization

The sequence from joining a voice channel to having DAVE fully initialized.

```mermaid
sequenceDiagram
    autonumber
    participant App as Application
    participant Mgr as Manager
    participant VC as VoiceClient
    participant WS as Client\WS
    participant GW as Voice Gateway
    participant RT as Dave\Runtime
    participant ST as Dave\State

    App->>Mgr: joinChannel(channel)
    Note over Mgr: Validates libdave is available<br/>(throws LibDaveNotFoundException if not)
    Mgr->>VC: create VoiceClient
    VC->>WS: new WS(vc, discord, data)
    Note over WS: Checks DaveRuntime::isAvailable()<br/>Initializes DaveState with identity<br/>Caps maxDaveProtocolVersion

    WS->>GW: Op 0 Identify<br/>{ max_dave_protocol_version: 1 }
    Note over WS,GW: First voice WebSocket connections send Identify.<br/>True reconnects send Op 7 Resume<br/>with seq_ack when available.
    GW-->>WS: Op 8 Hello<br/>{ heartbeat_interval }
    Note over WS: Starts heartbeat timer
    GW-->>WS: Op 2 Ready<br/>{ ssrc, ip, port, modes }
    Note over WS: Creates UDP client,<br/>starts IP discovery

    WS->>GW: Op 1 Select Protocol<br/>{ mode: "aead_aes256_gcm_rtpsize" }
    GW-->>WS: Op 4 Session Description<br/>{ secret_key, dave_protocol_version: 1 }

    WS->>WS: initializeDaveRuntimeState(protocolVersion)
    WS->>RT: createSession()
    RT-->>WS: SessionHandle
    WS->>ST: replaceSession(session)
    WS->>RT: createEncryptor()
    RT-->>WS: EncryptorHandle
    WS->>ST: replaceEncryptor(encryptor)
    WS->>RT: initializeSession(session, version, groupId, selfUserId)
    WS->>RT: getMarshalledKeyPackage(session)
    RT-->>WS: keyPackage bytes
    WS->>GW: Op 26 MLS Key Package<br/>(binary WebSocket frame)

    Note over WS: DAVE runtime is now initialized.<br/>Waiting for MLS group formation.

    WS->>VC: emit('ready')
```

---

## MLS Group Lifecycle

### Initial Group Creation (Epoch 1)

When a new MLS group is being formed (e.g. the first two members join a call).

```mermaid
sequenceDiagram
    autonumber
    participant WS as Client\WS
    participant GW as Voice Gateway
    participant RT as Dave\Runtime
    participant ST as Dave\State

    GW-->>WS: Op 24 Prepare Epoch<br/>{ epoch: 1, protocol_version, transition_id }
    WS->>ST: prepareEpoch(1)
    WS->>ST: prepareTransition(transitionId, protocolVersion)
    WS->>WS: initializeDaveRuntimeState(pv, resetState=true)
    Note over WS: Creates/resets session,<br/>creates encryptor,<br/>initializes MLS session

    WS->>WS: sendDaveKeyPackage()
    alt key package not already sent
        WS->>RT: getMarshalledKeyPackage(session)
        RT-->>WS: keyPackage bytes
        WS->>GW: Op 26 MLS Key Package (binary)<br/>[key package]
    else already sent by Session Description
        Note over WS,GW: Opcode 26 was already sent.
    end

    Note over GW,WS: Op 25 may arrive at any time —<br/>before, during, or after key package exchange.

    GW-->>WS: Op 25 MLS External Sender (binary)<br/>[external sender credential]
    WS->>ST: store externalSenderPackage
    WS->>RT: setExternalSender(session, package)
    Note over WS: If external sender arrived earlier,<br/>it was stored and applied during init.

    GW-->>WS: Op 27 MLS Proposals (binary)<br/>[add proposals for other members]
    WS->>RT: buildMlsCommitWelcomeWithSession(session, proposals, recognizedUsers)
    RT-->>WS: commit+welcome payload
    WS->>GW: Op 28 MLS Commit Welcome (binary)<br/>[commit + welcome]

    Note over GW: Gateway selects first<br/>valid commit as "winner"

    GW-->>WS: Op 29 MLS Announce Commit Transition (binary)<br/>[transitionId + commit]
    WS->>RT: processCommit(session, commit)
    RT-->>WS: { failed: false, ignored: false }
    WS->>WS: prepareDaveMediaTransition(transitionId, pv)
    Note over WS: Prepares decryptors for<br/>all recognized users;<br/>stores key ratchets and uses<br/>passthrough grace during setup
    WS->>GW: Op 23 Transition Ready<br/>{ transition_id }

    GW-->>WS: Op 22 Execute Transition<br/>{ transition_id }
    WS->>WS: applySelfDaveEncryptor(pv)
    WS->>ST: executeTransition(transitionId)
    Note over ST: passthroughMode = false<br/>DAVE E2EE is now active!
```

### Member Join (Welcome)

When a new member is being added to an existing MLS group.

```mermaid
sequenceDiagram
    autonumber
    participant WS as Client\WS (new member)
    participant GW as Voice Gateway
    participant RT as Dave\Runtime
    participant ST as Dave\State

    Note over WS: After connection + Session Description,<br/>DAVE runtime is initialized and<br/>the initial key package has been sent.

    GW-->>WS: Op 30 MLS Welcome (binary)<br/>[transitionId + welcome message]
    WS->>WS: splitTransitionPayload(payload)
    WS->>RT: processWelcome(session, welcome, recognizedUsers)
    RT-->>WS: true (joined group)
    WS->>WS: prepareDaveMediaTransition(transitionId, pv)
    Note over WS: Creates decryptors for<br/>all recognized users;<br/>stores key ratchets and uses<br/>passthrough grace during setup
    WS->>GW: Op 23 Transition Ready<br/>{ transition_id }

    GW-->>WS: Op 22 Execute Transition<br/>{ transition_id }
    WS->>WS: applySelfDaveEncryptor(pv)
    WS->>ST: executeTransition(transitionId)
    Note over ST: E2EE active for new member
```

### Member Change (Commit)

When a member joins or leaves, existing group members receive a commit to advance the MLS epoch.

```mermaid
sequenceDiagram
    autonumber
    participant WS as Client\WS (existing member)
    participant GW as Voice Gateway
    participant RT as Dave\Runtime
    participant ST as Dave\State

    GW-->>WS: Op 11 Client Connect<br/>{ user_ids: [...] }
    WS->>ST: addRecognizedUsers(userIds)

    Note over WS: When a member leaves:
    GW-->>WS: Op 13 Client Disconnect<br/>{ user_id }
    WS->>ST: removeRecognizedUser(userId)
    Note over ST: Clears decryptor for<br/>disconnected user

    GW-->>WS: Op 29 MLS Announce Commit (binary)<br/>[transitionId + commit]
    WS->>WS: splitTransitionPayload(payload)
    WS->>RT: processCommit(session, commit)
    RT-->>WS: { failed: false, ignored: false }
    WS->>WS: prepareDaveMediaTransition(transitionId, pv)
    Note over WS: Creates/updates decryptors<br/>for all recognized users<br/>with retained key ratchets and<br/>passthrough grace during setup
    WS->>GW: Op 23 Transition Ready<br/>{ transition_id }

    GW-->>WS: Op 22 Execute Transition<br/>{ transition_id }
    WS->>WS: applySelfDaveEncryptor(pv)
    WS->>ST: executeTransition(transitionId)
    Note over ST: New key ratchet in effect
```

For non-zero transition IDs, `Client\WS` sends Opcode 23 and waits for Opcode 22 before applying the local media transition. Transition ID `0` is the Discord gateway's immediate-transition shortcut: the client executes it locally without sending Opcode 23 or waiting for Opcode 22.

### Downgrade to Protocol v0

When E2EE must be disabled (e.g. a client without DAVE support joins during the transition phase).

```mermaid
sequenceDiagram
    autonumber
    participant WS as Client\WS
    participant GW as Voice Gateway
    participant ST as Dave\State
    participant RT as Dave\Runtime

    GW-->>WS: Op 21 Prepare Transition<br/>{ transition_id, protocol_version: 0 }
    WS->>WS: prepareDaveMediaTransition(transitionId, 0)
    Note over WS: Clears all decryptors<br/>(protocol version ≤ 0)
    WS->>GW: Op 23 Transition Ready<br/>{ transition_id }

    GW-->>WS: Op 22 Execute Transition<br/>{ transition_id }
    WS->>RT: resetSession(session)
    WS->>ST: resetProtocolState()
    WS->>ST: setProtocolVersion(0)
    Note over ST: passthroughMode = true<br/>E2EE disabled, transport-only
```

### Error Recovery

When a commit or welcome message can't be processed.

```mermaid
sequenceDiagram
    autonumber
    participant WS as Client\WS
    participant GW as Voice Gateway
    participant RT as Dave\Runtime
    participant ST as Dave\State

    GW-->>WS: Op 29 or Op 30 (commit/welcome)
    WS->>RT: processCommit() or processWelcome()
    RT-->>WS: failed / null

    WS->>GW: Op 31 MLS Invalid Commit Welcome (binary)
    Note over WS: Signals to gateway:<br/>"Please re-add me to the group"

    WS->>WS: initializeDaveRuntimeState(pv, resetState=true)
    Note over WS: Resets local MLS session,<br/>creates fresh session
    WS->>RT: getMarshalledKeyPackage(session)
    WS->>GW: Op 26 MLS Key Package (binary)<br/>[fresh key package]

    Note over GW: Gateway proposes removal<br/>and re-addition of this client
```

---

## Media Frame Encryption Pipeline

How audio frames are encrypted (outbound) and decrypted (inbound) through the two encryption layers: DAVE E2EE (frame-level) and transport encryption (packet-level).

### Outbound (Sending Audio)

```mermaid
flowchart LR
    A["🎤 Raw PCM"] --> B["Ffmpeg::encode()<br/><small>PCM → Opus</small>"]
    B --> C["OggStream<br/><small>Ogg container parsing</small>"]
    C --> D["playOggStream()<br/><small>Buffers frames,<br/>manages timing</small>"]
    D --> E{"DAVE<br/>Active?"}
    E -->|Yes| F["VoiceClient::<br/>encryptDaveFrame()"]
    E -->|No / Passthrough| G["Raw Opus frame"]
    F --> H["Runtime::<br/>encryptWithEncryptor()<br/><small>AES-128-GCM E2EE</small>"]
    H --> H2{"Encryption<br/>OK?"}
    H2 -->|Yes| I["Packet::encrypt()<br/><small>AES-256-GCM transport<br/>+ RTP header</small>"]
    H2 -->|No + Active| X["❌ Drop frame<br/><small>Preserves E2EE integrity</small>"]
    H2 -->|No + Passthrough| G
    G --> I
    I --> J["UDP::sendBuffer()<br/><small>→ Discord SFU</small>"]

    style E fill:#ff9f43,color:#fff
    style F fill:#6c5ce7,color:#fff
    style H fill:#6c5ce7,color:#fff
    style I fill:#00b894,color:#fff
```

### Inbound (Receiving Audio)

```mermaid
flowchart RL
    A["Discord SFU<br/>→ UDP"] --> B["Packet::decrypt()<br/><small>AES-256-GCM transport<br/>strip RTP header</small>"]
    B --> B2["Strip RTP extension payload<br/><small>after transport decrypt,<br/>before DAVE frame decrypt</small>"]
    B2 --> C{"DAVE<br/>Active?"}
    C -->|Yes| D["VoiceClient::<br/>decryptDaveFrame()"]
    C -->|No / Passthrough| E["Raw Opus frame"]
    D --> F["Resolve SSRC<br/>→ userId<br/>→ DecryptorHandle"]
    F --> G["Runtime::<br/>decryptWithDecryptor()<br/><small>AES-128-GCM E2EE</small>"]
    G --> H{"Decryption<br/>OK?"}
    H -->|Yes| I["Opus frame"]
    H -->|No| J["❌ Drop frame"]
    E --> I
    I --> K["Opus decoder<br/><small>→ PCM</small>"]
    K --> L["🔊 ReceiveStream"]

    style C fill:#ff9f43,color:#fff
    style D fill:#6c5ce7,color:#fff
    style G fill:#6c5ce7,color:#fff
    style B fill:#00b894,color:#fff
    style B2 fill:#00b894,color:#fff
```

Inbound RTP header extensions are removed only after transport decryption succeeds and before `decryptDaveFrame()` runs. This keeps Discord's RTP extension bytes out of the DAVE media frame while preserving normal RTP authentication.

### Two-Layer Encryption Stack

```
┌─────────────────────────────────────────────────────────────────────┐
│                         UDP Packet (wire)                          │
│  ┌───────────────────────────────────────────────────────────────┐  │
│  │              Transport Encryption (Packet)                    │  │
│  │         AES-256-GCM  •  Key from Session Description         │  │
│  │  ┌─────────────┬─────────────────────────────────────────┐   │  │
│  │  │  RTP Header  │          Encrypted Payload              │   │  │
│  │  │  (12 bytes)  │  ┌───────────────────────────────────┐  │   │  │
│  │  │              │  │      DAVE E2EE (Runtime)          │  │   │  │
│  │  │              │  │  AES-128-GCM  •  Per-sender key   │  │   │  │
│  │  │              │  │  ┌───────────────────────────┐    │  │   │  │
│  │  │              │  │  │    Opus Audio Frame       │    │  │   │  │
│  │  │              │  │  └───────────────────────────┘    │  │   │  │
│  │  │              │  │  + Auth Tag (8B) + Nonce + Magic  │  │   │  │
│  │  │              │  └───────────────────────────────────┘  │   │  │
│  │  └─────────────┴─────────────────────────────────────────┘   │  │
│  └───────────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────┘
```

---

## DAVE State Machine

The lifecycle of `Dave\State` through protocol transitions.

```mermaid
stateDiagram-v2
    [*] --> Unavailable: libdave not loaded

    [*] --> Passthrough: WS constructed<br/>protocolVersion = 0

    Passthrough --> Initializing: Session Description<br/>dave_protocol_version > 0

    Initializing --> AwaitingGroup: initializeDaveRuntimeState()<br/>Session + Encryptor created

    AwaitingGroup --> AwaitingGroup: Session Description / Prepare Epoch<br/>Send Key Package (Op 26)

    AwaitingGroup --> TransitionPending: Commit/Welcome received<br/>prepareTransition()

    TransitionPending --> Active: executeTransition()<br/>or transition_id 0 local completion<br/>passthroughMode = false

    Active --> TransitionPending: New Commit/Welcome<br/>(member join/leave)<br/>prepareTransition()

    Active --> Downgrading: Prepare Transition<br/>protocol_version = 0

    Downgrading --> Passthrough: Execute Transition<br/>resetProtocolState()

    Active --> ErrorRecovery: Invalid commit/welcome

    ErrorRecovery --> AwaitingGroup: Reset session<br/>Send new Key Package

    TransitionPending --> Active: Execute Transition<br/>applySelfDaveEncryptor()

    state Active {
        direction LR
        Encrypting: encryptDaveFrame()<br/>per outbound frame
        Decrypting: decryptDaveFrame()<br/>per inbound frame
        note right of Encrypting: Drops frame on failure<br/>to preserve E2EE integrity
    }
```

---

## Internal Flow

This section describes each DAVE opcode handler in `Client\WS` at the code level — where state is mutated, what is sent over the wire, and how the transition-readiness check gates E2EE activation.

### 1. Session description received (Op 4)

`handleSessionDescription()` extracts `dave_protocol_version` and calls `initializeDaveRuntimeState()`, which:

1. Creates (or resets) a `SessionHandle` via `DaveRuntime::createSession()` and stores it in `State::$session`.
2. Creates a fresh `EncryptorHandle` and stores it in `State::$encryptor`.
3. Calls `DaveRuntime::initializeSession()` with `selfUserId` and the negotiated `groupId`.
4. Applies the stored `externalSenderPackage` to the session if one has already arrived via Op 25.
5. Sends Op 26 (MLS Key Package) as a binary WebSocket frame via `sendDaveKeyPackage()`.

`passthroughMode` stays `true` until the first successful `executeTransition()`.

### 2. Prepare epoch (Op 24)

`handleDavePrepareEpoch()` reads `epoch`, optional `transition_id`, and `protocol_version` from the JSON payload:

1. Calls `State::prepareEpoch($epoch)` to record the current epoch number.
2. If a `transition_id` is present, calls `State::prepareTransition()` to record it; otherwise updates `latestPreparedTransitionVersion`.
3. If `protocol_version > 0`, calls `initializeDaveRuntimeState()` (with `resetState=true` when `epoch === 1`).
4. If epoch is 1 (a new MLS group is being formed), sends Op 26 via `sendDaveKeyPackage()`.

A session initialization failure here is fatal: the socket is closed immediately (fail-closed security policy).

### 3. External sender (Op 25)

`handleDaveMlsExternalSender()` receives a binary frame containing the gateway's MLS external sender credential:

1. Calls `State::recordExternalSender()` to persist the credential for replay inside `initializeDaveRuntimeState()`.
2. If a `SessionHandle` is already present, calls `DaveRuntime::setExternalSender()` immediately.
3. Calls `sendDaveKeyPackage()` — if the key package was not yet sent, this emits Op 26.

Op 25 may arrive before, during, or after the Op 4 session setup; storing the credential and replaying it inside init makes the ordering safe.

### 4. Proposals (Op 27)

`handleDaveMlsProposals()` receives a binary frame with MLS Add/Remove proposals from the external sender:

1. Calls `DaveRuntime::buildMlsCommitWelcomeWithSession()` to let libdave build a commit (and optional welcome messages) from the proposals.
2. On success, sends Op 28 (MLS Commit Welcome) as a binary WebSocket frame.
3. On failure, increments `State::$proposalFailureCount`. **Three consecutive failures trigger a socket close** so the client reconnects and obtains a fresh DAVE epoch.

### 5. Commit/welcome (Op 28)

`handleDaveMlsCommitWelcome()` receives the winning commit/welcome from the gateway:

1. Calls `DaveRuntime::processCommit()` on the stored session. On success (and not ignored), calls `prepareDaveMediaTransition()` then `completeDaveMediaTransition()`.
2. If `processCommit` fails, falls through to `DaveRuntime::processWelcome()` — the path taken by the client that is joining an existing group.
3. On double failure, calls `handleInvalidDaveTransition()` to begin error recovery (Op 31 + session reset + fresh Op 26).

### 6. Announce commit transition (Op 29)

`handleDaveMlsAnnounceCommitTransition()` is the server-broadcast path for existing group members:

1. `TransitionPayload::parse()` extracts the 2-byte big-endian `transition_id` from bytes 0–1 of the payload; the remainder is the raw MLS commit.
2. Calls `DaveRuntime::processCommit()`.
3. Calls `prepareDaveMediaTransition()` then `completeDaveMediaTransition()`, which branches on `transition_id`:
   - **Zero `transition_id`** — executes the media transition immediately and locally without a gateway round-trip.
   - **Non-zero `transition_id`** — sends Op 23 (Transition Ready) and waits for the gateway to confirm with Op 22 (Execute Transition).

### 7. Execute transition (Op 22)

`handleDaveExecuteTransition()` → `executeDaveMediaTransition($transitionId)`:

1. Verifies the transition ID matches `State::$pendingTransitionId`; mismatches are silently ignored.
2. **Protocol version 0 (downgrade):** calls `DaveRuntime::resetSession()`, then `State::resetProtocolState()` + `State::setProtocolVersion(0)` — restoring full passthrough.
3. **Protocol version > 0:** calls `applySelfDaveEncryptor()` to bind the new MLS epoch's key ratchet to `State::$encryptor`, then `State::executeTransition()` which sets `passthroughMode = false`.

`passthroughMode = false` is the gate that activates DAVE E2EE for both outbound and inbound frames.

### 8. Remote user decryptor setup

`prepareRemoteDaveDecryptor($userId, $protocolVersion)` is called inside `prepareDaveMediaTransition()` for each recognized remote user:

1. Obtains or creates a `DecryptorHandle` for the user.
2. Calls `DaveRuntime::getKeyRatchet()` to fetch the per-sender ratchet for the new epoch from libdave.
3. **`configureDecryptorPassthrough(true)`** — enables a passthrough grace period so in-flight frames encrypted with the old epoch's key are not dropped during the transition.
4. **`configureDecryptorKeyRatchet()`** — installs the new epoch's key ratchet into the decryptor.
5. **`configureDecryptorPassthrough(false)`** — closes the grace window; the decryptor now enforces E2EE decryption only.

The three-step passthrough sequence prevents audio glitches at epoch boundaries.

---

## Media encryption/decryption

### Outbound frame path

```
Packet::encrypt()
  │
  ├─ outboundFrameEncryptor callback (injected by VoiceClient)
  │    └─ if passthroughMode = false:
  │         VoiceClient::encryptDaveFrame()
  │           └─ DaveRuntime::encryptWithEncryptor()   [libdave AES-128-GCM]
  │
  └─ sodium_crypto_aead_aes256gcm_encrypt()            [transport AES-256-GCM]
```

If DAVE encryption fails while `passthroughMode = false`, the frame is **dropped** — never sent to Discord. This preserves E2EE integrity and prevents silent fallback to plaintext Opus.

### Inbound frame path

```
UDP receives raw packet
  │
  Packet::decrypt()
    1. sodium_crypto_aead_aes256gcm_decrypt()          [transport AES-256-GCM]
    2. RtpHeader::stripExtensionPayload()              [remove RTP header extensions]
    3. inboundFrameDecryptor callback (injected by VoiceClient)
         └─ if DAVE active (passthroughMode = false):
              VoiceClient::decryptDaveFrame()
                ├─ resolve SSRC → userId → DecryptorHandle
                └─ DaveRuntime::decryptWithDecryptor()  [libdave AES-128-GCM]
```

RTP header extensions are stripped **after** transport decryption and **before** DAVE frame decryption. This keeps Discord's RTP extension bytes outside the DAVE-encrypted media frame while preserving normal RTP authentication.

---

## Voice Gateway DAVE Opcodes

All DAVE-related opcodes handled by `Client\WS`.

| Opcode | Name | Direction | Format | Handler Method | Description |
|--------|------|-----------|--------|----------------|-------------|
| 21 | `DAVE_PREPARE_TRANSITION` | Server → Client | JSON | `handleDavePrepareTransition` | Announces an upcoming downgrade from the DAVE protocol. Contains `transition_id` and `protocol_version`. |
| 22 | `DAVE_EXECUTE_TRANSITION` | Server → Client | JSON | `handleDaveExecuteTransition` | Confirms execution of a non-zero pending transition. Transition ID `0` executes locally without waiting for this opcode. |
| 23 | `DAVE_TRANSITION_READY` | Client → Server | JSON | `handleDaveTransitionReady` | Client signals it has prepared local state and is ready to execute the transition. |
| 24 | `DAVE_PREPARE_EPOCH` | Server → Client | JSON | `handleDavePrepareEpoch` | Announces a protocol version change or new MLS epoch. `epoch: 1` means a new group is being created. |
| 25 | `DAVE_MLS_EXTERNAL_SENDER` | Server → Client | **Binary** | `handleDaveMlsExternalSender` | Provides the voice gateway's external sender credential for the MLS group. |
| 26 | `DAVE_MLS_KEY_PACKAGE` | Client → Server | **Binary** | `handleDaveMlsKeyPackage` | Client sends its MLS key package as a binary WebSocket frame so it can be proposed for group addition. |
| 27 | `DAVE_MLS_PROPOSALS` | Server → Client | **Binary** | `handleDaveMlsProposals` | Contains MLS Add/Remove proposals from the external sender. Client must process and produce a commit. |
| 28 | `DAVE_MLS_COMMIT_WELCOME` | Both | **Binary** | `handleDaveMlsCommitWelcome` | Client sends its MLS commit (and optional welcome messages) to the gateway. The gateway may also dispatch it back; the "winning" commit is broadcast via Op 29/30. |
| 29 | `DAVE_MLS_ANNOUNCE_COMMIT_TRANSITION` | Server → Client | **Binary** | `handleDaveMlsAnnounceCommitTransition` | Gateway broadcasts the winning commit to existing group members with a transition ID. Transition ID `0` is executed immediately after local media state is prepared. |
| 30 | `DAVE_MLS_WELCOME` | Server → Client | **Binary** | `handleDaveMlsWelcome` | Gateway sends an MLS Welcome to new members being added to the group. |
| 31 | `DAVE_MLS_INVALID_COMMIT_WELCOME` | Client → Server | **Binary** | `handleDaveMlsInvalidCommitWelcome` | Client signals that a commit/welcome was unprocessable. Triggers error recovery (removal + re-addition). A defensive inbound handler also exists. |

### Binary Frame Format

Binary DAVE messages use a compact format instead of JSON:

```
Server → Client:
┌──────────────────┬────────┬──────────────────────┐
│  Sequence Number │ Opcode │      Payload         │
│   (2 bytes, BE)  │(1 byte)│   (variable bytes)   │
└──────────────────┴────────┴──────────────────────┘

Client → Server:
┌────────┬──────────────────────┐
│ Opcode │      Payload         │
│(1 byte)│   (variable bytes)   │
└────────┴──────────────────────┘
```

The sequence number is tracked in `Dave\State::$lastReceivedSequence` and included in heartbeat (`seq_ack`) and resume payloads for [buffered resume](https://discord.com/developers/docs/topics/voice-connections#resuming-voice-connection) support.

---

## Key Source Files

| File | Role |
|------|------|
| `src/Discord/Voice/Manager.php` | Entry point — validates libdave availability |
| `src/Discord/Voice/VoiceClient.php` | `encryptDaveFrame()` / `decryptDaveFrame()` |
| `src/Discord/Voice/Client/WS.php` | All DAVE gateway opcode handlers |
| `src/Discord/Voice/Client/Packet.php` | RTP transport encryption with DAVE callbacks |
| `src/Discord/Voice/Dave/State.php` | Per-connection MLS state tracking |
| `src/Discord/Voice/Dave/Runtime.php` | FFI singleton wrapping native libdave |
| `src/Discord/Voice/Dave/BinaryFrame.php` | Binary DAVE frame parsing/serialisation |
| `src/Discord/Voice/Dave/SessionHandle.php` | Opaque MLS session handle |
| `src/Discord/Voice/Dave/EncryptorHandle.php` | Opaque media encryptor handle |
| `src/Discord/Voice/Dave/DecryptorHandle.php` | Opaque media decryptor handle |
| `src/Discord/Voice/Dave/KeyRatchetHandle.php` | Opaque key ratchet handle |
| `src/Discord/Voice/Dave/NativeHandle.php` | Abstract base for FFI handle lifecycle |
