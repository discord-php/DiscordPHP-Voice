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

use Pest\Factories\Attribute;
use Pest\PendingCalls\TestCall;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/Feature/Voice/WSDaveTestFixtures.php';

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * Attaches PHPUnit's #[WithoutErrorHandler] to a Pest test.
 *
 * Pest exposes no native chain for this attribute, so we push it onto the
 * generated test method directly. Pass the `it()`/`test()` call straight in (do
 * NOT assign the TestCall to a variable first) so its __destruct still fires at
 * statement end and the test registers normally.
 *
 * Use for negative-path tests that intentionally trigger a PHP warning in covered
 * src/ — PHPUnit otherwise reports @-suppressed warnings and fails the run.
 */
function withoutErrorHandler(TestCall $test): TestCall
{
    $test->testCaseMethod->attributes[] = new Attribute(WithoutErrorHandler::class, []);

    return $test;
}
