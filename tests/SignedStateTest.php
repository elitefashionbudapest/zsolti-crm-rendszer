<?php

declare(strict_types=1);

namespace Tests;

use App\Support\SignedState;
use PHPUnit\Framework\TestCase;

final class SignedStateTest extends TestCase
{
    private SignedState $s;

    protected function setUp(): void
    {
        $this->s = new SignedState('teszt-titkos-kulcs');
    }

    public function testRoundTripReturnsPayload(): void
    {
        $token = $this->s->sign(['office' => 7], 600);
        $data = $this->s->verify($token);
        self::assertNotNull($data);
        self::assertSame(7, $data['office']);
    }

    public function testTamperedTokenRejected(): void
    {
        $token = $this->s->sign(['office' => 7], 600);
        self::assertNull($this->s->verify($token . 'x'));
    }

    public function testExpiredTokenRejected(): void
    {
        $token = $this->s->sign(['office' => 7], -1);
        self::assertNull($this->s->verify($token));
    }

    public function testWrongKeyRejected(): void
    {
        $token = $this->s->sign(['office' => 7], 600);
        self::assertNull((new SignedState('masik-kulcs'))->verify($token));
    }
}
