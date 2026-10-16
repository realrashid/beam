<?php

declare(strict_types=1);

namespace RealRashid\Beam\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Every failure Beam raises carries a machine-readable reason beside its
 * message. Thrown inside a request it renders as JSON with the status the
 * reason implies — a gate refusal is a 403, not a 500.
 *
 * @author Rashid Ali <realrashid05@gmail.com>
 */
class BeamException extends RuntimeException
{
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function reason(): string
    {
        return $this->errorCode->value;
    }

    public function status(): int
    {
        return match ($this->errorCode) {
            ErrorCode::Forbidden => 403,
            ErrorCode::Expired, ErrorCode::Tampered, ErrorCode::ProtocolMismatch => 401,
            ErrorCode::NotFound => 404,
            ErrorCode::RoomFull => 409,
            ErrorCode::PushUnavailable, ErrorCode::TurnUnavailable, ErrorCode::GatewayError => 503,
            ErrorCode::InvalidConfiguration => 500,
        };
    }

    public function render(Request $request): JsonResponse|false
    {
        if (! $request->expectsJson() && $this->status() === 500) {
            return false;   // a misconfiguration deserves the full error page
        }

        return response()->json(['message' => $this->getMessage(), 'reason' => $this->reason()], $this->status());
    }

    public static function forbidden(string $message = 'This action is not allowed.'): self
    {
        return new self(ErrorCode::Forbidden, $message);
    }

    public static function expired(): self
    {
        return new self(ErrorCode::Expired, 'This Beam token has expired.');
    }

    public static function tampered(): self
    {
        return new self(ErrorCode::Tampered, 'This Beam token could not be read. It was issued by a different application key, or it has been altered.');
    }

    public static function protocolMismatch(int $got, int $want): self
    {
        return new self(ErrorCode::ProtocolMismatch, "This token speaks protocol v{$got}; this server speaks v{$want}. Update @realrashid/beam and realrashid/beam together.");
    }

    public static function notFound(string $id): self
    {
        return new self(ErrorCode::NotFound, "No live call or room with id [{$id}].");
    }

    public static function turnUnavailable(string $driver, string $detail): self
    {
        return new self(ErrorCode::TurnUnavailable, "The {$driver} TURN service did not answer ({$detail}). The call goes ahead on STUN.");
    }

    public static function pushUnavailable(string $message): self
    {
        return new self(ErrorCode::PushUnavailable, $message);
    }

    public static function roomFull(string $room): self
    {
        return new self(ErrorCode::RoomFull, "Room [{$room}] already has two people in it. A Beam room carries two; a mesh or an SFU is not in v1.");
    }

    public static function invalidConfiguration(string $message): self
    {
        return new self(ErrorCode::InvalidConfiguration, $message);
    }
}
