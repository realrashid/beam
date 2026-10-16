<?php

declare(strict_types=1);

namespace RealRashid\Beam\Support\Doctor;

interface Check
{
    public function name(): string;

    public function run(): Result;
}
