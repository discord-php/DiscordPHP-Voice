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

namespace Discord\Voice\Dave;

abstract class NativeHandle
{
    protected mixed $handle;

    private bool $destroyed = false;

    public function __construct(mixed $handle)
    {
        $this->handle = $handle;
    }

    public function raw(): mixed
    {
        if ($this->destroyed || $this->handle === null) {
            throw new \RuntimeException('DAVE native handle already destroyed.');
        }

        return $this->handle;
    }

    public function destroy(): void
    {
        if ($this->destroyed || $this->handle === null) {
            return;
        }

        Runtime::destroyNativeHandle($this->handle, $this->destroyMethod());

        $this->handle = null;
        $this->destroyed = true;
    }

    public function __destruct()
    {
        $this->destroy();
    }

    abstract protected function destroyMethod(): string;
}
