<?php

declare(strict_types=1);

namespace RealRashid\Beam\Commands;

use Illuminate\Console\Command;
use RealRashid\Beam\Support\Doctor\AssetVersionCheck;
use RealRashid\Beam\Support\Doctor\BroadcastingCheck;
use RealRashid\Beam\Support\Doctor\CacheCheck;
use RealRashid\Beam\Support\Doctor\Check;
use RealRashid\Beam\Support\Doctor\EchoClientEventsCheck;
use RealRashid\Beam\Support\Doctor\GateCheck;
use RealRashid\Beam\Support\Doctor\HttpsCheck;
use RealRashid\Beam\Support\Doctor\ManifestCheck;
use RealRashid\Beam\Support\Doctor\QueueCheck;
use RealRashid\Beam\Support\Doctor\Result;
use RealRashid\Beam\Support\Doctor\ServiceWorkerCheck;
use RealRashid\Beam\Support\Doctor\StunCheck;
use RealRashid\Beam\Support\Doctor\TurnCheck;
use RealRashid\Beam\Support\Doctor\VapidCheck;
use Throwable;

/**
 * beam:doctor — one line per thing that can be wrong, each saying what to do.
 * Exit code 1 when anything fails, so it can gate a deploy.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'beam:doctor';

    protected $description = 'Check Broadcasting, STUN/TURN, authorization, Web Push, HTTPS and timeouts, and say what to fix';

    /** @var list<class-string<Check>> */
    private static array $checks = [
        BroadcastingCheck::class,
        CacheCheck::class,
        StunCheck::class,
        TurnCheck::class,
        GateCheck::class,
        VapidCheck::class,
        ServiceWorkerCheck::class,
        ManifestCheck::class,
        AssetVersionCheck::class,
        HttpsCheck::class,
        EchoClientEventsCheck::class,
        QueueCheck::class,
    ];

    /**
     * Add an application's own check to the list.
     *
     * @param  class-string<Check>  $check
     */
    public static function add(string $check): void
    {
        if (! in_array($check, self::$checks, true)) {
            self::$checks[] = $check;
        }
    }

    /**
     * @param  class-string<Check>  $check
     */
    public static function forget(string $check): void
    {
        self::$checks = array_values(array_filter(self::$checks, fn (string $c) => $c !== $check));
    }

    public function handle(): int
    {
        $failed = 0;
        $this->newLine();

        foreach (self::$checks as $class) {
            /** @var Check $check */
            $check = $this->laravel->make($class);

            try {
                $result = $check->run();
            } catch (Throwable $e) {
                $result = Result::fail("{$check->name()} could not be checked: {$e->getMessage()}");
            }

            $this->line(match ($result->status) {
                'ok' => "  <fg=green>✓</> {$check->name()}: {$result->message}",
                'warn' => "  <fg=yellow>!</> {$check->name()}: {$result->message}",
                default => "  <fg=red>✗</> {$check->name()}: {$result->message}",
            });

            if ($result->fix !== null && $result->status !== 'ok') {
                $this->line("    <fg=gray>→ {$result->fix}</>");
            }

            if ($result->status === 'fail') {
                $failed++;
            }
        }

        $this->newLine();
        $this->line($failed === 0 ? '  <fg=green>Nothing is blocking a call.</>' : "  <fg=red>{$failed} thing".($failed === 1 ? '' : 's').' to fix before calls will work.</>');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
