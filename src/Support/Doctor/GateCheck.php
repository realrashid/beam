<?php

declare(strict_types=1);

namespace RealRashid\Beam\Support\Doctor;

use Illuminate\Contracts\Auth\Access\Gate as Access;
use RealRashid\Beam\Support\Gate as BeamGate;

/**
 * Without a beam.call ability, every signed-in user may ring every other one.
 * Fine on a laptop; a fail in production.
 */
final class GateCheck implements Check
{
    public function __construct(private readonly Access $gate) {}

    public function name(): string
    {
        return 'Authorization';
    }

    public function run(): Result
    {
        if ($this->gate->has(BeamGate::CALL)) {
            return Result::ok('The '.BeamGate::CALL.' ability decides who may call whom.');
        }

        $message = 'No '.BeamGate::CALL.' ability is defined, so any signed-in user may ring any other.';
        $fix = "Gate::define('".BeamGate::CALL."', fn (\$caller, \$callee) => …) in a service provider; docs/usage/authorization.md.";

        return app()->environment('production') ? Result::fail($message, $fix) : Result::warn($message, $fix);
    }
}
