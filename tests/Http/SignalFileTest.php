<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use RealRashid\Beam\Broadcasts\IncomingCall;
use RealRashid\Beam\Broadcasts\Signal;
use RealRashid\Beam\Events\FileAccepted;
use RealRashid\Beam\Events\FileDeclined;
use RealRashid\Beam\Events\FileOffered;
use RealRashid\Beam\Events\FileTransferred;
use RealRashid\Beam\Facades\Beam;

beforeEach(function () {
    Event::fake([Signal::class, IncomingCall::class, FileOffered::class, FileAccepted::class, FileDeclined::class, FileTransferred::class]);
    $this->agent = makeUser('Agent');
    $this->customer = makeUser('Customer');
    $this->call = Beam::call($this->customer)->from($this->agent)->ring();
    $this->agentToken = unpackToken($this->call->token)['token'];
    $incoming = Event::dispatched(IncomingCall::class)->first()[0];
    $this->customerToken = unpackToken($incoming->calleeToken)['token'];
});

$post = fn ($test, $user, string $token, array $message) => $test->actingAs($user)->postJson('/beam/signal', ['channel' => $test->call->channel(), 'message' => $message], ['X-Beam-Token' => $token]);

it('relays the file control messages and tells the application who offered what to whom', function () use ($post) {
    $post($this, $this->agent, $this->agentToken, ['type' => 'file-offer', 'transferId' => 'xfer_1', 'name' => 'export.csv', 'size' => 1024, 'mime' => 'text/csv'])->assertNoContent();
    Event::assertDispatched(FileOffered::class, fn (FileOffered $e) => $e->transferId === 'xfer_1' && $e->from->id === $this->agent->id && $e->to->id === $this->customer->id && $e->file->toArray() === ['name' => 'export.csv', 'size' => 1024, 'mime' => 'text/csv']
        && $e->file->forHumans() === '1 KB');

    $post($this, $this->customer, $this->customerToken, ['type' => 'file-accept', 'transferId' => 'xfer_1'])->assertNoContent();
    Event::assertDispatched(FileAccepted::class, fn (FileAccepted $e) => $e->from->id === $this->agent->id && $e->to->id === $this->customer->id && $e->file->name === 'export.csv');

    $post($this, $this->customer, $this->customerToken, ['type' => 'file-done', 'transferId' => 'xfer_1', 'sha256' => str_repeat('a', 64)])->assertNoContent();
    Event::assertDispatched(FileTransferred::class, fn (FileTransferred $e) => $e->transferId === 'xfer_1' && $e->file->size === 1024);
    Event::assertDispatchedTimes(Signal::class, 3);
});

it('reports a decline', function () use ($post) {
    $post($this, $this->agent, $this->agentToken, ['type' => 'file-offer', 'transferId' => 'xfer_2', 'name' => 'a', 'size' => 1, 'mime' => 'x'])->assertNoContent();
    $post($this, $this->customer, $this->customerToken, ['type' => 'file-decline', 'transferId' => 'xfer_2'])->assertNoContent();

    Event::assertDispatched(FileDeclined::class, fn (FileDeclined $e) => $e->transferId === 'xfer_2');
    Event::assertNotDispatched(FileTransferred::class);
});

it('refuses an offer over the configured size before relaying it', function () use ($post) {
    config()->set('beam.transfer.max_bytes', 100);

    $post($this, $this->agent, $this->agentToken, ['type' => 'file-offer', 'transferId' => 'xfer_3', 'name' => 'big', 'size' => 101, 'mime' => 'x'])
        ->assertStatus(413)->assertJsonPath('reason', 'TOO_LARGE');

    Event::assertNotDispatched(Signal::class);
    Event::assertNotDispatched(FileOffered::class);
});

it('validates the shape of every file message', function () use ($post) {
    $post($this, $this->agent, $this->agentToken, ['type' => 'file-offer', 'transferId' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['message.name', 'message.size', 'message.mime']);
    $post($this, $this->agent, $this->agentToken, ['type' => 'file-done', 'transferId' => 'x', 'sha256' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('message.sha256');
    $post($this, $this->agent, $this->agentToken, ['type' => 'file-accept'])->assertUnprocessable()->assertJsonValidationErrors('message.transferId');
});

it('POST /transfers rings a data-only connection guarded by the gate, and sends no push for it', function () {
    Notification::fake();
    config()->set('beam.push.vapid', ['public' => 'PUB', 'private' => 'PRIV']);

    $response = $this->actingAs($this->agent)->postJson('/beam/transfers', ['to' => $this->customer->id])->assertOk();

    expect(unpackToken($response->json('token'))['claims']['meta']['media'])->toBe('none');
    Event::assertDispatched(IncomingCall::class, fn (IncomingCall $e) => $e->call->media === 'none' && $e->broadcastWith()['media'] === 'none');
    Notification::assertNothingSent();

    $this->actingAs($this->agent)->postJson('/beam/transfers', ['to' => 999999])->assertNotFound();

    Gate::define('beam.call', fn () => false);
    $this->actingAs($this->agent)->postJson('/beam/transfers', ['to' => $this->customer->id])->assertForbidden();
});
