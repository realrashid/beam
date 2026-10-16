<?php

declare(strict_types=1);

namespace RealRashid\Beam\Testing;

use Illuminate\Contracts\Auth\Authenticatable;
use PHPUnit\Framework\Assert;
use RealRashid\Beam\Call;

final class PendingRingAssertion
{
    /**
     * @param  array<int, Call>  $calls
     */
    public function __construct(private array $calls) {}

    public function from(Authenticatable|int|string $caller): self
    {
        return $this->where(fn (Call $c) => $c->caller->is($caller), 'from the given caller');
    }

    public function withVideo(): self
    {
        return $this->where(fn (Call $c) => $c->media === 'video', 'with video');
    }

    public function withAudio(): self
    {
        return $this->where(fn (Call $c) => $c->media === 'audio', 'audio-only');
    }

    public function about(string $about): self
    {
        return $this->where(fn (Call $c) => $c->about === $about, "about [{$about}]");
    }

    public function timeout(int $seconds): self
    {
        return $this->where(fn (Call $c) => $c->timeout === $seconds, "with a {$seconds}s timeout");
    }

    private function where(callable $test, string $description): self
    {
        $this->calls = array_values(array_filter($this->calls, $test));
        Assert::assertNotEmpty($this->calls, "A call was rung, but none {$description}.");

        return $this;
    }
}
