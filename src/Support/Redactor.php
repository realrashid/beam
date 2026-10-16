<?php

declare(strict_types=1);

namespace RealRashid\Beam\Support;

/**
 * Strips TURN credentials, VAPID keys, tokens and the like before anything
 * reaches a log. Keys are matched by name, case-insensitively, at any depth.
 */
final class Redactor
{
    public const MASK = '[redacted]';

    private const SENSITIVE = ['credential', 'password', 'secret', 'token', 'private', 'authorization', 'vapid', 'api_key', 'apikey', 'auth'];

    /**
     * @template T
     *
     * @param  T  $value
     * @return T|string|array<mixed>
     */
    public static function scrub(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = is_string($key) && self::isSensitive($key) ? self::MASK : self::scrub($item);
            }

            return $out;
        }

        if (is_string($value)) {
            return self::scrubString($value);
        }

        return $value;
    }

    /** `credential=abc`, `"credential":"abc"`, `Bearer abc`, `apiKey=abc` inside a string. */
    public static function scrubString(string $value): string
    {
        $keys = implode('|', array_map('preg_quote', self::SENSITIVE));

        $value = (string) preg_replace('/("?(?:'.$keys.')[a-z_]*"?\s*[:=]\s*"?)([^"&\s,}]+)/i', '$1'.self::MASK, $value);

        return (string) preg_replace('/\bBearer\s+\S+/i', 'Bearer '.self::MASK, $value);
    }

    public static function isSensitive(string $key): bool
    {
        $lower = strtolower($key);

        foreach (self::SENSITIVE as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }
}
