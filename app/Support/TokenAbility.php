<?php

namespace App\Support;

/**
 * Sanctum token abilities used by Orbit.
 *
 * Tokens issued at login get full access. Third-party integrations should be
 * given a narrowly scoped token (e.g. "links:read" for the link exports).
 */
final class TokenAbility
{
    public const FULL = '*';

    public const LINKS_READ = 'links:read';

    /**
     * Abilities a user may grant to a token they mint themselves.
     *
     * @return list<string>
     */
    public static function grantable(): array
    {
        return [self::LINKS_READ];
    }
}
