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
use Discord\Voice\Dave\EncryptorHandle;
use Discord\Voice\Dave\KeyRatchetHandle;
use Discord\Voice\Dave\NativeHandle;
use Discord\Voice\Dave\SessionHandle;
use Discord\Voice\Dave\State;

it('tracks identity protocol epochs and gateway sequence updates', function (): void {
    $state = new State();

    $state->setIdentity(42, '123');
    expect($state->selfUserId)->toBe('42')
        ->and($state->groupId)->toBe(123);

    $state->setIdentity('84', null);
    expect($state->selfUserId)->toBe('84')
        ->and($state->groupId)->toBeNull();

    $state->setProtocolVersion(0);
    expect($state->protocolVersion)->toBe(0)
        ->and($state->passthroughMode)->toBeTrue();

    $state->setProtocolVersion(2);
    expect($state->protocolVersion)->toBe(2)
        ->and($state->passthroughMode)->toBeFalse();

    $state->prepareEpoch(7);
    $state->recordGatewaySequence(10);
    $state->recordGatewaySequence(null);
    $state->recordGatewaySequence(19);

    expect($state->epoch)->toBe(7)
        ->and($state->lastReceivedSequence)->toBe(19);
});

it('prepares and executes transitions only when the transition id matches', function (): void {
    $state = new State();
    $state->setProtocolVersion(1);

    $state->prepareTransition(11, 3);
    expect($state->pendingTransitionId)->toBe(11)
        ->and($state->pendingProtocolVersion)->toBe(3)
        ->and($state->latestPreparedTransitionVersion)->toBe(3);

    $state->executeTransition(99);
    expect($state->protocolVersion)->toBe(1)
        ->and($state->pendingTransitionId)->toBe(11)
        ->and($state->pendingProtocolVersion)->toBe(3);

    $state->executeTransition(11);
    expect($state->protocolVersion)->toBe(3)
        ->and($state->passthroughMode)->toBeFalse()
        ->and($state->pendingTransitionId)->toBeNull()
        ->and($state->pendingProtocolVersion)->toBeNull()
        ->and($state->latestPreparedTransitionVersion)->toBe(3);

    $state->prepareTransition(12);
    expect($state->pendingTransitionId)->toBe(12)
        ->and($state->pendingProtocolVersion)->toBeNull()
        ->and($state->latestPreparedTransitionVersion)->toBe(3);

    $state->executeTransition(12);
    expect($state->protocolVersion)->toBe(3)
        ->and($state->pendingTransitionId)->toBeNull()
        ->and($state->pendingProtocolVersion)->toBeNull();
});

it('keeps media passthrough enabled for a prepared protocol until transition execution', function (): void {
    $state = new State();

    $state->prepareProtocolVersion(1);
    expect($state->protocolVersion)->toBe(1)
        ->and($state->passthroughMode)->toBeTrue();

    $state->prepareTransition(22, 1);
    $state->executeTransition(22);

    expect($state->protocolVersion)->toBe(1)
        ->and($state->passthroughMode)->toBeFalse();
});

it('tracks recognized users and only appends self when needed', function (): void {
    $state = new State();
    $state->setIdentity(42, 100);

    $state->addRecognizedUsers([7, '7', '9']);
    expect($state->recognizedUsers())->toBe(['7', '9'])
        ->and($state->recognizedUsersIncludingSelf())->toBe(['7', '9', '42']);

    $state->addRecognizedUsers([42, '9']);
    expect($state->recognizedUsers())->toBe(['7', '9', '42'])
        ->and($state->recognizedUsersIncludingSelf())->toBe(['7', '9', '42']);
});

it('replaces and clears handles while destroying old instances', function (): void {
    $state = new State();

    $sessionOne = makeSessionHandle();
    $state->replaceSession($sessionOne);
    $state->replaceSession($sessionOne);
    expect($state->session)->toBe($sessionOne)
        ->and($sessionOne->raw())->toBeInstanceOf(\stdClass::class);

    $sessionTwo = makeSessionHandle();
    $state->replaceSession($sessionTwo);
    expectHandleDestroyed($sessionOne);
    expect($state->session)->toBe($sessionTwo);

    $encryptorOne = makeEncryptorHandle();
    $state->replaceEncryptor($encryptorOne);
    $state->replaceEncryptor($encryptorOne);
    expect($state->encryptor)->toBe($encryptorOne)
        ->and($encryptorOne->raw())->toBeInstanceOf(\stdClass::class);

    $encryptorTwo = makeEncryptorHandle();
    $state->replaceEncryptor($encryptorTwo);
    expectHandleDestroyed($encryptorOne);
    expect($state->encryptor)->toBe($encryptorTwo);

    $decryptorOne = makeDecryptorHandle();
    $state->setDecryptor(77, $decryptorOne);
    $state->setDecryptor('77', $decryptorOne);
    expect($state->getDecryptor(77))->toBe($decryptorOne)
        ->and($decryptorOne->raw())->toBeInstanceOf(\stdClass::class);

    $decryptorTwo = makeDecryptorHandle();
    $state->setDecryptor('77', $decryptorTwo);
    expectHandleDestroyed($decryptorOne);
    expect($state->getDecryptor(77))->toBe($decryptorTwo);

    $extraDecryptor = makeDecryptorHandle();
    $state->setDecryptor('88', $extraDecryptor);
    $state->clearDecryptors();
    expect($state->getDecryptor('77'))->toBeNull()
        ->and($state->getDecryptor('88'))->toBeNull();
    expectHandleDestroyed($decryptorTwo);
    expectHandleDestroyed($extraDecryptor);

    $state->replaceSession(null);
    $state->replaceEncryptor(null);
    expectHandleDestroyed($sessionTwo);
    expectHandleDestroyed($encryptorTwo);
    expect($state->session)->toBeNull()
        ->and($state->encryptor)->toBeNull();
});

it('resets protocol state without losing identity or recognized users', function (): void {
    $state = new State();
    $state->setIdentity('42', '123');
    $state->addRecognizedUsers(['7']);
    $state->externalSenderPackage = 'sender';
    $state->lastReceivedSequence = 55;
    $state->setProtocolVersion(2);
    $state->prepareEpoch(8);
    $state->prepareTransition(99, 5);

    $session = makeSessionHandle();
    $encryptor = makeEncryptorHandle();
    $decryptor = makeDecryptorHandle();

    $state->replaceSession($session);
    $state->replaceEncryptor($encryptor);
    $state->setDecryptor('7', $decryptor);
    $state->resetProtocolState();

    expect($state->session)->toBeNull()
        ->and($state->encryptor)->toBeNull()
        ->and($state->getDecryptor('7'))->toBeNull()
        ->and($state->protocolVersion)->toBe(0)
        ->and($state->epoch)->toBeNull()
        ->and($state->pendingTransitionId)->toBeNull()
        ->and($state->pendingProtocolVersion)->toBeNull()
        ->and($state->latestPreparedTransitionVersion)->toBeNull()
        ->and($state->passthroughMode)->toBeTrue()
        ->and($state->selfUserId)->toBe('42')
        ->and($state->groupId)->toBe(123)
        ->and($state->recognizedUsers())->toBe(['7'])
        ->and($state->recognizedUsersIncludingSelf())->toBe(['7', '42'])
        ->and($state->externalSenderPackage)->toBe('sender')
        ->and($state->lastReceivedSequence)->toBe(55);

    expectHandleDestroyed($session);
    expectHandleDestroyed($encryptor);
    expectHandleDestroyed($decryptor);
});

it('removes users and closes tracked handles without resetting metadata', function (): void {
    $state = new State();
    $state->setProtocolVersion(2);
    $state->addRecognizedUsers(['7', '8']);

    $removedDecryptor = makeDecryptorHandle();
    $removedKeyRatchet = makeKeyRatchetHandle();
    $state->setDecryptor('8', $removedDecryptor);
    $state->setKeyRatchet('8', $removedKeyRatchet);
    $state->removeRecognizedUser('8');

    expect($state->recognizedUsers())->toBe(['7'])
        ->and($state->getDecryptor('8'))->toBeNull()
        ->and($state->getKeyRatchet('8'))->toBeNull();
    expectHandleDestroyed($removedDecryptor);
    expectHandleDestroyed($removedKeyRatchet);

    $session = makeSessionHandle();
    $encryptor = makeEncryptorHandle();
    $decryptor = makeDecryptorHandle();
    $keyRatchet = makeKeyRatchetHandle();
    $selfKeyRatchet = makeKeyRatchetHandle();

    $state->replaceSession($session);
    $state->replaceEncryptor($encryptor);
    $state->setDecryptor('7', $decryptor);
    $state->setKeyRatchet('7', $keyRatchet);
    $state->setSelfKeyRatchet($selfKeyRatchet);
    $state->close();

    expect($state->session)->toBeNull()
        ->and($state->encryptor)->toBeNull()
        ->and($state->getDecryptor('7'))->toBeNull()
        ->and($state->getKeyRatchet('7'))->toBeNull()
        ->and($state->getSelfKeyRatchet())->toBeNull()
        ->and($state->protocolVersion)->toBe(2)
        ->and($state->recognizedUsers())->toBe(['7']);

    expectHandleDestroyed($session);
    expectHandleDestroyed($encryptor);
    expectHandleDestroyed($decryptor);
    expectHandleDestroyed($keyRatchet);
    expectHandleDestroyed($selfKeyRatchet);
});

it('destroys tracked handles when the state is destructed', function (): void {
    $session = makeSessionHandle();
    $encryptor = makeEncryptorHandle();
    $decryptor = makeDecryptorHandle();
    $keyRatchet = makeKeyRatchetHandle();
    $selfKeyRatchet = makeKeyRatchetHandle();

    $state = new State();
    $state->replaceSession($session);
    $state->replaceEncryptor($encryptor);
    $state->setDecryptor('1', $decryptor);
    $state->setKeyRatchet('1', $keyRatchet);
    $state->setSelfKeyRatchet($selfKeyRatchet);

    unset($state);
    gc_collect_cycles();

    expectHandleDestroyed($session);
    expectHandleDestroyed($encryptor);
    expectHandleDestroyed($decryptor);
    expectHandleDestroyed($keyRatchet);
    expectHandleDestroyed($selfKeyRatchet);
});

function makeSessionHandle(): SessionHandle
{
    return new SessionHandle(new \stdClass());
}

function makeEncryptorHandle(): EncryptorHandle
{
    return new EncryptorHandle(new \stdClass());
}

function makeDecryptorHandle(): DecryptorHandle
{
    return new DecryptorHandle(new \stdClass());
}

function makeKeyRatchetHandle(): KeyRatchetHandle
{
    return new KeyRatchetHandle(new \stdClass());
}

function expectHandleDestroyed(NativeHandle $handle): void
{
    expect(fn (): mixed => $handle->raw())
        ->toThrow(\RuntimeException::class, 'DAVE native handle already destroyed.');
}
