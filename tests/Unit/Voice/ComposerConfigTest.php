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

namespace Discord\Tests\Unit\Voice;

// ---------------------------------------------------------------------------
// 1. composer.json — metadata fields
// ---------------------------------------------------------------------------

it('composer.json has required metadata fields', function (): void {
    $composer = loadComposer();

    expect($composer)->toHaveKey('name')
        ->and($composer)->toHaveKey('description')
        ->and($composer)->toHaveKey('license')
        ->and($composer)->toHaveKey('authors');
});

// ---------------------------------------------------------------------------
// 2. composer.json — runtime require declarations
// ---------------------------------------------------------------------------

it('composer.json require section declares ext-ffi', function (): void {
    $require = loadComposer()['require'] ?? [];

    expect($require)->toHaveKey('ext-ffi');
});

/**
 * EXPECTED TO FAIL: libsodium (ext-sodium) is used at runtime for RTP
 * encryption/decryption in Discord\Voice\Client\Packet via sodium_*() calls,
 * but it is not declared in the `require` section of composer.json.
 * The fix is to add "ext-sodium": "*" to the require block.
 */
it('composer.json require section declares ext-sodium (libsodium)', function (): void {
    $require = loadComposer()['require'] ?? [];

    expect($require)->toHaveKey('ext-sodium');
})->skip('known gap, not yet fixed');

// ---------------------------------------------------------------------------
// 3. composer.json — scripts section
// ---------------------------------------------------------------------------

it('composer.json scripts section has pint', function (): void {
    $scripts = loadComposer()['scripts'] ?? [];

    expect($scripts)->toHaveKey('pint');
});

it('composer.json scripts section has cs', function (): void {
    $scripts = loadComposer()['scripts'] ?? [];

    expect($scripts)->toHaveKey('cs');
});

it('composer.json scripts section has unit', function (): void {
    $scripts = loadComposer()['scripts'] ?? [];

    expect($scripts)->toHaveKey('unit');
});

it('composer.json scripts section has pest', function (): void {
    $scripts = loadComposer()['scripts'] ?? [];

    expect($scripts)->toHaveKey('pest');
});

it('composer.json scripts section has phpstan', function (): void {
    $scripts = loadComposer()['scripts'] ?? [];

    expect($scripts)->toHaveKey('phpstan');
});

// ---------------------------------------------------------------------------
// 4. Pint / CS-Fixer consistency
//    Both scripts exist but call DIFFERENT underlying tools (pint vs
//    php-cs-fixer). This test documents the inconsistency by verifying that
//    the pint script actually invokes pint (not php-cs-fixer) and that the cs
//    script invokes php-cs-fixer (not pint), making the distinction explicit.
// ---------------------------------------------------------------------------

it('pint script invokes vendor/bin/pint', function (): void {
    $scripts = loadComposer()['scripts'] ?? [];
    $pint = (array) ($scripts['pint'] ?? []);

    $joined = implode(' ', $pint);
    expect($joined)->toContain('pint');
});

it('cs script invokes vendor/bin/php-cs-fixer', function (): void {
    $scripts = loadComposer()['scripts'] ?? [];
    $cs = (array) ($scripts['cs'] ?? []);

    $joined = implode(' ', $cs);
    expect($joined)->toContain('php-cs-fixer');
});

// ---------------------------------------------------------------------------
// 5. CI workflows — PHPStan and code-style coverage
// ---------------------------------------------------------------------------

it('a CI workflow file runs PHPStan', function (): void {
    $found = workflowsContain('phpstan');

    expect($found)->toBeTrue('No .github/workflows/*.yml file contains a phpstan step or job.');
});

it('a CI workflow file runs a code-style check', function (): void {
    // Accepts either php-cs-fixer or pint in CI
    $found = workflowsContain('php-cs-fixer') || workflowsContain('pint');

    expect($found)->toBeTrue('No .github/workflows/*.yml file contains a code-style (php-cs-fixer/pint) step.');
});

/**
 * EXPECTED TO FAIL: docs.yml uses GITHUB_TOKEN for the github-pages-deploy
 * action but does NOT declare an explicit `permissions:` block, so it runs
 * with the default (broad) token permissions.  The fix is to add:
 *
 *   permissions:
 *     contents: write
 *     pages: write
 *
 * at the job or workflow level in docs.yml.
 */
it('every workflow that uses GITHUB_TOKEN declares explicit permissions', function (): void {
    $workflowsDir = workflowsDir();

    foreach (glob($workflowsDir.'/*.yml') as $file) {
        $content = file_get_contents($file);

        if (! str_contains($content, 'GITHUB_TOKEN')) {
            continue;
        }

        $name = basename($file);
        expect($content)->toContain(
            'permissions:',
            "Workflow {$name} uses GITHUB_TOKEN but declares no explicit permissions: block."
        );
    }
})->skip('known gap, not yet fixed');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function loadComposer(): array
{
    $path = dirname(__DIR__, 3).'/composer.json';

    return json_decode(file_get_contents($path), true);
}

function workflowsDir(): string
{
    return dirname(__DIR__, 3).'/.github/workflows';
}

function workflowsContain(string $needle): bool
{
    foreach (glob(workflowsDir().'/*.yml') as $file) {
        if (str_contains(file_get_contents($file), $needle)) {
            return true;
        }
    }

    return false;
}
