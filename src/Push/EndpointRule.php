<?php

declare(strict_types=1);

namespace RealRashid\Beam\Push;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A Web Push endpoint is a URL the browser hands over and the server posts to
 * on every incoming call — so it is somewhere an authenticated user gets to
 * point your server. Left as a plain `url` rule, that is a blind SSRF: a
 * subscription for `http://169.254.169.254/…` makes your application probe its
 * own network, with the status ending up in your logs.
 *
 * Two layers, both cheap:
 *
 *   - the host may not be your own network — a private or reserved IP literal,
 *     localhost, or one of the internal-only suffixes;
 *   - beam.push.endpoints, when it lists anything, is an allow-list of hosts.
 *
 * The allow-list is empty by default on purpose: Chrome, Firefox, Safari and
 * Edge are only the services that exist today, and refusing an unlisted one
 * would break a browser nobody has heard of yet in a way nobody could
 * diagnose. Applications that want the tighter posture set the four hosts and
 * accept that trade. Neither layer is a complete SSRF defence — a public
 * hostname resolving inward still gets through — and the docs say so.
 */
final class EndpointRule implements ValidationRule
{
    /** Hosts that only ever mean "this network". */
    private const INTERNAL_SUFFIXES = ['.localhost', '.local', '.internal', '.lan', '.home.arpa'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $host = strtolower((string) parse_url((string) $value, PHP_URL_HOST));

        if ($host === '') {
            $fail('The :attribute must be a push service URL.');

            return;
        }

        if (self::isOwnNetwork($host)) {
            $fail('The :attribute must be a push service Beam can reach on the internet, not an address on this network.');

            return;
        }

        $allowed = array_values(array_filter(array_map('strval', (array) config('beam.push.endpoints', []))));

        if ($allowed !== [] && ! self::isAllowed($host, $allowed)) {
            $fail('The :attribute is not one of the push services this application allows.');
        }
    }

    /** localhost, a single-label name, an internal suffix, or a private or reserved IP. */
    public static function isOwnNetwork(string $host): bool
    {
        $host = trim($host, '[]');

        if ($host === 'localhost' || ! str_contains($host, '.') && ! str_contains($host, ':')) {
            return true;
        }

        foreach (self::INTERNAL_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * An exact host, or any host under a listed domain.
     *
     * @param  array<int, string>  $allowed
     */
    public static function isAllowed(string $host, array $allowed): bool
    {
        foreach ($allowed as $candidate) {
            $candidate = strtolower(ltrim(trim($candidate), '*.'));

            if ($host === $candidate || str_ends_with($host, '.'.$candidate)) {
                return true;
            }
        }

        return false;
    }
}
