<?php

declare(strict_types=1);

arch('no debugging statements are left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();

arch('the package declares strict types everywhere')
    ->expect('RealRashid\Beam')
    ->toUseStrictTypes();

arch('secrets never go through the logger unredacted')
    ->expect('RealRashid\Beam')
    ->not->toUse(['error_log']);
