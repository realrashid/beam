<?php

declare(strict_types=1);

namespace RealRashid\Beam\Support\Doctor;

final class Result
{
    private function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly ?string $fix = null,
    ) {}

    public static function ok(string $message): self
    {
        return new self('ok', $message);
    }

    public static function warn(string $message, ?string $fix = null): self
    {
        return new self('warn', $message, $fix);
    }

    public static function fail(string $message, ?string $fix = null): self
    {
        return new self('fail', $message, $fix);
    }
}
