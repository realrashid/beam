<?php

declare(strict_types=1);

namespace RealRashid\Beam\Support\Doctor;

/**
 * Web Push needs a VAPID key pair (a P-256 point and its private scalar) and
 * a subject the push services can contact. A truncated key fails only when
 * the first push is sent, which is when someone is being rung.
 */
final class VapidCheck implements Check
{
    public function name(): string
    {
        return 'Web Push keys';
    }

    public function run(): Result
    {
        $public = (string) config('beam.push.vapid.public', '');
        $private = (string) config('beam.push.vapid.private', '');
        $subject = (string) config('beam.push.vapid.subject', '');

        if ($public === '' && $private === '') {
            return Result::warn('Web Push is not configured: a user whose tab is closed cannot be rung.', 'php artisan beam:install writes VAPID keys to .env; then set BEAM_VAPID_SUBJECT=mailto:you@example.com.');
        }

        if (strlen(self::decode($public)) !== 65) {
            return Result::fail('BEAM_VAPID_PUBLIC is not a valid VAPID public key (expected a 65-byte uncompressed P-256 point, base64url).', 'Regenerate the pair with php artisan beam:install --force, or Minishlink\WebPush\VAPID::createVapidKeys().');
        }

        if (strlen(self::decode($private)) !== 32) {
            return Result::fail('BEAM_VAPID_PRIVATE is not a valid VAPID private key (expected 32 bytes, base64url).', 'Regenerate the pair with php artisan beam:install --force.');
        }

        if ($subject === '') {
            return Result::warn('BEAM_VAPID_SUBJECT is empty; push services want a contact for the sender.', 'Set BEAM_VAPID_SUBJECT=mailto:you@example.com (or an https: URL).');
        }

        if (! str_starts_with($subject, 'mailto:') && ! str_starts_with($subject, 'https://')) {
            return Result::fail("BEAM_VAPID_SUBJECT [{$subject}] must be a mailto: address or an https: URL.", 'Set BEAM_VAPID_SUBJECT=mailto:you@example.com.');
        }

        return Result::ok('VAPID keys are present and well-formed; subject '.$subject.'.');
    }

    private static function decode(string $key): string
    {
        return (string) base64_decode(strtr($key, '-_', '+/').str_repeat('=', (4 - strlen($key) % 4) % 4), true);
    }
}
