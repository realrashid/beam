<?php

declare(strict_types=1);

namespace RealRashid\Beam\Data;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use RealRashid\Beam\Exceptions\BeamException;

/**
 * The one thing that crosses from PHP to the browser and back.
 *
 * Two forms of the same claims:
 *
 *   sign()  → an opaque string encrypted with the application key. The browser
 *             presents it back (X-Beam-Token) and the server trusts it.
 *   pack()  → base64url(json({ token, claims })): the opaque token *and* the
 *             claims in plain JSON, side by side. The browser reads the claims
 *             (channel, ICE servers, role, meta) and round-trips the token. It
 *             needs nothing else — no configuration in JavaScript.
 *
 * JSON rather than PHP serialisation because it is decrypted from an inbound
 * request, and unserialize() on attacker-influenced input is a gadget-chain risk
 * with no upside. Same construction as Paisa's IntentToken.
 *
 * @author Rashid Ali <realrashid05@gmail.com>
 */
final class Token
{
    public const VERSION = 1;

    public const ROLES = ['caller', 'callee', 'participant'];

    /**
     * @param  array<string, mixed>  $meta  about, from, media, transport, heartbeat, transferMax…
     */
    public function __construct(
        public readonly string $callId,
        public readonly string $channel,
        public readonly string $role,
        public readonly int|string $userId,
        public readonly IceServers $ice,
        public readonly int $expiresAt,
        public readonly array $meta = [],
    ) {
        if (! in_array($role, self::ROLES, true)) {
            throw BeamException::invalidConfiguration("Unknown token role [{$role}].");
        }
    }

    /** The opaque, server-trusted form. */
    public function sign(Encrypter $encrypter): string
    {
        return $encrypter->encrypt(json_encode($this->claims(), JSON_THROW_ON_ERROR), serialize: false);
    }

    /** What the browser is handed: the opaque token beside its plain claims. */
    public function pack(Encrypter $encrypter): string
    {
        $json = json_encode(['token' => $this->sign($encrypter), 'claims' => $this->claims()], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * The opaque token inside a packed one — or the string itself when it is
     * already opaque. A browser unpacks what it was handed and presents the
     * opaque half; a service worker declining a call from a notification has
     * only the packed payload, so both forms are accepted where one arrives.
     */
    public static function opaque(string $token): string
    {
        $json = json_decode((string) base64_decode(strtr(trim($token), '-_', '+/'), false), true);

        return is_array($json) && is_string($json['token'] ?? null) ? $json['token'] : $token;
    }

    /**
     * Checks, in order: readable (else tampered) → protocol version → expiry → shape.
     */
    public static function parse(string $token, Encrypter $encrypter, ?int $now = null): self
    {
        try {
            $json = $encrypter->decrypt(self::opaque($token), unserialize: false);
        } catch (DecryptException) {
            throw BeamException::tampered();
        }

        $data = json_decode((string) $json, true);

        if (! is_array($data) || ! isset($data['v'])) {
            throw BeamException::tampered();
        }

        if ((int) $data['v'] !== self::VERSION) {
            throw BeamException::protocolMismatch((int) $data['v'], self::VERSION);
        }

        if (! isset($data['exp']) || (int) $data['exp'] <= ($now ?? now()->getTimestamp())) {
            throw BeamException::expired();
        }

        if (! is_string($data['channel'] ?? null) || ! isset($data['sub']) || ! is_string($data['callId'] ?? null)
            || ! in_array($data['role'] ?? null, self::ROLES, true) || ! is_array($data['ice'] ?? null)) {
            throw BeamException::tampered();
        }

        return new self(
            callId: $data['callId'],
            channel: $data['channel'],
            role: $data['role'],
            userId: $data['sub'],
            ice: IceServers::fromArray($data['ice']),
            expiresAt: (int) $data['exp'],
            meta: is_array($data['meta'] ?? null) ? $data['meta'] : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function claims(): array
    {
        return [
            'v' => self::VERSION,
            'callId' => $this->callId,
            'channel' => $this->channel,
            'role' => $this->role,
            'sub' => $this->userId,
            'ice' => $this->ice->toArray(),
            'exp' => $this->expiresAt,
            'meta' => (object) $this->meta,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->claims();
    }

    public function isFor(string $channel): bool
    {
        return hash_equals($this->channel, $channel);
    }
}
