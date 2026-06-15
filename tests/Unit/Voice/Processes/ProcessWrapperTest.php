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

use BadMethodCallException;
use Discord\Voice\Exceptions\Libraries\FFmpegNotFoundException;
use Discord\Voice\Processes\DCA;
use Discord\Voice\Processes\Ffmpeg;
use Discord\Voice\Processes\ProcessAbstract;
use React\ChildProcess\Process;
use ReflectionProperty;

const FIXTURE_BINARIES = __DIR__.'/../../../Fixtures/Binaries';

function getProcessCommand(Process $process): string
{
    $property = new ReflectionProperty(Process::class, 'cmd');
    $property->setAccessible(true);

    return $property->getValue($process);
}

function getProtectedStatic(string $class, string $property): mixed
{
    $reflection = new ReflectionProperty($class, $property);
    $reflection->setAccessible(true);

    return $reflection->getValue();
}

function setProtectedStatic(string $class, string $property, mixed $value): void
{
    $reflection = new ReflectionProperty($class, $property);
    $reflection->setAccessible(true);
    $reflection->setValue(null, $value);
}

beforeEach(function (): void {
    $this->originalPath = getenv('PATH') ?: '';
    $this->originalFfmpegExec = getProtectedStatic(Ffmpeg::class, 'exec');
    $this->originalDcaExec = getProtectedStatic(DCA::class, 'exec');
});

afterEach(function (): void {
    putenv("PATH={$this->originalPath}");
    setProtectedStatic(Ffmpeg::class, 'exec', $this->originalFfmpegExec);
    setProtectedStatic(DCA::class, 'exec', $this->originalDcaExec);
});

it('locates direct executables and rejects missing paths', function (): void {
    expect(ProcessAbstract::checkForExecutable(PHP_BINARY))->toBe(PHP_BINARY)
        ->and(ProcessAbstract::checkForExecutable(dirname(__DIR__, 4).'/README.md'))->toBeNull();
});

it('finds ffmpeg on the path and reuses it for construction', function (): void {
    setProtectedStatic(Ffmpeg::class, 'exec', 'missing-ffmpeg');
    putenv('PATH='.FIXTURE_BINARIES);

    expect(Ffmpeg::checkForFFmpeg())->toBeTrue()
        ->and(getProtectedStatic(Ffmpeg::class, 'exec'))->toBe(FIXTURE_BINARIES.'/ffmpeg')
        ->and(new Ffmpeg())->toBeInstanceOf(Ffmpeg::class);
});

it('fails ffmpeg detection and construction when the binary is unavailable', function (): void {
    $sentinel = '/missing/ffmpeg';

    setProtectedStatic(Ffmpeg::class, 'exec', $sentinel);
    putenv('PATH=');

    expect(Ffmpeg::checkForFFmpeg())->toBeFalse()
        ->and(getProtectedStatic(Ffmpeg::class, 'exec'))->toBe($sentinel);

    expect(fn (): Ffmpeg => new Ffmpeg())
        ->toThrow(FFmpegNotFoundException::class, 'FFmpeg binary not found.');
});

it('builds ffmpeg encode commands with stdin defaults and pre-arguments', function (): void {
    setProtectedStatic(Ffmpeg::class, 'exec', '/opt/ffmpeg');

    $process = Ffmpeg::encode(null, -6, 192000, ['-re', '-nostdin']);

    expect(getProcessCommand($process))->toBe("/opt/ffmpeg '-re' '-nostdin' -protocol_whitelist file,http,https,tcp,tls,crypto,pipe -fflags +nobuffer -i 'pipe:0' -map_metadata -1 -f opus -c:a libopus -ar 48000 -af 'volume=-6dB' -ac 2 -b:a 192000 -loglevel warning pipe:1");
});

it('builds ffmpeg decode commands for stdout output by default', function (): void {
    setProtectedStatic(Ffmpeg::class, 'exec', '/opt/ffmpeg');

    $process = Ffmpeg::decode(null, 0, 128000, 2, null, ['-hide_banner']);

    expect(getProcessCommand($process))->toBe("/opt/ffmpeg '-hide_banner' -loglevel error -channel_layout stereo -ac 2 -ar 48000 -f s16le -i pipe:0 -acodec libopus -f ogg -ar 48000 -ac 2 -b:a 128000 'pipe:1'");
});

it('prefixes decoded ffmpeg files with a timestamp and ogg extension', function (): void {
    setProtectedStatic(Ffmpeg::class, 'exec', '/opt/ffmpeg');

    $process = Ffmpeg::decode('capture', 0, 96000, 1, 960);

    $tmpDir = sys_get_temp_dir();
    expect(getProcessCommand($process))
        ->toStartWith('/opt/ffmpeg -loglevel error -channel_layout stereo -ac 1 -ar 48000 -f s16le -i pipe:0 -acodec libopus -f ogg -ar 48000 -ac 1 -b:a 96000 ')
        ->toContain($tmpDir)
        ->toMatch("/capture\.ogg'$/");
});

it('keeps existing ogg extensions when decoding with ffmpeg', function (): void {
    setProtectedStatic(Ffmpeg::class, 'exec', '/opt/ffmpeg');

    $command = getProcessCommand(Ffmpeg::decode('clip.ogg'));

    expect($command)->toEndWith("-clip.ogg'")
        ->not->toMatch('/\.ogg\.ogg/');
});

it('routes explicit ffmpeg magic calls through binary detection', function (): void {
    putenv('PATH='.FIXTURE_BINARIES);

    $process = Ffmpeg::__callStatic('encode', [null, 1, 128000, ['-re']]);

    expect($process)->toBeInstanceOf(Process::class)
        ->and(getProcessCommand($process))->toBe(FIXTURE_BINARIES."/ffmpeg '-re' -protocol_whitelist file,http,https,tcp,tls,crypto,pipe -fflags +nobuffer -i 'pipe:0' -map_metadata -1 -f opus -c:a libopus -ar 48000 -af 'volume=1dB' -ac 2 -b:a 128000 -loglevel warning pipe:1");
});

it('throws for missing or invalid ffmpeg magic calls', function (): void {
    putenv('PATH=');

    expect(fn (): Process => Ffmpeg::__callStatic('encode', []))
        ->toThrow(FFmpegNotFoundException::class, 'FFmpeg binary not found.');

    expect(fn (): mixed => Ffmpeg::__callStatic('transcode', []))
        ->toThrow(BadMethodCallException::class, 'Method transcode does not exist in Discord\Voice\Processes\Ffmpeg');
});

it('finds dca on the path and updates the executable path', function (): void {
    setProtectedStatic(DCA::class, 'exec', 'missing-dca');
    putenv('PATH='.FIXTURE_BINARIES);

    expect(DCA::checkForDca())->toBeTrue()
        ->and(getProtectedStatic(DCA::class, 'exec'))->toBe(FIXTURE_BINARIES.'/dca');
});

it('returns false when dca is unavailable', function (): void {
    $sentinel = '/missing/dca';

    setProtectedStatic(DCA::class, 'exec', $sentinel);
    putenv('PATH=');

    expect(DCA::checkForDca())->toBeFalse()
        ->and(getProtectedStatic(DCA::class, 'exec'))->toBe($sentinel);
});

it('builds dca encode commands from the requested bitrate', function (): void {
    setProtectedStatic(DCA::class, 'exec', '/opt/dca');

    $process = DCA::encode('ignored.wav', 5, 128500, ['-x']);

    expect(getProcessCommand($process))->toBe('/opt/dca -ab 129 -mode decode');
});

it('builds dca decode commands even when the frame size defaults', function (): void {
    setProtectedStatic(DCA::class, 'exec', '/opt/dca');

    $process = DCA::decode(null, 0, 128000, 2, null);

    expect(getProcessCommand($process))->toBe('/opt/dca ');
});

it('does not produce a frameSize of zero when dca decode defaults the frame size', function (): void {
    setProtectedStatic(DCA::class, 'exec', '/opt/dca');

    // Prior to the fix, null * 48 = 0 was used as the frameSize.
    // The correct default is 960 samples (20 ms at 48 kHz).
    $process = DCA::decode(null, 0, 128000, 2, null);

    // The command must not embed a "0" frameSize artefact from round(null * 48).
    expect(getProcessCommand($process))->not->toContain('frameSize=0')
        ->and(getProcessCommand($process))->not->toContain(' 0 ');
});

it('escapes shell metacharacters in filenames passed to ffmpeg encode', function (): void {
    setProtectedStatic(Ffmpeg::class, 'exec', '/opt/ffmpeg');

    $malicious = "'; rm -rf /'";
    $process = Ffmpeg::encode($malicious, 0, 128000);
    $command = getProcessCommand($process);

    // The raw filename must NOT appear directly as the -i argument (unescaped injection)
    expect($command)->not->toContain("-i {$malicious} ")
        // The filename must appear safely quoted via escapeshellarg
        ->and($command)->toContain(escapeshellarg($malicious));
});
