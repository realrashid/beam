<?php

declare(strict_types=1);

namespace RealRashid\Beam\Http\Middleware;

use Closure;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Request;
use RealRashid\Beam\Data\Token;
use RealRashid\Beam\Exceptions\BeamException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proves the browser making this request was handed the call it names.
 * The token travels in the X-Beam-Token header (or a beam_token field);
 * the parsed Token is attached to the request as `beam.token`.
 */
final class ValidateBeamToken
{
    public const ATTRIBUTE = 'beam.token';

    public function __construct(private readonly Encrypter $encrypter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $raw = $request->header('X-Beam-Token') ?? $request->input('beam_token');

        if (! is_string($raw) || $raw === '') {
            return response()->json(['message' => 'A Beam token is required.', 'reason' => 'MISSING_TOKEN'], 401);
        }

        try {
            $token = Token::parse($raw, $this->encrypter);
        } catch (BeamException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason()], 401);
        }

        // A signed-in user must be the one the token was issued to. With no
        // session at all — a service worker declining a call from a locked
        // phone — the token stands on its own: it is encrypted with the
        // application key, names one call and one user, and expires. Every
        // route that needs more than that (the relay) also checks the registry.
        $user = $request->user();

        if ($user !== null && (string) $token->userId !== (string) $user->getAuthIdentifier()) {
            return response()->json(['message' => 'This Beam token was issued to a different user.', 'reason' => 'FORBIDDEN'], 403);
        }

        $request->attributes->set(self::ATTRIBUTE, $token);

        return $next($request);
    }
}
