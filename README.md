DiscordPHP Voice
====
[![Latest Stable Version](http://poser.pugx.org/discord-php-helpers/voice/v)](https://packagist.org/packages/discord-php-helpers/voice) [![Total Downloads](http://poser.pugx.org/discord-php-helpers/voice/downloads)](https://packagist.org/packages/discord-php-helpers/voice) [![Latest Unstable Version](http://poser.pugx.org/discord-php-helpers/voice/v/unstable)](https://packagist.org/packages/discord-php-helpers/voice) [![License](http://poser.pugx.org/discord-php-helpers/voice/license)](https://packagist.org/packages/discord-php-helpers/voice) [![PHP Version Require](http://poser.pugx.org/discord-php-helpers/voice/require/php)](https://packagist.org/packages/discord-php-helpers/voice) [![codecov](https://codecov.io/gh/discord-php/DiscordPHP-Voice/graph/badge.svg)](https://codecov.io/gh/discord-php/DiscordPHP-Voice)

[![PHP Discorders](https://discord.com/api/guilds/115233111977099271/widget.png?style=banner1)](https://discord.gg/dphp)

## Getting Started

Before you start using this Library, you **need** to know how PHP works, you need to know how Event Loops and Promises work. This is a fundamental requirement before you start. Without this knowledge, you will only suffer.

### Requirements

- [DiscordPHP](https://github.com/discord-php/DiscordPHP/)
- [PHP 8.3](https://php.net) or higher (latest version recommended)
	- x86 (32-bit) PHP requires [`ext-gmp`](https://www.php.net/manual/en/book.gmp.php) enabled.
- [`ext-json`](https://www.php.net/manual/en/book.json.php)

### DAVE (Discord Audio/Video End-to-End Encryption) runtime support

- **[📊 Visual DAVE Protocol Guide →](docs/DAVE.md)** — Mermaid diagrams covering architecture, MLS lifecycle, media encryption pipeline, and all DAVE opcodes.
- **[🎵 Audio Pipeline Guide →](docs/AUDIO_PIPELINE.md)** — Mermaid diagrams covering outbound/inbound audio flow, playback state machine, and format chain.
- **[📡 Protocol Reference →](docs/PROTOCOL.md)** — Voice gateway opcodes, DAVE opcodes, and close codes reference table.
- DAVE protocol negotiation is now supported at the voice gateway layer.
- Binary DAVE voice opcodes are parsed and routed.
- **Required:** [`ext-ffi`](https://www.php.net/manual/en/book.ffi.php) must be enabled and `libdave` must be available — the runtime loads libdave session and media APIs and detects the maximum supported DAVE protocol version.
- Use `./scripts/setup-libdave.sh` to fetch the published `discord/libdave` release asset into `.cache/libdave` without vendoring the binary into git. The script auto-detects your OS and architecture (Linux, macOS, Windows — x64 and ARM64).
- Export `DISCORDPHP_DAVE_LIBRARY` pointing to the platform library (e.g. `.cache/libdave/lib/libdave.so` on Linux) to force the runtime to use the repo-local shared library path.
- CI uses the same setup script and enables `ext-ffi` so native DAVE coverage stays runnable.
- libdave is **required** for voice connections. `Manager::__construct()` and the voice WebSocket throw `LibDaveNotFoundException` immediately when [`ext-ffi`](https://www.php.net/manual/en/book.ffi.php) or a working libdave library cannot be loaded — there is currently no automatic fallback to protocol version `0`. Discord has required the DAVE E2EE protocol for all voice and video connections since March 1st, 2026.

#### Local setup

```bash
COMPOSER_ROOT_VERSION=dev-main composer install --no-interaction --prefer-dist
./scripts/setup-libdave.sh

# Linux
export DISCORDPHP_DAVE_LIBRARY="$PWD/.cache/libdave/lib/libdave.so"
# macOS
# export DISCORDPHP_DAVE_LIBRARY="$PWD/.cache/libdave/lib/libdave.dylib"
# Windows (Git Bash / PowerShell)
# export DISCORDPHP_DAVE_LIBRARY="$PWD/.cache/libdave/bin/libdave.dll"

./vendor/bin/pest tests/Unit/Dave/RuntimeTest.php
```

The script auto-detects your OS and architecture (Linux, macOS, Windows — x64 and ARM64). Set `DISCORDPHP_DAVE_LIBRARY` to the path printed by the script.
Use the bundled Pest runner for local validation. `composer unit` runs the default suite with TestDox output, while `composer pest` keeps the parallel full-suite command.

## Composer Scripts

| Script | Description |
|--------|-------------|
| `composer unit` | Run the test suite (`pest --testdox`) |
| `composer lint` | Run PHPLint syntax check |
| `composer check` | Run CS-Fixer dry-run + PHPLint + tests (CI-safe, no rewrites) |
| `composer cs` | Auto-format with PHP-CS-Fixer (rewrites files) |
| `composer cs:check` | Check style without rewriting files |
| `composer phpstan` | Run static analysis (level 5) |
| `composer infection` | Run mutation testing (slow — local only) |
| `composer coverage` | Run tests with Xdebug coverage report |

### Basic Example

```php
$discord->on('init', function (Discord $discord) {
    $channel = $discord->getChannel('YOUR_CHANNEL_ID');

    $discord->voice->joinChannel($channel)->then(function (VoiceClient $vc) {
        $vc->on('ready', function () use ($vc) {
            $vc->playFile('/path/to/audio.mp3');
        });

        $vc->on('end', function () use ($vc) {
            $vc->disconnect();
        });
    });
});
```

### Recording

```php
// Raw PCM events — handle audio yourself
$discord->voice->joinChannel($channel)->then(function (VoiceClient $vc) use ($discord) {
    $vc->on('ready', function () use ($vc, $discord) {
        $vc->record();

        // Fires with raw 16-bit stereo 48 kHz PCM for each decoded Opus frame.
        $vc->on('channel-pcm', function (string $pcm) {
            // write $pcm to a file, pipe to an encoder, etc.
        });

        $discord->getLoop()->addTimer(10, function () use ($vc) {
            $vc->stopRecording();
            $vc->disconnect();
        });
    });
});
```

```php
use Discord\Voice\Recording\RecordingFormat;

// Automatic per-user WAV files — no manual file handling needed
$discord->voice->joinChannel($channel)->then(function (VoiceClient $vc) use ($discord) {
    $vc->on('ready', function () use ($vc, $discord) {
        $vc->record(
            RecordingFormat::WAV,
            fn(string $userId) => "/tmp/recording_{$userId}.wav"
        );

        $discord->getLoop()->addTimer(10, function () use ($vc) {
            $vc->stopRecording(); // finalizes and closes all WAV files
            $vc->disconnect();
        });
    });
});
```

> **Recording formats:** `RecordingFormat::PCM` emits raw PCM events (default). `RecordingFormat::WAV` writes self-contained WAV files per user (pure PHP). `RecordingFormat::OGG` writes OGG Opus files per user (requires ffmpeg).

See [examples folder](examples) for full runnable scripts.

## Documentation

Documentation for the latest version can be found [here](//discord-php.github.io/DiscordPHP/guide). Community contributed tutorials can be found on the [wiki](//github.com/discord-php/DiscordPHP/wiki).

## Troubleshooting

Having issues? See [**docs/TROUBLESHOOTING.md**](docs/TROUBLESHOOTING.md) for solutions to common problems including missing libraries (libdave, ffmpeg, opus, libsodium), permission errors, and playback state issues.

## Exceptions

All library exceptions implement the [`VoiceException`](src/Discord/Voice/Exceptions/VoiceException.php) marker interface, so you can catch any voice error with a single handler:

```php
use Discord\Voice\Exceptions\VoiceException;

try {
    $manager->joinChannel($channel);
} catch (VoiceException $e) {
    echo "Voice error: " . $e->getMessage();
}
```

Common exceptions include `LibDaveNotFoundException`, `EnterChannelDeniedException`, `CantSpeakInChannelException`, `FFmpegNotFoundException`, and `LibSodiumNotFoundException`.

## Contributing

We are open to contributions. However, please make sure you follow our coding standards (PSR-4 autoloading and custom styling). Please run php-cs-fixer before opening a pull request by running `composer cs`.

See [CONTRIBUTING.md](CONTRIBUTING.md) for setup, coding standards, and PR guidelines.

See [CHANGELOG.md](CHANGELOG.md) for version history.

## License

MIT License, &copy; David Cole and other contributors 2016-present.
