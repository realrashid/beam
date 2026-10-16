<?php

declare(strict_types=1);

namespace RealRashid\Beam\Signaling;

/**
 * What a signal looks like on the wire when it is too big to broadcast.
 *
 * Pusher caps an event's payload at 10 KB and will not raise it; Reverb ships
 * the same 10,000-byte default. A renegotiation offer can exceed that on its
 * own: Chromium repeats the whole video codec list per m-line, so sharing a
 * screen alongside the camera — two video sections plus audio plus the data
 * channel — produced a 12 KB offer, the broadcaster answered "Payload too
 * large", the relay returned 500, and the screen never arrived. The rig found
 * it; nothing in the suite could, because a fake broadcaster has no cap.
 *
 * So anything over beam.signaling.max_payload crosses gzipped, and the client
 * inflates it. Compression is the cheapest answer: no extra round trip in the
 * middle of connecting a call, nothing new to authorise, and an SDP is
 * repetitive text — 12 KB becomes about 2.
 *
 * @author Rashid Ali <realrashid05@gmail.com>
 */
final class Envelope
{
    public const TYPE = 'gz';

    /** Below which a message is broadcast as it is. Deliberately under 10,000 for the event's own envelope. */
    public const DEFAULT_MAX = 9000;

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    public static function forBroadcast(array $message, ?int $max = null): array
    {
        $max ??= (int) config('beam.signaling.max_payload', self::DEFAULT_MAX);
        $json = json_encode($message, JSON_THROW_ON_ERROR);

        // 0 or less turns it off, for a broadcaster with no cap at all.
        if ($max <= 0 || strlen($json) <= $max) {
            return $message;
        }

        return [
            'type' => self::TYPE,
            'payload' => base64_encode((string) gzencode($json, 6)),
            // Kept outside the envelope so the receiving client can drop a
            // message from a third participant without inflating it first.
            'from' => $message['from'] ?? null,
        ];
    }

    /** The inverse, for tests and for anything server-side that wants to read one back. */
    public static function read(string $payload): mixed
    {
        return json_decode((string) gzdecode((string) base64_decode($payload, true)), true, flags: JSON_THROW_ON_ERROR);
    }
}
