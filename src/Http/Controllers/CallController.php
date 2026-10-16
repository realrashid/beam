<?php

declare(strict_types=1);

namespace RealRashid\Beam\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use RealRashid\Beam\BeamManager;
use RealRashid\Beam\Call;
use RealRashid\Beam\Data\CallState;
use RealRashid\Beam\Data\Participant;
use RealRashid\Beam\Data\Token;
use RealRashid\Beam\Events\CallAnswered;
use RealRashid\Beam\Events\ParticipantJoined;
use RealRashid\Beam\Exceptions\BeamException;
use RealRashid\Beam\Exceptions\ErrorCode;
use RealRashid\Beam\Http\Middleware\ValidateBeamToken;
use RealRashid\Beam\Room;
use RealRashid\Beam\Signaling\Channels;
use RealRashid\Beam\Support\CallRegistry;

/**
 * What a browser tells the server about a call it is in: still here
 * (heartbeat), no thanks (decline), goodbye (end), nobody picked up (timeout).
 * Membership in the live call is the proof; the token did its job at join.
 */
final class CallController
{
    public function __construct(
        private readonly CallRegistry $registry,
        private readonly BeamManager $beam,
    ) {}

    public function heartbeat(Request $request, string $id): Response|JsonResponse
    {
        $data = $request->validate(['state' => ['required', Rule::enum(CallState::class)]]);
        $channel = $this->participantChannel($request, $id);

        if ($channel === null) {
            return $this->notParticipant();
        }

        $heartbeat = (int) config('beam.call.heartbeat', 30);
        $userId = $request->user()->getAuthIdentifier();
        $firstTime = ! $this->registry->hasSeen($id, $userId);

        $this->registry->touch($id, $channel, $userId, $data['state'], $heartbeat);

        // A room participant's first heartbeat is their arrival.
        if ($firstTime && ($room = $this->participantRoom($request, $id)) !== null) {
            event(new ParticipantJoined($room, Participant::from($request->user())));
        }

        // Rooms have no answer moment; calls do. The first "connected" from
        // either side of a call is the answer: server-timed, once.
        $call = $this->participantCall($request, $id);
        if ($call !== null && $data['state'] === CallState::Connected->value && $this->registry->answered($id)) {
            $answered = Call::fromArray($this->registry->get($id) ?? $call->toArray());
            event(new CallAnswered($answered, Participant::from($request->user())));
            $this->beam->timeouts()->watch($answered);
        }

        return response()->noContent();
    }

    public function decline(Request $request, string $id): Response|JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);
        $call = $this->participantCall($request, $id);

        if ($call === null) {
            return $this->notParticipant();
        }

        $call->decline($data['reason'] ?? 'declined', Participant::from($request->user()));

        return response()->noContent();
    }

    /**
     * Decline from the notification itself, with no page open and no session:
     * the Beam token the push carried is the proof. A worker cannot send a CSRF
     * token, so this route is outside the `web` group — see routes/sessionless.php.
     */
    public function declineFromNotification(Request $request, string $id): Response|JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);

        /** @var Token $token */
        $token = $request->attributes->get(ValidateBeamToken::ATTRIBUTE);
        $snapshot = $this->registry->get($id);

        if ($token->callId !== $id || $token->role !== 'callee') {
            return $this->notParticipant();
        }

        // The ring is already over — answered on another device, cancelled, or
        // timed out. Nothing to decline, and nothing to complain about either.
        if ($snapshot === null || ! isset($snapshot['callee']) || ! empty($snapshot['answeredAt'])) {
            return response()->noContent();
        }

        $call = Call::fromArray($snapshot);

        if (! $call->callee->is($token->userId)) {
            return $this->notParticipant();
        }

        $call->decline($data['reason'] ?? 'declined', $call->callee);

        return response()->noContent();
    }

    /** A call: hang up for both. A room: this participant leaves; the room stays open for the others. */
    public function end(Request $request, string $id): Response|JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);

        if (($room = $this->participantRoom($request, $id)) !== null) {
            $room->leave($request->user());

            return response()->noContent();
        }

        $call = $this->participantCall($request, $id);

        if ($call === null) {
            return $this->notParticipant();
        }

        $call->end($data['reason'] ?? 'hangup', Participant::from($request->user()));

        return response()->noContent();
    }

    /** The browser timeout driver: the caller's page says nobody picked up. Only while still ringing. */
    public function timeout(Request $request, string $id): Response|JsonResponse
    {
        $call = $this->participantCall($request, $id);

        if ($call === null || ! $call->caller->is($request->user())) {
            return $this->notParticipant();
        }

        $this->beam->sweeper()->expireIfRinging($id);

        return response()->noContent();
    }

    /** A data-only connection to another user, for a file outside a call. The beam.call gate guards it like any ring. */
    public function transfer(Request $request): JsonResponse
    {
        $data = $request->validate(['to' => ['required']]);
        $provider = config('auth.providers.users.model');
        $to = is_string($provider) && class_exists($provider) ? $provider::find($data['to']) : null;

        if ($to === null) {
            return response()->json(['message' => 'No such user.', 'reason' => 'NOT_FOUND'], 404);
        }

        try {
            $call = $this->beam->call($to)->from($request->user())->files()->timeout(60)->ring();
        } catch (BeamException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason()], $e->errorCode === ErrorCode::Forbidden ? 403 : 422);
        }

        return response()->json(['callId' => $call->id, 'token' => $call->token]);
    }

    /** The channel of the live call or room this user is a participant of, or null. */
    private function participantChannel(Request $request, string $id): ?string
    {
        $snapshot = $this->registry->get($id);

        if ($snapshot === null) {
            return null;
        }

        $channel = isset($snapshot['callee']) ? Channels::call($id) : Channels::room($id);

        return $this->registry->isParticipant($channel, $request->user()->getAuthIdentifier()) ? $channel : null;
    }

    private function participantRoom(Request $request, string $id): ?Room
    {
        $snapshot = $this->registry->get($id);

        if ($snapshot === null || isset($snapshot['callee']) || ! isset($snapshot['name'])) {
            return null;
        }

        return $this->registry->isParticipant(Channels::room($id), $request->user()->getAuthIdentifier()) ? Room::fromArray($snapshot) : null;
    }

    private function participantCall(Request $request, string $id): ?Call
    {
        $snapshot = $this->registry->get($id);

        if ($snapshot === null || ! isset($snapshot['callee'])) {
            return null;
        }

        $call = Call::fromArray($snapshot);

        return $call->isParticipant($request->user()) && $this->registry->isParticipant($call->channel(), $request->user()->getAuthIdentifier())
            ? $call
            : null;
    }

    private function notParticipant(): JsonResponse
    {
        return response()->json(['message' => 'You are not a participant of that call.', 'reason' => 'FORBIDDEN'], 403);
    }
}
