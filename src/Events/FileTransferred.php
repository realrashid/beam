<?php

declare(strict_types=1);

namespace RealRashid\Beam\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RealRashid\Beam\Data\File;
use RealRashid\Beam\Data\Participant;

final class FileTransferred
{
    use Dispatchable;

    public function __construct(
        public readonly string $transferId,
        public readonly Participant $from,
        public readonly Participant $to,
        public readonly File $file,
    ) {}
}
