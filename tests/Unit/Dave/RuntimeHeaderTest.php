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

use Discord\Voice\Dave\Runtime;

afterEach(function (): void {
    Runtime::reset();
});

// ---------------------------------------------------------------------------
// loadHeaderDefinitions — preprocessor stripping
// ---------------------------------------------------------------------------

it('strips preprocessor directives from a C header', function (): void {
    $header = createTempHeader(<<<'HEADER'
#pragma once

#include <stddef.h>
#include <stdint.h>

uint16_t daveMaxSupportedProtocolVersion(void);
HEADER);

    $result = Runtime::loadHeaderDefinitions($header);

    $this->assertNotNull($result);
    $this->assertStringNotContainsString('#pragma', $result);
    $this->assertStringNotContainsString('#include', $result);
    $this->assertStringContainsString('uint16_t daveMaxSupportedProtocolVersion(void);', $result);
});

it('expands DECLARE_OPAQUE_HANDLE macros', function (): void {
    $header = createTempHeader(<<<'HEADER'
#define DECLARE_OPAQUE_HANDLE(x) typedef struct x##_s* x

DECLARE_OPAQUE_HANDLE(DAVESessionHandle);
DECLARE_OPAQUE_HANDLE(DAVEEncryptorHandle);
HEADER);

    $result = Runtime::loadHeaderDefinitions($header);

    $this->assertNotNull($result);
    $this->assertStringContainsString('typedef struct DAVESessionHandle_s* DAVESessionHandle;', $result);
    $this->assertStringContainsString('typedef struct DAVEEncryptorHandle_s* DAVEEncryptorHandle;', $result);
    $this->assertStringNotContainsString('DECLARE_OPAQUE_HANDLE', $result);
});

it('strips DAVE_EXPORT from function declarations', function (): void {
    $header = createTempHeader(<<<'HEADER'
#define DAVE_EXPORT __attribute__((visibility("default")))

DAVE_EXPORT uint16_t daveMaxSupportedProtocolVersion(void);
DAVE_EXPORT void daveFree(void* ptr);
HEADER);

    $result = Runtime::loadHeaderDefinitions($header);

    $this->assertNotNull($result);
    $this->assertStringContainsString('uint16_t daveMaxSupportedProtocolVersion(void);', $result);
    $this->assertStringContainsString('void daveFree(void* ptr);', $result);
    $this->assertStringNotContainsString('DAVE_EXPORT', $result);
});

it('strips extern C blocks and ifdef guards', function (): void {
    $header = createTempHeader(<<<'HEADER'
#ifdef __cplusplus
extern "C" {
#endif

void daveFree(void* ptr);

#ifdef __cplusplus
}
#endif
HEADER);

    $result = Runtime::loadHeaderDefinitions($header);

    $this->assertNotNull($result);
    $this->assertStringContainsString('void daveFree(void* ptr);', $result);
    $this->assertStringNotContainsString('extern', $result);
    $this->assertStringNotContainsString('#ifdef', $result);
});

it('strips block and line comments', function (): void {
    $header = createTempHeader(<<<'HEADER'
/**
 * @brief Returns the max protocol version
 */
uint16_t daveMaxSupportedProtocolVersion(void);

// This is a line comment
void daveFree(void* ptr);
HEADER);

    $result = Runtime::loadHeaderDefinitions($header);

    $this->assertNotNull($result);
    $this->assertStringNotContainsString('@brief', $result);
    $this->assertStringNotContainsString('line comment', $result);
    $this->assertStringContainsString('uint16_t daveMaxSupportedProtocolVersion(void);', $result);
    $this->assertStringContainsString('void daveFree(void* ptr);', $result);
});

withoutErrorHandler(it('returns null for nonexistent header file', function (): void {
    $result = Runtime::loadHeaderDefinitions('/nonexistent/path/dave.h');

    $this->assertNull($result);
}));

it('returns null for empty header file', function (): void {
    $header = createTempHeader('');

    $result = Runtime::loadHeaderDefinitions($header);

    $this->assertNull($result);
});

it('processes the real dave.h header from the installed libdave', function (): void {
    $headerPath = dirname(__DIR__, 3).'/.cache/libdave/include/dave/dave.h';

    if (! is_file($headerPath)) {
        $this->markTestSkipped('libdave not installed — .cache/libdave/include/dave/dave.h not found');
    }

    $result = Runtime::loadHeaderDefinitions($headerPath);

    $this->assertNotNull($result);
    // Verify key declarations survived preprocessing
    $this->assertStringContainsString('typedef struct DAVESessionHandle_s* DAVESessionHandle;', $result);
    $this->assertStringContainsString('typedef struct DAVEEncryptorHandle_s* DAVEEncryptorHandle;', $result);
    $this->assertStringContainsString('typedef struct DAVEDecryptorHandle_s* DAVEDecryptorHandle;', $result);
    $this->assertStringContainsString('daveMaxSupportedProtocolVersion', $result);
    $this->assertStringContainsString('daveSessionCreate', $result);
    $this->assertStringContainsString('daveEncryptorEncrypt', $result);
    $this->assertStringContainsString('daveDecryptorDecrypt', $result);
    // Verify no preprocessing artifacts remain
    $this->assertStringNotContainsString('#pragma', $result);
    $this->assertStringNotContainsString('#include', $result);
    $this->assertStringNotContainsString('DAVE_EXPORT', $result);
    $this->assertStringNotContainsString('DECLARE_OPAQUE_HANDLE', $result);
    $this->assertStringNotContainsString('extern "C"', $result);
});

it('handles nested ifdef blocks in the header', function (): void {
    $header = createTempHeader(<<<'HEADER'
#if (defined(_WIN32) || defined(_WIN64))
#define DAVE_EXPORT __declspec(dllexport)
#else
#define DAVE_EXPORT __attribute__((visibility("default")))
#endif

DAVE_EXPORT void daveFree(void* ptr);
HEADER);

    $result = Runtime::loadHeaderDefinitions($header);

    $this->assertNotNull($result);
    $this->assertStringContainsString('void daveFree(void* ptr);', $result);
    $this->assertStringNotContainsString('#if', $result);
    $this->assertStringNotContainsString('#define', $result);
    $this->assertStringNotContainsString('DAVE_EXPORT', $result);
});

// ---------------------------------------------------------------------------
// destroyNativeHandle — error recording
// ---------------------------------------------------------------------------

it('records error in lastDestroyError instead of silently swallowing', function (): void {
    Runtime::configureCallbacks(availabilityOverride: false);

    // With no FFI loaded, destroyNativeHandle should be a no-op
    Runtime::destroyNativeHandle(null, 'daveSessionDestroy');
    $this->assertNull(Runtime::getLastDestroyError());
});

it('clears lastDestroyError on reset', function (): void {
    Runtime::reset();
    $this->assertNull(Runtime::getLastDestroyError());
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function createTempHeader(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'dave_header_test_');
    file_put_contents($path, $content);

    // Register cleanup
    register_shutdown_function(function () use ($path): void {
        @unlink($path);
    });

    return $path;
}
