# Troubleshooting

Common errors and how to fix them.

## Table of Contents

- [Catch Any Voice Exception](#catch-any-voice-exception)
- [LibDaveNotFoundException — libdave not found](#libdavenotfoundexception--libdave-not-found)
- [DAVE decrypt failures or silent inbound audio](#dave-decrypt-failures-or-silent-inbound-audio)
- [Buzzing or static audio when recording](#buzzing-or-static-audio-when-recording)
- [FFmpegNotFoundException — ffmpeg not found](#ffmpegnotfoundexception--ffmpeg-not-found)
- [OGG recording requires ffmpeg](#ogg-recording-requires-ffmpeg)
- [OpusNotFoundException — libopus not found](#opusnotfoundexception--libopus-not-found)
- [LibSodiumNotFoundException — libsodium not found](#libsodiumnotfoundexception--libsodium-not-found)
- [DCANotFoundException / OutdatedDCAException — DCA binary missing or outdated](#dcanotfoundexception--outdateddcaexception--dca-binary-missing-or-outdated)
- [CantSpeakInChannelException — bot missing Speak permission](#cantspeakinchannel-exception--bot-missing-speak-permission)
- [EnterChannelDeniedException — bot missing Connect permission](#enterchanneldeniedexception--bot-missing-connect-permission)
- [AudioAlreadyPlayingException — audio already playing](#audioalreadyplayingexception--audio-already-playing)
- [ClientNotReadyException — client not ready](#clientnotreadyexception--client-not-ready)
- [BufferTimedOutException — audio stream stalled](#buffertimeoutexception--audio-stream-stalled)

---

## Catch Any Voice Exception

All library exceptions implement the `VoiceException` marker interface. You can catch any voice-related error with a single handler instead of listing every exception type:

```php
use Discord\Voice\Exceptions\VoiceException;

try {
    $manager->joinChannel($channel)->then(function ($client) {
        // ...
    });
} catch (VoiceException $e) {
    echo 'Voice error: ' . $e->getMessage() . PHP_EOL;
}
```

Individual exception classes (`LibDaveNotFoundException`, `EnterChannelDeniedException`, etc.) remain available for fine-grained handling when needed.

---

## LibDaveNotFoundException — libdave not found

**What it means:** Discord required DAVE E2EE (end-to-end encryption) for all voice and video connections starting **March 1st, 2026**. This library now requires `libdave` to connect to any voice channel. The exception message includes the underlying FFI load error from `LibDaveNotFoundException::fromRuntimeError()`.

**How to fix it:**

The easiest way is to run the included setup script, which auto-detects your OS and architecture (Linux, macOS, Windows — x64 and ARM64), downloads the correct `libdave` release asset, and verifies its SHA-256 digest:

```bash
./scripts/setup-libdave.sh
```

This installs the library into `.cache/libdave/` relative to the package root. The runtime auto-discovers it there — no environment variable needed.

**Alternatively**, point the runtime at an existing `libdave` installation:

```bash
export DISCORDPHP_DAVE_LIBRARY=/path/to/libdave.so   # Linux
export DISCORDPHP_DAVE_LIBRARY=/path/to/libdave.dylib # macOS
```

**Prerequisites** (required before `libdave` can load):

- PHP [`ext-ffi`](https://www.php.net/manual/en/book.ffi.php) must be enabled:
  ```ini
  ; php.ini
  extension=ffi
  ffi.enable=true
  ```

- On **Linux (Debian/Ubuntu)**:
  ```bash
  sudo apt install php-ffi
  ```

- On **Linux (RHEL/Fedora)**:
  ```bash
  sudo dnf install php-ffi
  ```

- On **macOS**:
  ```bash
  brew install php
  # ext-ffi is bundled; ensure ffi.enable=true in php.ini
  ```

- On **Windows**: `php_ffi.dll` ships with PHP 8.x; uncomment `extension=ffi` and set `ffi.enable=true` in `php.ini`.

**Verify the setup:**

```bash
export DISCORDPHP_DAVE_LIBRARY="$PWD/.cache/libdave/lib/libdave.so"
./vendor/bin/pest tests/Unit/Dave/RuntimeTest.php
```

---

## DAVE decrypt failures or silent inbound audio

**What it means:** Voice connects, but inbound audio is silent or DAVE frame decryption fails after the transport layer succeeds.

**How to check it:**

- Use a current build that sends outbound DAVE MLS packets (Opcode 26/28/31) as binary WebSocket frames. Text-encoding those packets, or passing them through a proxy that rewrites binary frames, will break MLS setup.
- First voice WebSocket connections should Identify. Only true reconnects should Resume, and Resume/heartbeat payloads include `seq_ack` when a DAVE binary gateway sequence has been received.
- DAVE media decryption runs after RTP transport decryption. RTP extension payload bytes are stripped before the DAVE decryptor is called, so speech-sized DAVE frames should be passed to libdave without the transport extension prefix.
- During MLS transitions, remote decryptors are configured with a short passthrough grace while their key ratchets are installed. Transition ID `0` is executed locally and does not wait for Opcode 22.

When sharing logs, do **not** include raw MLS key packages, DAVE binary frame payloads, RTP payloads, session secret keys, or bot tokens. Opcode names, transition IDs, payload lengths, and sanitized error messages are enough for most reports.

---

## Buzzing or static audio when recording

**What it means:** Recorded audio plays back with buzzing, static, or a "warbling" effect — especially noticeable between words or whenever a speaker pauses and resumes.

**Root cause (historical — fixed in current version):** The Opus codec (Discord uses the SILK/CELT hybrid mode) is stateful. Each decoder maintains inter-frame pitch predictors and packet-loss concealment (PLC) context that must survive across 20 ms frames. Older versions of this library created a new native Opus decoder on every single frame and immediately destroyed it, discarding all accumulated codec state and producing audible buzzing at every frame boundary.

**Current behaviour:** Each SSRC (speaker) now has a dedicated, long-lived `OpusFfi` decoder instance. The decoder is created on the first packet from a speaker and reused for all subsequent frames. Codec state is fully isolated per speaker so multiple simultaneous speakers do not corrupt each other's audio.

**If you still hear buzzing after updating:**

1. Confirm you are on the latest version: `composer show discord-php-helpers/voice`
2. Verify `ext-ffi` is enabled and `libopus` is found:
   ```bash
   php -r "var_dump(extension_loaded('ffi'));"
   php -r "var_dump(FFI::load('path/to/libopus.so'));" 2>&1 | head -5
   ```
3. Check that `OpusFfi::isAvailable()` returns `true` in your environment — if it returns `false`, the FFI Opus decoder is not loaded and audio decoding falls back to raw Opus frames (no PCM output).
4. If you have a **custom** `OpusDecoderInterface` implementation that creates/destroys a native decoder per call, update it to persist the decoder handle across calls.

---

## FFmpegNotFoundException — ffmpeg not found

**What it means:** `ffmpeg` was not found on your system `PATH`. ffmpeg is required to encode audio for playback via `playFile()` and `playRawStream()`.

**How to fix it:**

- **Linux (Debian/Ubuntu)**:
  ```bash
  sudo apt install ffmpeg
  ```

- **Linux (RHEL/Fedora)**:
  ```bash
  sudo dnf install ffmpeg
  ```

- **macOS**:
  ```bash
  brew install ffmpeg
  ```

- **Windows**: Download a static build from [ffmpeg.org/download.html](https://ffmpeg.org/download.html), extract it, and add the `bin/` folder to your system `PATH`.

**Verify:**

```bash
ffmpeg -version
```

### Recording to OGG format requires ffmpeg

When calling `record(RecordingFormat::OGG, ...)`, the voice client spawns a `ffmpeg` process per speaker to encode PCM to OGG Opus. If `ffmpeg` is not on your system `PATH`, the process will silently fail to start and no OGG file will be produced.

Install ffmpeg (see [FFmpegNotFoundException](#ffmpegnotfoundexception--ffmpeg-not-found) above) and verify with `ffmpeg -version` before using `RecordingFormat::OGG`.

`RecordingFormat::WAV` does not require ffmpeg — it uses a pure-PHP writer.

---

## OpusNotFoundException — libopus not found

**What it means:** The Opus shared library (`libopus`) was not found. It is required for Opus codec support used in audio send and receive.

**How to fix it:**

- **Linux (Debian/Ubuntu)**:
  ```bash
  sudo apt install libopus-dev
  ```

- **Linux (RHEL/Fedora)**:
  ```bash
  sudo dnf install opus-devel
  ```

- **macOS**:
  ```bash
  brew install opus
  ```

- **Windows**: Download pre-built Opus binaries from [opus-codec.org](https://opus-codec.org/downloads/), or install via `vcpkg`:
  ```
  vcpkg install opus
  ```

---

## LibSodiumNotFoundException — libsodium not found

**What it means:** PHP's `ext-sodium` extension is not enabled. libsodium is used to encrypt and decrypt RTP voice packets.

**How to fix it:**

`ext-sodium` has been bundled with PHP since **7.2** — you just need to enable it.

1. Find your active `php.ini`:
   ```bash
   php --ini
   ```

2. Uncomment or add the following line:
   ```ini
   extension=sodium
   ```

3. On **Linux (Debian/Ubuntu)**, the package may need installing first:
   ```bash
   sudo apt install php-sodium
   ```

4. On **Linux (RHEL/Fedora)**:
   ```bash
   sudo dnf install php-sodium
   ```

5. Restart your PHP process after editing `php.ini`.

**Verify:**

```bash
php -m | grep sodium
```

---

## DCANotFoundException / OutdatedDCAException — DCA binary missing or outdated

**What it means:** The `dca` binary was not found, or the installed version is too old. DCA is a **legacy** audio format.

**Recommended fix — migrate away from DCA:**

DCA playback (`playDCAStream()`) is a legacy path. The modern equivalents are:

```php
// Play any file or URL (uses ffmpeg + Ogg/Opus internally):
$vc->playFile('/path/to/audio.mp3');

// Stream raw PCM:
$vc->playRawStream($pcmStream);

// Stream a pre-encoded Ogg/Opus stream:
$vc->playOggStream($oggStream);
```

**If you must keep using DCA:**

Download the `dca` binary from [github.com/bwmarrin/dca](https://github.com/bwmarrin/dca/releases) and place it somewhere on your system `PATH`.

```bash
# Example for Linux x64:
chmod +x dca-linux-amd64
sudo mv dca-linux-amd64 /usr/local/bin/dca
```

---

## CantSpeakInChannelException — bot missing Speak permission

**What it means:** The bot does not have the **Speak** permission in the target voice channel.

**How to fix it:**

1. Open your Discord server settings → **Roles** or the channel's **Edit Channel** → **Permissions**.
2. Grant the bot role (or the bot user directly) the **Speak** permission on the voice channel.

The bot requires both **Connect** and **Speak** to participate in voice.

---

## EnterChannelDeniedException — bot missing Connect permission

**What it means:** The bot does not have the **Connect** permission for the target voice channel.

**How to fix it:**

1. Open **Edit Channel** → **Permissions** for the voice channel.
2. Grant the bot role (or user) the **Connect** permission.

If the channel has a user limit set and is full, the bot also needs the **Move Members** permission to bypass it.

---

## AudioAlreadyPlayingException — audio already playing

**What it means:** You called `playFile()` (or another playback method) while audio was already playing. Only one audio source can play at a time.

**How to fix it:**

Stop the current audio before starting new playback:

```php
$vc->on('ready', function () use ($vc) {
    $vc->playFile('/path/to/first.mp3');

    // To play a second file, stop the first:
    $vc->stop();
    $vc->playFile('/path/to/second.mp3');
});
```

Or queue tracks by listening to the `end` event:

```php
$vc->on('ready', function () use ($vc) {
    $vc->playFile('/path/to/first.mp3');
});

$vc->on('end', function () use ($vc) {
    $vc->playFile('/path/to/second.mp3');
});
```

---

## ClientNotReadyException — client not ready

**What it means:** A playback method was called before the voice client finished connecting. The client is not ready to send audio yet.

**How to fix it:**

Always start playback inside the `ready` event:

```php
$discord->voice->joinChannel($channel)->then(function (VoiceClient $vc) {
    $vc->on('ready', function () use ($vc) {
        // Safe to call playback methods here:
        $vc->playFile('/path/to/audio.mp3');
    });
});
```

Do not call `playFile()`, `record()`, or similar methods directly inside the `join()` promise callback — wait for `ready`.

---

## BufferTimedOutException — audio stream stalled

**What it means:** The audio send buffer waited too long for the next chunk of data and timed out. This usually means the source stream stopped producing data.

**Common causes and fixes:**

- **File not found or unreadable** — verify the path and permissions:
  ```bash
  ls -la /path/to/audio.mp3
  ```

- **URL source is slow or unreliable** — use a local file for testing to rule out network issues.

- **ffmpeg process crashed** — check that `ffmpeg` is installed and can decode the file format:
  ```bash
  ffmpeg -i /path/to/audio.mp3 -f null -
  ```

- **Stream pipe closed early** — if you are piping a custom `ReadableStreamInterface`, make sure it does not close before the audio finishes.

- **System under heavy load** — the React event loop may be starved. Avoid blocking calls (e.g. synchronous I/O, `sleep()`) on the same loop tick as audio playback.
