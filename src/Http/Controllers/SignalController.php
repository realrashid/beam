<?php

declare(strict_types=1);

namespace RealRashid\Beam\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use RealRashid\Beam\Contracts\Signaler;
use RealRashid\Beam\Data\File;
use RealRashid\Beam\Data\Participant;
use RealRashid\Beam\Data\Token;
use RealRashid\Beam\Events\FileAccepted;
use RealRashid\Beam\Events\FileDeclined;
use RealRashid\Beam\Events\FileOffered;
use RealRashid\Beam\Events\FileTransferred;
use RealRashid\Beam\Http\Middleware\ValidateBeamToken;
use RealRashid\Beam\Signaling\Channels;
use RealRashid\Beam\Signaling\Envelope;
use RealRashid\Beam\Support\CallRegistry;

/**
 * The controller transport: browser → here → Broadcasting → the other browser.
 *
 * Two proofs before anything is relayed, both required: the token names this
 * channel (this browser was handed this call), and the registry lists the
 * user as a participant (the call is live and they are still in it).
 * `from` is set here from the authenticated user, never taken from the body.
 */
final class SignalController
{
    public function __construct(
        private readonly Signaler $signaler,
        private readonly CallRegistry $registry,
    ) {}

    public function __invoke(Request $request): Response|JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', 'string', 'max:200', function (string $attribute, mixed $value, \Closure $fail) {
                if (! is_string($value) || ! Channels::isBeam($value)) {
                    $fail('The channel must be a Beam channel.');
                }
            }],
            'message' => ['required', 'array'],
            'message.type' => ['required', Rule::in(['hello', 'offer', 'answer', 'ice', 'bye', 'file-offer', 'file-accept', 'file-decline', 'file-cancel', 'file-done'])],
            'message.transferId' => ['required_if:message.type,file-offer,file-accept,file-decline,file-cancel,file-done', 'string', 'max:64'],
            'message.name' => ['required_if:message.type,file-offer', 'string', 'max:255'],
            'message.size' => ['required_if:message.type,file-offer', 'integer', 'min:0'],
            'message.mime' => ['required_if:message.type,file-offer', 'string', 'max:255'],
            'message.sha256' => ['required_if:message.type,file-done', 'string', 'size:64'],
            'message.sdp' => ['required_if:message.type,offer,answer', 'array'],
            'message.sdp.type' => ['required_with:message.sdp', Rule::in(['offer', 'answer', 'pranswer', 'rollback'])],
            'message.sdp.sdp' => ['nullable', 'string', 'max:65536'],
            'message.candidates' => ['required_if:message.type,ice', 'array', 'max:200'],
            'message.candidates.*' => ['array'],
            'message.reason' => ['required_if:message.type,bye', 'string', 'max:200'],
        ]);

        /** @var Token $token */
        $token = $request->attributes->get(ValidateBeamToken::ATTRIBUTE);
        $userId = $request->user()?->getAuthIdentifier();

        if (! $token->isFor($data['channel'])) {
            return response()->json(['message' => 'This Beam token is not for that channel.', 'reason' => 'FORBIDDEN'], 403);
        }

        if (! $this->registry->isParticipant($data['channel'], $userId)) {
            return response()->json(['message' => 'You are not a participant of that call.', 'reason' => 'FORBIDDEN'], 403);
        }

        $message = $data['message'];
        $message['from'] = $userId;

        if ($message['type'] === 'file-offer' && (int) $message['size'] > (int) config('beam.transfer.max_bytes')) {
            return response()->json(['message' => 'That file is larger than this server allows.', 'reason' => 'TOO_LARGE'], 413);
        }

        // Envelope::forBroadcast gzips anything the broadcaster would refuse for
        // its size — a renegotiation offer with two video sections is over
        // Pusher's and Reverb's 10 KB event cap on its own.
        $this->signaler->relay($data['channel'], Envelope::forBroadcast($message));
        $this->fileEvent($data['channel'], $message, $request);

        return response()->noContent();
    }

    /**
     * The application hears about transfers; the bytes never touch it. The
     * offer names the file once; accept, decline and done carry it again from
     * what the registry remembered.
     *
     * @param  array<string, mixed>  $message
     */
    private function fileEvent(string $channel, array $message, Request $request): void
    {
        $event = match ($message['type']) {
            'file-offer' => FileOffered::class,
            'file-accept' => FileAccepted::class,
            'file-decline' => FileDeclined::class,
            'file-done' => FileTransferred::class,
            default => null,
        };

        if ($event === null) {
            return;
        }

        $me = Participant::from($request->user());

        if ($message['type'] === 'file-offer') {
            $meta = [
                'name' => (string) $message['name'], 'size' => (int) $message['size'], 'mime' => (string) $message['mime'],
                'from' => $me->toArray(),
                'to' => $this->otherParticipant($channel, $me->id) ?? ['id' => '', 'name' => ''],
            ];
            $this->registry->rememberTransfer((string) $message['transferId'], $meta);
        } else {
            $meta = $this->registry->transfer((string) $message['transferId']);
        }

        if ($meta === null) {
            return;
        }

        event(new $event(
            (string) $message['transferId'],
            Participant::from($meta['from']),
            Participant::from($meta['to']),
            File::from($meta),
        ));

        if (in_array($message['type'], ['file-decline', 'file-done'], true)) {
            $this->registry->forgetTransfer((string) $message['transferId']);
        }
    }

    /**
     * @return array{id: int|string, name: string}|null
     */
    private function otherParticipant(string $channel, int|string $me): ?array
    {
        $id = str_replace([Channels::call(''), Channels::room('')], '', $channel);
        $snapshot = $this->registry->get($id) ?? [];

        foreach ([$snapshot['caller'] ?? null, $snapshot['callee'] ?? null] as $p) {
            if (is_array($p) && (string) $p['id'] !== (string) $me) {
                return ['id' => $p['id'], 'name' => (string) $p['name']];
            }
        }

        foreach (array_keys($this->registry->participants($id)) as $userId) {
            if ((string) $userId !== (string) $me) {
                return ['id' => $userId, 'name' => (string) $userId];
            }
        }

        return null;
    }
}
