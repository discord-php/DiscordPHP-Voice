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

namespace Discord\Voice;

use Discord\Discord;
use Discord\Parts\Part;
use Discord\Repository\AbstractRepository;

/**
 * The bot's voice session in a guild, wherever the installed DiscordPHP keeps it.
 *
 * From DiscordPHP 10.66, `Discord::$voice_sessions` is a repository of `VoiceSession` parts keyed by guild,
 * stored through the cache so that shards and processes sharing a cache share it too. DiscordPHP's gateway
 * handlers fill it in from the bot's own voice state and the voice server. Before that, it was an array of
 * guild IDs to session IDs that this package kept itself. Both are handled here, so the rest of the
 * package need not know which it has.
 *
 * @link https://github.com/discord-php/DiscordPHP/issues/1441
 *
 * @internal
 *
 * @since 8.3.0
 */
final class VoiceSessions
{
    /**
     * The session part of each guild a voice client is connected in, by client.
     *
     * The voice gateway reads the session as it identifies and resumes, and cannot wait for the cache to
     * answer. A cache that sweeps lets go of parts nothing else holds, so these are held until their voice
     * client closes.
     *
     * @var \WeakMap<Discord, array<string, Part>>|null
     */
    private static ?\WeakMap $held = null;

    /**
     * The session ID to identify or resume with, or null when there is none to use.
     */
    public static function sessionId(Discord $discord, string|int $guildId): ?string
    {
        $sessions = self::of($discord);

        if ($sessions instanceof AbstractRepository) {
            // By key: the repository hands back a Part, and VoiceSession, which declares `session_id`,
            // cannot be named before DiscordPHP 10.66.
            $sessionId = $sessions->get('guild_id', (string) $guildId)['session_id'] ?? null;

            return is_string($sessionId) ? $sessionId : null;
        }

        return is_array($sessions) && isset($sessions[$guildId]) ? (string) $sessions[$guildId] : null;
    }

    /**
     * Records the session from the bot's voice state.
     *
     * A repository already has it: DiscordPHP records the session from the same event before any listener,
     * this package's included, hears about it. Its part is held for the voice client from here on, and let
     * go of once DiscordPHP removes it.
     */
    public static function remember(Discord $discord, string|int $guildId, ?string $sessionId): void
    {
        $sessions = self::of($discord);

        if ($sessions instanceof AbstractRepository) {
            self::hold($discord, (string) $guildId, $sessions->get('guild_id', (string) $guildId));

            return;
        }

        if (is_array($sessions)) {
            $array = &self::arrayOf($discord);
            $array[$guildId] = $sessionId;
        }
    }

    /**
     * Marks the session as one that cannot be resumed, so the next connection identifies afresh.
     */
    public static function invalidate(Discord $discord, string|int $guildId): void
    {
        $sessions = self::of($discord);

        if ($sessions instanceof AbstractRepository) {
            if ($session = $sessions->get('guild_id', (string) $guildId)) {
                $session['session_id'] = null;
                $sessions->cache->set((string) $guildId, $session);
            }

            return;
        }

        if (is_array($sessions)) {
            $array = &self::arrayOf($discord);
            $array[$guildId] = null;
        }
    }

    /**
     * Forgets the session, once its voice client is gone.
     */
    public static function forget(Discord $discord, string|int $guildId): void
    {
        $sessions = self::of($discord);

        if ($sessions instanceof AbstractRepository) {
            self::hold($discord, (string) $guildId, null);
            $sessions->cache->delete((string) $guildId);

            return;
        }

        if (is_array($sessions)) {
            $array = &self::arrayOf($discord);
            unset($array[$guildId]);
        }
    }

    /**
     * The client's voice sessions, as whichever type this DiscordPHP keeps them in.
     */
    private static function of(Discord $discord): mixed
    {
        return $discord->voice_sessions;
    }

    /**
     * The array a DiscordPHP before 10.66 keeps sessions in, to write to.
     *
     * For that array only, which is a declared property: from 10.66 `$discord->voice_sessions` is magic,
     * and taking a reference to it would be an indirect modification. Typed loosely, as static analysis
     * against 10.66 sees the repository here.
     */
    private static function &arrayOf(Discord $discord): mixed
    {
        return $discord->voice_sessions;
    }

    /**
     * Holds a guild's session part for the client, or lets go of it.
     */
    private static function hold(Discord $discord, string $guildId, ?Part $session): void
    {
        self::$held ??= new \WeakMap();

        $held = self::$held[$discord] ?? [];

        if ($session === null) {
            unset($held[$guildId]);
        } else {
            $held[$guildId] = $session;
        }

        if ($held === []) {
            unset(self::$held[$discord]);
        } else {
            self::$held[$discord] = $held;
        }
    }
}
