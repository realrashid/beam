<?php

declare(strict_types=1);

namespace RealRashid\Beam\Support\Doctor;

use RealRashid\Beam\Data\Token;
use RealRashid\Beam\Http\Controllers\AssetController;

/**
 * The client and the server speak one protocol version; a token from one is
 * refused by the other. The compiled client ships with the package, so they
 * only drift when a cache keeps serving an old beam.js.
 */
final class AssetVersionCheck implements Check
{
    public function name(): string
    {
        return 'Client version';
    }

    public function run(): Result
    {
        $path = AssetController::dist('beam.js');

        if (! is_file($path)) {
            return Result::fail('The compiled client (resources/dist/beam.js) is missing from the package.', 'Reinstall with composer reinstall realrashid/beam; the archive ships resources/dist.');
        }

        if (! preg_match('/PROTOCOL_VERSION\s*=\s*(\d+)/', (string) file_get_contents($path), $m)) {
            return Result::fail('resources/dist/beam.js carries no PROTOCOL_VERSION marker; it is not a Beam build.', 'Reinstall the package.');
        }

        if ((int) $m[1] !== Token::VERSION) {
            return Result::fail("beam.js speaks protocol {$m[1]}; PHP speaks ".Token::VERSION.'.', 'composer update realrashid/beam (the two ship together), then purge any CDN or proxy cache of beam.js.');
        }

        return Result::ok('beam.js and PHP both speak protocol '.Token::VERSION.'; the asset URL carries a version hash, so browsers fetch a new build after an update.');
    }
}
