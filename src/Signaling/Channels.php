<?php

declare(strict_types=1);

namespace RealRashid\Beam\Signaling;

/**
 * The Broadcasting channel names, in one place. Private channels all; Echo
 * adds the `private-` prefix itself.
 */
final class Channels
{
    public const PREFIX = 'beam.';

    public static function user(int|string $id): string
    {
        return self::PREFIX."user.{$id}";
    }

    public static function call(string $id): string
    {
        return self::PREFIX."call.{$id}";
    }

    public static function room(string $name): string
    {
        return self::PREFIX."room.{$name}";
    }

    public static function isBeam(string $channel): bool
    {
        return str_starts_with($channel, self::PREFIX);
    }
}
