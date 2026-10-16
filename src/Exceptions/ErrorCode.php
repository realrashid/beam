<?php

declare(strict_types=1);

namespace RealRashid\Beam\Exceptions;

enum ErrorCode: string
{
    case Forbidden = 'FORBIDDEN';
    case Expired = 'EXPIRED';
    case Tampered = 'TAMPERED';
    case ProtocolMismatch = 'PROTOCOL_MISMATCH';
    case NotFound = 'NOT_FOUND';
    case TurnUnavailable = 'TURN_UNAVAILABLE';
    case InvalidConfiguration = 'INVALID_CONFIGURATION';
    case GatewayError = 'GATEWAY_ERROR';
    case PushUnavailable = 'PUSH_UNAVAILABLE';
    case RoomFull = 'ROOM_FULL';
}
