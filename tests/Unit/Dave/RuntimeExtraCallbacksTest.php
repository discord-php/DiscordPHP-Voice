<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP project.
 *
 * Copyright (c) 2015-2022 David Cole <david.cole1340@gmail.com>
 * Copyright (c) 2020-present Valithor Obsidion <valithor@discordphp.org>
 * Copyright (c) 2025-present Alexandre Candeias (Sky) <sky@discordphp.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Discord\Tests\Unit\Dave;

use Discord\Voice\Dave\DecryptorHandle;
use Discord\Voice\Dave\KeyRatchetHandle;
use Discord\Voice\Dave\Runtime;
use Discord\Voice\Dave\SessionHandle;

afterEach(function (): void {
    Runtime::reset();
});

it('buildMlsCommitWelcome short-circuits to null when protocol version is 0', function (): void {
    expect(Runtime::buildMlsCommitWelcome('proposals', 0))->toBeNull();
});

it('encryptMediaFrame returns null when DAVE is unavailable and no callback is set', function (): void {
    Runtime::configureCallbacks(availabilityOverride: false);

    expect(Runtime::encryptMediaFrame('hi', 1))->toBeNull();
});

it('decryptMediaFrame returns null when DAVE is unavailable and no callback is set', function (): void {
    Runtime::configureCallbacks(availabilityOverride: false);

    expect(Runtime::decryptMediaFrame('audio', 1))->toBeNull();
});

it('buildMlsCommitWelcome returns null when DAVE is unavailable and no callback is set', function (): void {
    Runtime::configureCallbacks(availabilityOverride: false);

    expect(Runtime::buildMlsCommitWelcome('p', 1))->toBeNull();
});

it('getMarshalledKeyPackage delegates to the keyPackage callback when set', function (): void {
    $session = new SessionHandle(new \stdClass());
    Runtime::configureCallbacks(
        keyPackageCallback: fn (SessionHandle $s): string => 'kp-bytes',
    );

    expect(Runtime::getMarshalledKeyPackage($session))->toBe('kp-bytes');
});

it('getKeyRatchet delegates to the keyRatchet callback when set', function (): void {
    $session = new SessionHandle(new \stdClass());
    $kr = new KeyRatchetHandle(new \stdClass());
    Runtime::configureCallbacks(
        keyRatchetCallback: fn (SessionHandle $s, string $u): KeyRatchetHandle => $kr,
    );

    expect(Runtime::getKeyRatchet($session, 'user-1'))->toBe($kr);
});

it('createDecryptor delegates to the createDecryptor callback when set', function (): void {
    $dec = new DecryptorHandle(new \stdClass());
    Runtime::configureCallbacks(
        createDecryptorCallback: fn (): DecryptorHandle => $dec,
    );

    expect(Runtime::createDecryptor())->toBe($dec);
});

it('configureDecryptorPassthrough delegates to the callback when set', function (): void {
    $captured = null;
    Runtime::configureCallbacks(
        decryptorPassthroughCallback: function (DecryptorHandle $d, bool $p) use (&$captured): bool {
            $captured = ['handle' => $d, 'passthrough' => $p];

            return true;
        },
    );

    $dec = new DecryptorHandle(new \stdClass());
    expect(Runtime::configureDecryptorPassthrough($dec, true))->toBeTrue()
        ->and($captured['handle'])->toBe($dec)
        ->and($captured['passthrough'])->toBeTrue();
});

it('configureDecryptorKeyRatchet delegates to the callback when set', function (): void {
    Runtime::configureCallbacks(
        decryptorKeyRatchetCallback: fn (): bool => true,
    );

    $dec = new DecryptorHandle(new \stdClass());
    $kr = new KeyRatchetHandle(new \stdClass());
    expect(Runtime::configureDecryptorKeyRatchet($dec, $kr))->toBeTrue();
});

it('decryptWithDecryptor delegates to the callback when set', function (): void {
    Runtime::configureCallbacks(
        decryptWithDecryptorCallback: fn (DecryptorHandle $d, string $f): string => "out:{$f}",
    );

    $dec = new DecryptorHandle(new \stdClass());
    expect(Runtime::decryptWithDecryptor($dec, 'frame'))->toBe('out:frame');
});

it('decryptWithDecryptor surfaces auth-fail false from the callback', function (): void {
    Runtime::configureCallbacks(
        decryptWithDecryptorCallback: fn (): bool => false,
    );

    $dec = new DecryptorHandle(new \stdClass());
    expect(Runtime::decryptWithDecryptor($dec, 'frame'))->toBeFalse();
});
