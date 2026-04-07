DiscordPHP Voice
====
[![Latest Stable Version](http://poser.pugx.org/discord-php-helpers/voice/v)](https://packagist.org/packages/discord-php-helpers/voice) [![Total Downloads](http://poser.pugx.org/discord-php-helpers/voice/downloads)](https://packagist.org/packages/discord-php-helpers/voice) [![Latest Unstable Version](http://poser.pugx.org/discord-php-helpers/voice/v/unstable)](https://packagist.org/packages/discord-php-helpers/voice) [![License](http://poser.pugx.org/discord-php-helpers/voice/license)](https://packagist.org/packages/discord-php-helpers/voice) [![PHP Version Require](http://poser.pugx.org/discord-php-helpers/voice/require/php)](https://packagist.org/packages/discord-php-helpers/voice)

[![PHP Discorders](https://discord.com/api/guilds/115233111977099271/widget.png?style=banner1)](https://discord.gg/dphp)

## Getting Started

Before you start using this Library, you **need** to know how PHP works, you need to know how Event Loops and Promises work. This is a fundamental requirement before you start. Without this knowledge, you will only suffer.

### Requirements

- [DiscordPHP](https://github.com/discord-php/DiscordPHP/)
- [PHP 8.1.2](https://php.net) or higher (latest version recommended)
	- x86 (32-bit) PHP requires [`ext-gmp`](https://www.php.net/manual/en/book.gmp.php) enabled.
- [`ext-json`](https://www.php.net/manual/en/book.json.php)

### DAVE (Discord Audio/Video End-to-End Encryption) runtime support

- DAVE protocol negotiation is now supported at the voice gateway layer.
- Binary DAVE voice opcodes are parsed and routed.
- If [`ext-ffi`](https://www.php.net/manual/en/book.ffi.php) is enabled and `libdave.so` is available, the runtime will load real libdave session and media APIs and detect the maximum supported DAVE protocol version.
- Use `./scripts/setup-libdave.sh` on Linux x64 to fetch the published `discord/libdave` release asset into `.cache/libdave` without vendoring the binary into git.
- Export `DISCORDPHP_DAVE_LIBRARY=$PWD/.cache/libdave/lib/libdave.so` to force the runtime to use the repo-local shared library path.
- CI uses the same setup script and enables `ext-ffi` so native DAVE coverage stays runnable.
- If libdave is unavailable, the client automatically falls back to protocol version `0` (transport-only behavior).

#### Local Linux x64 setup

```bash
COMPOSER_ROOT_VERSION=dev-main composer install --no-interaction --prefer-dist
./scripts/setup-libdave.sh
export DISCORDPHP_DAVE_LIBRARY="$PWD/.cache/libdave/lib/libdave.so"
phpunit --filter Dave
```

The setup script currently targets the published Linux x64 BoringSSL release asset `libdave-Linux-X64-boringssl.zip` from `discord/libdave`.
Use any PHPUnit 9-compatible runner available on your PATH for local validation.

### Basic Example

```php
<?php
// todo
```

See [examples folder](examples) for more.

## Documentation

Documentation for the latest version can be found [here](//discord-php.github.io/DiscordPHP/guide). Community contributed tutorials can be found on the [wiki](//github.com/discord-php/DiscordPHP/wiki).

## Contributing

We are open to contributions. However, please make sure you follow our coding standards (PSR-4 autoloading and custom styling). Please run php-cs-fixer before opening a pull request by running `composer run-script cs`.

## License

MIT License, &copy; David Cole and other contributers 2016-present.
