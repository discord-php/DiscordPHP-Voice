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

namespace Discord\Tests\Unit\Voice\Processes;

use Discord\Voice\Processes\DCA;
use Discord\Voice\Processes\Ffmpeg;
use Discord\Voice\Processes\OpusDecoderInterface;
use Discord\Voice\Processes\OpusFfi;
use Discord\Voice\Processes\ProcessAbstract;
use React\ChildProcess\Process;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

const ENCODER_FIXTURE_BINARIES = __DIR__.'/../../../Fixtures/Binaries';

function setEncoderExec(string $class, string $value): string
{
    $prop = new ReflectionProperty($class, 'exec');
    $prop->setAccessible(true);
    $previous = $prop->getValue();
    $prop->setValue(null, $value);

    return $previous;
}

function getEncoderProcessCmd(Process $process): string
{
    $prop = new ReflectionProperty(Process::class, 'cmd');
    $prop->setAccessible(true);

    return $prop->getValue($process);
}

beforeEach(function (): void {
    $this->originalPath = getenv('PATH') ?: '';
    $this->originalFfmpegExec = setEncoderExec(Ffmpeg::class, '/opt/ffmpeg-stub');
    $this->originalDcaExec = setEncoderExec(DCA::class, '/opt/dca-stub');
});

afterEach(function (): void {
    putenv("PATH={$this->originalPath}");
    setEncoderExec(Ffmpeg::class, $this->originalFfmpegExec);
    setEncoderExec(DCA::class, $this->originalDcaExec);
});

it('exposes Ffmpeg and DCA as concrete subclasses of ProcessAbstract', function (): void {
    expect(is_subclass_of(Ffmpeg::class, ProcessAbstract::class))->toBeTrue()
        ->and(is_subclass_of(DCA::class, ProcessAbstract::class))->toBeTrue()
        ->and((new ReflectionClass(Ffmpeg::class))->isFinal())->toBeTrue()
        ->and((new ReflectionClass(DCA::class))->isFinal())->toBeTrue();
});

it('declares the shared default sample rate constant on ProcessAbstract', function (): void {
    expect(ProcessAbstract::DEFAULT_KHZ)->toBe(48000);
});

it('builds an ffmpeg encode process with the documented opus pipeline arguments', function (): void {
    setEncoderExec(Ffmpeg::class, '/opt/ffmpeg');

    $cmd = getEncoderProcessCmd(Ffmpeg::encode());

    expect($cmd)->toStartWith('/opt/ffmpeg ')
        ->toContain('-protocol_whitelist file,http,https,tcp,tls,crypto,pipe')
        ->toContain("-i 'pipe:0'")
        ->toContain('-f opus')
        ->toContain('-c:a libopus')
        ->toContain('-ar 48000')
        ->toContain('-ac 2')
        ->toContain('-b:a 128000')
        ->toContain('-loglevel warning')
        ->toEndWith(' pipe:1');
});

it('honours custom volume and bitrate when encoding through ffmpeg', function (): void {
    setEncoderExec(Ffmpeg::class, '/opt/ffmpeg');

    $cmd = getEncoderProcessCmd(Ffmpeg::encode(null, -3.5, 64000));

    expect($cmd)
        ->toContain("-af 'volume=-3.5dB'")
        ->toContain('-b:a 64000');
});

it('escapes filenames passed to ffmpeg encode and decode to prevent shell injection', function (): void {
    setEncoderExec(Ffmpeg::class, '/opt/ffmpeg');

    $payload = 'evil; rm -rf / #';
    $encodeCmd = getEncoderProcessCmd(Ffmpeg::encode($payload));
    $decodeCmd = getEncoderProcessCmd(Ffmpeg::decode($payload));

    expect($encodeCmd)->toContain(escapeshellarg($payload))
        ->and($decodeCmd)->toContain(escapeshellarg(sys_get_temp_dir().DIRECTORY_SEPARATOR.date('Y-m-d_H-i').'-'.$payload.'.ogg'))
        ->and($encodeCmd)->not->toContain(' '.$payload.' ')
        ->and($decodeCmd)->not->toContain(' '.$payload.' ');
});

it('builds DCA encode commands with bitrate rounded to kbps and decode mode', function (): void {
    setEncoderExec(DCA::class, '/opt/dca');

    $cmd = getEncoderProcessCmd(DCA::encode(null, 0, 128499));

    expect($cmd)->toBe('/opt/dca -ab 128 -mode decode');
});

it('exposes the DCA1 protocol version constant', function (): void {
    expect(DCA::DCA_VERSION)->toBe('DCA1');
});

it('returns a Process instance from DCA decode without calling external binaries', function (): void {
    setEncoderExec(DCA::class, '/opt/dca');

    $process = DCA::decode();

    expect($process)->toBeInstanceOf(Process::class)
        ->and(getEncoderProcessCmd($process))->toStartWith('/opt/dca');
});

it('declares the OpusDecoderInterface contract', function (): void {
    $reflection = new ReflectionClass(OpusDecoderInterface::class);

    expect($reflection->isInterface())->toBeTrue()
        ->and($reflection->hasMethod('decode'))->toBeTrue();

    $method = $reflection->getMethod('decode');
    $params = $method->getParameters();

    expect($params)->toHaveCount(3)
        ->and($params[0]->getName())->toBe('data')
        ->and($params[1]->getName())->toBe('channels')
        ->and($params[1]->getDefaultValue())->toBe(2)
        ->and($params[2]->getName())->toBe('audioRate')
        ->and($params[2]->getDefaultValue())->toBe(48000)
        ->and((string) $method->getReturnType())->toBe('string');
});

it('confirms OpusFfi implements OpusDecoderInterface with the expected magic surface', function (): void {
    $reflection = new ReflectionClass(OpusFfi::class);

    expect($reflection->implementsInterface(OpusDecoderInterface::class))->toBeTrue()
        ->and($reflection->hasMethod('decode'))->toBeTrue()
        ->and($reflection->hasMethod('__call'))->toBeTrue()
        ->and($reflection->hasMethod('new'))->toBeTrue()
        ->and($reflection->getMethod('new')->isStatic())->toBeTrue();

    $decode = $reflection->getMethod('decode');
    expect($decode->isPublic())->toBeTrue()
        ->and((string) $decode->getReturnType())->toBe('string');
});

it('instantiates OpusFfi when libopus is available, otherwise skips', function (): void {
    if (! extension_loaded('ffi')) {
        $this->markTestSkipped('Requires the FFI extension to be enabled.');
    }

    try {
        $instance = OpusFfi::new();
    } catch (\Throwable $e) {
        $this->markTestSkipped('libopus.so.0 is not available on this system: '.$e->getMessage());
    }

    expect($instance)->toBeInstanceOf(OpusFfi::class)
        ->and($instance)->toBeInstanceOf(OpusDecoderInterface::class);
});

it('returns the configured Ffmpeg executable path when present on PATH', function (): void {
    if (! is_dir(ENCODER_FIXTURE_BINARIES)) {
        $this->markTestSkipped('Fixture binaries directory missing.');
    }

    putenv('PATH='.ENCODER_FIXTURE_BINARIES);
    setEncoderExec(Ffmpeg::class, 'missing');

    expect(Ffmpeg::checkForFFmpeg())->toBeTrue();

    $prop = new ReflectionProperty(Ffmpeg::class, 'exec');
    $prop->setAccessible(true);
    expect($prop->getValue())->toBe(ENCODER_FIXTURE_BINARIES.'/ffmpeg');
});

it('exposes only encode and decode as ProcessAbstract abstract methods', function (): void {
    $reflection = new ReflectionClass(ProcessAbstract::class);

    $abstractMethods = array_map(
        fn (ReflectionMethod $m): string => $m->getName(),
        array_filter(
            $reflection->getMethods(),
            fn (ReflectionMethod $m): bool => $m->isAbstract()
        )
    );

    sort($abstractMethods);

    expect($abstractMethods)->toBe(['decode', 'encode']);
});
