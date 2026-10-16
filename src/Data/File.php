<?php

declare(strict_types=1);

namespace RealRashid\Beam\Data;

/**
 * What crossed, as the file-transfer events carry it: the name, the size and
 * the type the sending browser declared.
 *
 * All three come from the other side of the connection, like the body of any
 * request — escape the name before it reaches a page, and sanitise it before
 * anything is saved under it.
 *
 * @author Rashid Ali <realrashid05@gmail.com>
 */
final class File
{
    public function __construct(
        public readonly string $name,
        public readonly int $size,
        public readonly string $mime,
    ) {}

    /**
     * @param  array{name?: string, size?: int|string, mime?: string}  $file
     */
    public static function from(array $file): self
    {
        return new self((string) ($file['name'] ?? ''), (int) ($file['size'] ?? 0), (string) ($file['mime'] ?? 'application/octet-stream'));
    }

    /** Kilobytes, megabytes — whatever the page wants to show. */
    public function forHumans(int $precision = 1): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $at = $this->size > 0 ? min((int) floor(log($this->size, 1024)), count($units) - 1) : 0;

        return round($this->size / 1024 ** $at, $at === 0 ? 0 : $precision).' '.$units[$at];
    }

    /**
     * @return array{name: string, size: int, mime: string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'size' => $this->size, 'mime' => $this->mime];
    }
}
