<?php

declare(strict_types=1);

use RealRashid\Beam\Support\Redactor;

it('masks sensitive keys at any depth and leaves the rest', function () {
    $scrubbed = Redactor::scrub([
        'driver' => 'coturn',
        'ice' => [['urls' => ['turn:r'], 'username' => '1700:1', 'credential' => 'hmac-here']],
        'push' => ['vapid' => ['public' => 'pk', 'private' => 'sk']],
        'Authorization' => 'Bearer abc',
        'apiKey' => 'k',
        'about' => 'Order #1042',
    ]);

    expect($scrubbed['driver'])->toBe('coturn')
        ->and($scrubbed['ice'][0]['username'])->toBe('1700:1')
        ->and($scrubbed['ice'][0]['credential'])->toBe(Redactor::MASK)
        ->and($scrubbed['push']['vapid'])->toBe(Redactor::MASK)
        ->and($scrubbed['Authorization'])->toBe(Redactor::MASK)
        ->and($scrubbed['apiKey'])->toBe(Redactor::MASK)
        ->and($scrubbed['about'])->toBe('Order #1042');
});

it('masks secrets embedded in strings', function () {
    expect(Redactor::scrubString('turn credential=abc123 and apiKey=zzz, "token":"t0k" Bearer eyJ.x.y done'))
        ->toBe('turn credential=[redacted] and apiKey=[redacted], "token":"[redacted]" Bearer [redacted] done');
});

it('passes scalars through untouched', function () {
    expect(Redactor::scrub(42))->toBe(42)->and(Redactor::scrub(null))->toBeNull()->and(Redactor::scrub('plain'))->toBe('plain');
});
