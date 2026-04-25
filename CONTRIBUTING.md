# Contributing

We welcome contributions. Follow the steps below to get set up and keep things consistent.

## Prerequisites

- PHP 8.1.2 or higher
- Composer
- FFmpeg — `apt install ffmpeg` / `brew install ffmpeg`
- libopus — `apt install libopus-dev` / `brew install opus`
- libdave — installed via `./scripts/setup-libdave.sh` (see Setup)
- PHP extensions: `ext-ffi`, `ext-sodium`, `ext-json`

## Setup

```bash
COMPOSER_ROOT_VERSION=dev-main composer install --no-interaction --prefer-dist
./scripts/setup-libdave.sh

# Linux
export DISCORDPHP_DAVE_LIBRARY="$PWD/.cache/libdave/lib/libdave.so"
# macOS
# export DISCORDPHP_DAVE_LIBRARY="$PWD/.cache/libdave/lib/libdave.dylib"
# Windows
# export DISCORDPHP_DAVE_LIBRARY="$PWD/.cache/libdave/bin/libdave.dll"
```

## Running Tests

- `composer unit` — full suite with libdave
- `composer unit-no-dave` — skip libdave-dependent tests
- `composer pest` — run tests in parallel

Tests live in:
- `tests/Unit/` — pure logic, no sockets or async
- `tests/Feature/Voice/` — gateway behavior with mocked sockets

## Code Style

- `composer cs` — auto-format with PHP-CS-Fixer (rewrites files)
- `composer cs:check` — dry-run check (no writes)
- `composer pint` — format with Laravel Pint

All PHP files must have `declare(strict_types=1);` at the top.

## Static Analysis

```bash
composer phpstan
```

Runs PHPStan at level 5. Fix all errors before opening a PR.

PHPStan is configured in `phpstan.neon` (level 5; `src/Discord/Voice/Helpers` and `OpEnum.php` are excluded as legacy/intentional code).

### Mutation Testing

[Infection](https://infection.github.io/) is available for deeper test quality validation. It is slow and not run in CI — use locally when adding significant new logic:

```bash
composer infection
```

## Pre-Push Checklist

```bash
composer check   # pint + cs:check + unit in one command
composer phpstan # must pass cleanly
```

## Pull Request Guidelines

- Describe what changed and why.
- Link to any relevant issues.
- Adding `no test` in a commit message skips CI — use sparingly (e.g. docs-only changes).

## Issue Tracker

Use GitHub issues only for bugs and feature requests in this library.

For usage questions, join our Discord:

[![PHP Discorders](https://discord.com/api/guilds/115233111977099271/widget.png?style=banner1)](https://discord.gg/dphp)
