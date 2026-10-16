<?php

declare(strict_types=1);

namespace RealRashid\Beam\Commands;

use Illuminate\Console\Command;
use RealRashid\Beam\Support\CallSweeper;

final class SweepCommand extends Command
{
    protected $signature = 'beam:sweep';

    protected $description = 'Decline rings nobody answered and end calls nobody is in any more (call.timeout.driver = scheduler)';

    public function handle(CallSweeper $sweeper): int
    {
        [$declined, $ended] = $sweeper->sweep();

        $this->components->info("Swept: {$declined} ring(s) declined, {$ended} call(s) ended.");

        return self::SUCCESS;
    }
}
