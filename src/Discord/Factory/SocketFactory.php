<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP project.
 *
 * Copyright (c) 2015-present David Cole <david.cole1340@gmail.com>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Discord\Factory;

use Discord\Voice\Client\UDP;
use Discord\Voice\Client\WS;
use React\Datagram\Factory;
use React\Dns\Resolver\Factory as DnsFactory;

final class SocketFactory extends Factory
{
    protected ?WS $ws;

    public function __construct($loop = null, $resolver = null, ?WS $ws = null)
    {
        if (null === $resolver) {
            $resolver = (new DnsFactory())->createCached($ws->data['dnsConfig'], $loop);
        }

        parent::__construct($loop, $resolver);

        if ($ws !== null) {
            $this->ws = $ws;
        }
    }

    public function createClient($address)
    {
        $loop = $this->loop;

        return $this->resolveAddress($address)->then(function ($address) use ($loop) {
            $socket = @\stream_socket_client($address, $errno, $errstr);
            if (! $socket) {
                throw new \Exception('Unable to create client socket: '.$errstr, $errno);
            }

            return new UDP($loop, $socket, ws: $this?->ws);
        });
    }
}
