<?php

namespace Osiset\ShopifyApp\Test\Objects\Values;

use Assert\AssertionFailedException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Osiset\ShopifyApp\Contracts\Objects\Values\SessionId as SessionIdValue;
use Osiset\ShopifyApp\Contracts\Objects\Values\ShopDomain as ShopDomainValue;
use Osiset\ShopifyApp\Objects\Values\SessionToken;
use Osiset\ShopifyApp\Test\TestCase;
use Osiset\ShopifyApp\Util;

class SessionTokenTest extends TestCase
{
    public function testShouldProcessForValidCheckoutExtensionToken(): void
    {
        $now = Carbon::now()->unix();
        $this->tokenDefaults = [
            'dest' => 'shop-name.myshopify.com',
            'aud' => Util::getShopifyConfig('api_key'),
            'exp' => $now + 60,
            'nbf' => $now,
            'iat' => $now,
            'jti' => '00000000-0000-0000-0000-000000000000',
        ];

        $token = $this->buildToken();
        $st = SessionToken::fromNative($token);

        $this->assertInstanceOf(ShopDomainValue::class, $st->getShopDomain());
        $this->assertTrue(Str::contains($this->tokenDefaults['dest'], $st->getShopDomain()->toNative()));

        $this->assertInstanceOf(Carbon::class, $st->getExpiration());
        $this->assertSame($this->tokenDefaults['exp'], $st->getExpiration()->unix());

        $this->assertInstanceOf(Carbon::class, $st->getIssuedAt());
        $this->assertSame($this->tokenDefaults['iat'], $st->getIssuedAt()->unix());

        $this->assertInstanceOf(Carbon::class, $st->getNotBefore());
        $this->assertSame($this->tokenDefaults['nbf'], $st->getNotBefore()->unix());

        $this->assertSame($this->tokenDefaults['dest'], $st->getDestination());
        $this->assertSame($this->tokenDefaults['aud'], $st->getAudience());
        $this->assertSame($this->tokenDefaults['jti'], $st->getTokenId());

        $this->assertInstanceOf(SessionIdValue::class, $st->getSessionId());
        $this->assertSame('', $st->getSessionId()->toNative());

        $this->assertSame('', $st->getIssuer());
        $this->assertSame('', $st->getSubject());
    }

    public function testShouldProcessForValidToken(): void
    {
        $token = $this->buildToken();
        $st = SessionToken::fromNative($token);

        $this->assertInstanceOf(ShopDomainValue::class, $st->getShopDomain());
        $this->assertTrue(Str::contains($this->tokenDefaults['dest'], $st->getShopDomain()->toNative()));

        $this->assertInstanceOf(SessionIdValue::class, $st->getSessionId());
        $this->assertSame($this->tokenDefaults['sid'], $st->getSessionId()->toNative());

        $this->assertInstanceOf(Carbon::class, $st->getExpiration());
        $this->assertSame($this->tokenDefaults['exp'], $st->getExpiration()->unix());

        $this->assertInstanceOf(Carbon::class, $st->getIssuedAt());
        $this->assertSame($this->tokenDefaults['iat'], $st->getIssuedAt()->unix());

        $this->assertInstanceOf(Carbon::class, $st->getNotBefore());
        $this->assertSame($this->tokenDefaults['nbf'], $st->getNotBefore()->unix());

        $this->assertSame($this->tokenDefaults['iss'], $st->getIssuer());
        $this->assertSame($this->tokenDefaults['dest'], $st->getDestination());
        $this->assertSame($this->tokenDefaults['aud'], $st->getAudience());
        $this->assertSame($this->tokenDefaults['sub'], $st->getSubject());
        $this->assertSame($this->tokenDefaults['jti'], $st->getTokenId());
    }

    public function testShouldProcessForExpiredTokenStillInLeewayPeriod(): void
    {
        $now = Carbon::now();
        $token = $this->buildToken(['exp' => (new Carbon($now))->subSeconds(SessionToken::LEEWAY_SECONDS - 2)]);
        $st = SessionToken::fromNative($token);

        $this->assertInstanceOf(Carbon::class, $st->getLeewayExpiration());
        $this->assertTrue($now->unix() < $st->getLeewayExpiration()->unix());
        $this->assertTrue($st->getLeewayExpiration()->unix() - $now->unix() < SessionToken::LEEWAY_SECONDS);
    }

    public function testShouldThrowExceptionForExpiredTokenOutOfLeewayPeriod(): void
    {
        $this->expectException(AssertionFailedException::class);

        $token = $this->buildToken(['exp' => Carbon::now()->subSeconds(SessionToken::LEEWAY_SECONDS + 2)]);
        SessionToken::fromNative($token);
    }

    public function testShouldProcessForNotBeforeTokenStillInLeewayPeriod(): void
    {
        $now = Carbon::now();
        $token = $this->buildToken(['nbf' => (new Carbon($now))->addSeconds(SessionToken::LEEWAY_SECONDS - 2)]);
        $st = SessionToken::fromNative($token);

        $this->assertInstanceOf(Carbon::class, $st->getLeewayNotBefore());
        $this->assertTrue($now->unix() > $st->getLeewayNotBefore()->unix());
        $this->assertTrue($st->getLeewayNotBefore()->unix() - $now->unix() < SessionToken::LEEWAY_SECONDS);
    }

    public function testShouldThrowExceptionForNotBeforeTokenOutOfLeewayPeriod(): void
    {
        $this->expectException(AssertionFailedException::class);

        $token = $this->buildToken(['nbf' => Carbon::now()->addSeconds(SessionToken::LEEWAY_SECONDS + 2)]);
        SessionToken::fromNative($token);
    }

    public function testShouldProcessForIssuedAtTokenStillInLeewayPeriod(): void
    {
        $now = Carbon::now();
        $token = $this->buildToken(['iat' => (new Carbon($now))->addSeconds(SessionToken::LEEWAY_SECONDS - 2)]);
        $st = SessionToken::fromNative($token);

        $this->assertInstanceOf(Carbon::class, $st->getLeewayIssuedAt());
        $this->assertTrue($now->unix() > $st->getLeewayIssuedAt()->unix());
        $this->assertTrue($st->getLeewayIssuedAt()->unix() - $now->unix() < SessionToken::LEEWAY_SECONDS);
    }

    public function testShouldThrowExceptionForIssuedAtTokenOutOfLeewayPeriod(): void
    {
        $this->expectException(AssertionFailedException::class);

        $token = $this->buildToken(['iat' => Carbon::now()->addSeconds(SessionToken::LEEWAY_SECONDS + 2)]);
        SessionToken::fromNative($token);
    }

    public function testShouldThrowExceptionForMalformedToken(): void
    {
        $this->expectException(AssertionFailedException::class);

        $token = $this->buildToken().'OOPS';
        SessionToken::fromNative($token);
    }

    public function testShouldThrowExceptionForInvalidToken(): void
    {
        $this->expectException(AssertionFailedException::class);

        $token = $this->buildToken(['iss' => 'someone-else.myshopify.com/admin']);
        SessionToken::fromNative($token);
    }

    public function testShouldThrowExceptionForExpiredToken(): void
    {
        $this->expectException(AssertionFailedException::class);

        $token = $this->buildToken(['exp' => Carbon::now()->subDay()]);
        SessionToken::fromNative($token);
    }
}
