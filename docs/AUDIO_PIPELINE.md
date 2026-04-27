# Audio Pipeline

This document provides visual diagrams of how DiscordPHP-Voice processes audio in both directions — outbound (your application sending audio to Discord) and inbound (receiving audio from other users). All diagrams use [Mermaid](https://mermaid.js.org/) syntax, which GitHub renders natively.

---

## Table of Contents

- [Outbound Audio Pipeline](#outbound-audio-pipeline)
- [Inbound Audio Pipeline](#inbound-audio-pipeline)
- [Playback State Machine](#playback-state-machine)
- [Audio Format Chain](#audio-format-chain)

---

## Outbound Audio Pipeline

The full path from a `playFile()` / `playRawStream()` / `playOggStream()` call to encrypted RTP packets leaving the UDP socket.

```mermaid
graph TD
    PF["VoiceClient::playFile(file/URL)<br/><small>Accepts local path or HTTP/HTTPS URL.<br/>Forks Ffmpeg::encode() as child process.</small>"]
    PRS["VoiceClient::playRawStream(stream)<br/><small>Feeds raw PCM into ffmpeg<br/>via stdin as -f s16le.</small>"]
    POS["VoiceClient::playOggStream(stream)<br/><small>Core send loop entry point.<br/>Sets speaking=MICROPHONE,<br/>delays first packet by 500 ms.</small>"]
    FFMPEG["Processes\Ffmpeg::encode()<br/><small>Child process: transcodes any input<br/>to Opus inside an Ogg container.<br/>Writes Ogg pages to stdout.</small>"]
    OGG["OggStream<br/><small>Reads and buffers Ogg pages,<br/>yields decoded Opus frames.</small>"]
    ROO["VoiceClient::readOggOpus()<br/><small>Schedules one Opus packet<br/>per 20 ms frame on ReactPHP loop.<br/>Handles sequence/timestamp rollover.<br/>Sends 5 silence frames on EOF/stop.</small>"]
    DELAY["500 ms initial delay<br/><small>Gives Discord time to prepare<br/>before the first audio packet.</small>"]
    TIMER["ReactPHP loop timer<br/><small>addTimer(0.02 s) per frame<br/>= 20 ms / frame = 50 fps</small>"]
    ROLLOVER["Sequence/Timestamp rollover<br/><small>seq: 16-bit → wraps 65535→0<br/>ts:  32-bit → wraps at 2³²−1</small>"]
    SILENCE["5 silence frames<br/><small>Sent on stop() or EOF<br/>to flush Discord jitter buffer.</small>"]
    ENC["Client\Packet::encrypt(opus)<br/><small>Builds RTP header (seq, timestamp, SSRC).<br/>Encrypts payload with libsodium<br/>AES-256-GCM (XSalsa20-Poly1305).<br/>Calls optional DAVE frame callback.</small>"]
    DAVE["VoiceClient::encryptDaveFrame()<br/><small>Optional DAVE E2EE media layer<br/>on top of RTP. Only active when<br/>DAVE session is established.</small>"]
    UDP["Client\UDP::sendBuffer(packet)<br/><small>Writes encrypted RTP bytes<br/>to the UDP socket.</small>"]
    DISCORD["Discord UDP Voice Server"]

    PF -->|"forks ffmpeg"| FFMPEG
    PRS -->|"forks ffmpeg (stdin=PCM)"| FFMPEG
    FFMPEG -->|"Ogg/Opus stdout"| OGG
    OGG -->|"Opus frames"| POS
    PF -.->|"or direct Ogg stream"| POS
    POS -->|"500 ms delay"| DELAY
    DELAY -->|"first frame"| ROO
    ROO -->|"addTimer(0.02)"| TIMER
    TIMER -->|"next frame"| ROO
    ROO -->|"sequence rollover"| ROLLOVER
    ROO -->|"EOF/stop"| SILENCE
    ROO -->|"Opus payload"| ENC
    ENC -->|"DAVE active?"| DAVE
    DAVE -->|"encrypted frame"| UDP
    ENC -->|"DAVE inactive"| UDP
    UDP -->|"UDP packet"| DISCORD

    style PF fill:#4a9eff,color:#fff
    style PRS fill:#4a9eff,color:#fff
    style POS fill:#00b894,color:#fff
    style FFMPEG fill:#fdcb6e,color:#333
    style ENC fill:#e17055,color:#fff
    style DAVE fill:#6c5ce7,color:#fff
    style UDP fill:#ff9f43,color:#fff
    style DISCORD fill:#5865f2,color:#fff
    style DELAY fill:#dfe6e9,color:#333
    style TIMER fill:#dfe6e9,color:#333
    style ROLLOVER fill:#dfe6e9,color:#333
    style SILENCE fill:#dfe6e9,color:#333
```

### Key timing details

| Detail | Value |
|---|---|
| Initial send delay | 500 ms |
| Frame duration | 20 ms (50 fps) |
| Opus frame size | 960 samples @ 48 kHz |
| Sequence counter width | 16-bit (wraps 65535 → 0) |
| Timestamp counter width | 32-bit (wraps 2³²−1 → 0) |
| Silence frames on stop/EOF | 5 frames |

---

## Inbound Audio Pipeline

The path from raw bytes arriving on the UDP socket to `channel-pcm` / `channel-opus` events emitted by `VoiceClient`.

```mermaid
graph TD
    RUDP["Discord UDP Voice Server<br/><small>Sends encrypted RTP packets<br/>to the client UDP socket.</small>"]
    RECV["Client\UDP socket<br/><small>ReactPHP datagram listener.<br/>Emits raw bytes on each UDP datagram.</small>"]
    DEC["Client\Packet::decrypt(data)<br/><small>Strips RTP header, reads SSRC.<br/>Decrypts AES-256-GCM payload.<br/>Strips RTP extension payload before<br/>optional DAVE decrypt callback.</small>"]
    DAVD["VoiceClient::decryptDaveFrame()<br/><small>Optional DAVE E2EE media layer.<br/>Only active when DAVE session<br/>is established for this user.</small>"]
    HAD["VoiceClient::handleAudioData(packet)<br/><small>Routes packet to the correct<br/>per-user decoder.</small>"]
    SSRC["SSRC → userId map<br/><small>speakingStatus + ssrcToUserId<br/>populated by WS speaking events.<br/>Packet dropped if SSRC unknown.</small>"]
    LIMIT["MAX_DECODERS = 25<br/><small>If 25 active decoders already exist,<br/>new users are silently dropped<br/>to prevent resource exhaustion.</small>"]
    USER["Client\User (per user)<br/><small>Value object: SSRC, userId,<br/>Opus decoder Process,<br/>ReceiveStream, Speaking part.</small>"]
    OPDEC["Opus decoder Process<br/><small>Decodes Opus frames to<br/>48 kHz / stereo / 16-bit PCM.</small>"]
    RS["ReceiveStream (per user)<br/><small>Emits 'pcm' and 'opus' events<br/>with the decoded/raw frame data.</small>"]
    EVCPCM["VoiceClient event: channel-pcm<br/><small>Payload: (User $user, string $pcm)<br/>Emitted once per inbound PCM frame.</small>"]
    EVCOPUS["VoiceClient event: channel-opus<br/><small>Payload: (User $user, string $opus)<br/>Emitted once per inbound Opus frame.</small>"]

    RUDP -->|"UDP datagram"| RECV
    RECV -->|"raw bytes"| DEC
    DEC -->|"DAVE active?"| DAVD
    DAVD -->|"decrypted Opus"| HAD
    DEC -->|"DAVE inactive"| HAD
    HAD -->|"look up SSRC"| SSRC
    SSRC -->|"userId found"| LIMIT
    SSRC -->|"unknown SSRC"| DROP1["drop packet"]
    LIMIT -->|"under limit: get or create"| USER
    LIMIT -->|"at limit: new user"| DROP2["drop packet"]
    USER -->|"Opus bytes"| OPDEC
    OPDEC -->|"PCM bytes"| RS
    RS -->|"pcm event"| EVCPCM
    RS -->|"opus event"| EVCOPUS

    style RUDP fill:#5865f2,color:#fff
    style RECV fill:#ff9f43,color:#fff
    style DEC fill:#e17055,color:#fff
    style DAVD fill:#6c5ce7,color:#fff
    style HAD fill:#00b894,color:#fff
    style USER fill:#4a9eff,color:#fff
    style EVCPCM fill:#00cec9,color:#fff
    style EVCOPUS fill:#00cec9,color:#fff
    style LIMIT fill:#fdcb6e,color:#333
    style DROP1 fill:#d63031,color:#fff
    style DROP2 fill:#d63031,color:#fff
```

---

## Playback State Machine

States and transitions for the outbound audio playback lifecycle.

```mermaid
stateDiagram-v2
    [*] --> Idle : VoiceClient ready

    Idle --> Playing : playFile() / playRawStream() / playOggStream()

    Playing --> Paused : pause()
    Paused --> Playing : resume()

    Playing --> Idle : stop()
    Paused --> Idle : stop()

    Playing --> Idle : EOF / end event

    Playing --> Playing : setVolume() blocked\nsetAudioApplication() blocked

    note right of Playing
        speaking = MICROPHONE (1)
        Frames sent every 20 ms
        ReactPHP loop timer active
    end note

    note right of Paused
        speaking unchanged
        Silence frames refreshed
        to maintain UDP cadence
    end note

    note right of Idle
        speaking = NOT_SPEAKING (0)
        No loop timer active
        5 silence frames flushed
    end note
```

### Playback control methods

| Method | Allowed states | Effect |
|---|---|---|
| `playFile(file)` | Idle | Start playback from file/URL |
| `playRawStream(stream)` | Idle | Start playback from PCM stream |
| `playOggStream(stream)` | Idle | Start playback from Ogg/Opus stream |
| `pause()` | Playing | Suspend; send silence frames to keep cadence |
| `resume()` | Paused | Continue from where paused |
| `stop()` | Playing, Paused | Drain buffer, send 5 silence frames, → Idle |
| `setVolume(vol)` | Idle only | Adjusts ffmpeg volume filter |
| `setAudioApplication(app)` | Idle only | Changes Opus application mode |

### Speaking flags (bitmask)

| Constant | Value | Meaning |
|---|---|---|
| `NOT_SPEAKING` | `0` | Silent |
| `MICROPHONE` | `1` | Normal voice audio |
| `SOUNDSHARE` | `2` | Screen-share audio |
| `PRIORITY_SPEAKER` | `4` | Ducks other speakers |

---

## Audio Format Chain

Format conversions from application input to bytes on the wire.

```mermaid
graph LR
    IN["Input<br/><small>MP3 / WAV / FLAC / AAC<br/>HTTP/HTTPS URL / PCM stream</small>"]
    FFMPEG["FFmpeg<br/><small>Transcodes to Opus<br/>48 kHz stereo 128 kbps<br/>wrapped in Ogg container</small>"]
    OPUS["Ogg/Opus<br/><small>Variable-bitrate Opus frames<br/>20 ms / 960 samples each</small>"]
    RTP["RTP Packet<br/><small>12-byte header:<br/>V=2, PT=120, seq, ts, SSRC<br/>+ raw Opus payload</small>"]
    ERTP["Encrypted RTP<br/><small>libsodium AES-256-GCM<br/>(XSalsa20-Poly1305)<br/>nonce derived from RTP header</small>"]
    DAVE["DAVE E2EE Frame<br/><small>MLS-derived key ratchet<br/>wraps the encrypted RTP frame.<br/>Only present when DAVE active.</small>"]
    WIRE["Discord UDP Wire<br/><small>Raw UDP datagram<br/>sent to Discord voice server</small>"]

    IN -->|"ffmpeg child process"| FFMPEG
    FFMPEG -->|"stdout Ogg pages"| OPUS
    OPUS -->|"Packet::encrypt()"| RTP
    RTP -->|"libsodium seal"| ERTP
    ERTP -->|"encryptDaveFrame()\n[optional]"| DAVE
    DAVE -->|"UDP::sendBuffer()"| WIRE
    ERTP -->|"UDP::sendBuffer()\n[no DAVE]"| WIRE

    style IN fill:#4a9eff,color:#fff
    style FFMPEG fill:#fdcb6e,color:#333
    style OPUS fill:#00b894,color:#fff
    style RTP fill:#e17055,color:#fff
    style ERTP fill:#d63031,color:#fff
    style DAVE fill:#6c5ce7,color:#fff
    style WIRE fill:#5865f2,color:#fff
```

The inbound path reverses this chain: `WIRE → ERTP → RTP → strip RTP extension payload → [DAVE decrypt] → Opus → PCM`.
