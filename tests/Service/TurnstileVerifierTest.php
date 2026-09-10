<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\TurnstileVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Covers MAIN.MD's Turnstile server-side verification requirements: a
 * missing token, an unconfigured secret key, Cloudflare's success=false, and
 * a network/API failure must all be rejected, while a genuine success=true
 * response must be accepted. postSiteverify() (the only method that touches
 * the network) is faked via an anonymous subclass — same convention as
 * tests/Service/Shipping/PostNl/PostNlRateSyncServiceTest.php's fake
 * fetcher/parser — so these tests never hit the real Cloudflare API.
 */
final class TurnstileVerifierTest extends TestCase
{
    private function verifierReturning(string $jsonResponse, ?string $secretKeyOverride = 'test-secret'): TurnstileVerifier
    {
        return new class ($jsonResponse, $secretKeyOverride) extends TurnstileVerifier {
            public function __construct(private readonly string $jsonResponse, ?string $secretKeyOverride)
            {
                parent::__construct($secretKeyOverride);
            }

            protected function postSiteverify(string $secretKey, string $token, ?string $remoteIp): string
            {
                return $this->jsonResponse;
            }
        };
    }

    private function verifierThrowing(): TurnstileVerifier
    {
        return new class ('test-secret') extends TurnstileVerifier {
            public function __construct(?string $secretKeyOverride)
            {
                parent::__construct($secretKeyOverride);
            }

            protected function postSiteverify(string $secretKey, string $token, ?string $remoteIp): string
            {
                throw new \RuntimeException('simulated network failure');
            }
        };
    }

    public function testMissingTokenIsRejectedWithoutCallingCloudflare(): void
    {
        $verifier = new class ('test-secret') extends TurnstileVerifier {
            public function __construct(?string $secretKeyOverride)
            {
                parent::__construct($secretKeyOverride);
            }

            protected function postSiteverify(string $secretKey, string $token, ?string $remoteIp): string
            {
                throw new \RuntimeException('must not be called for a missing token');
            }
        };

        $this->assertFalse($verifier->verify(null));
        $this->assertFalse($verifier->verify(''));
        $this->assertFalse($verifier->verify('   '));
    }

    public function testUnconfiguredSecretKeyFailsClosed(): void
    {
        $verifier = $this->verifierReturning(json_encode(['success' => true]), secretKeyOverride: '');

        $this->assertFalse($verifier->verify('some-token'));
    }

    public function testSuccessfulSiteverifyResponseIsAccepted(): void
    {
        $verifier = $this->verifierReturning(json_encode(['success' => true, 'action' => 'checkout']));

        $this->assertTrue($verifier->verify('valid-token'));
    }

    public function testCloudflareSuccessFalseIsRejected(): void
    {
        $verifier = $this->verifierReturning(json_encode(['success' => false, 'error-codes' => ['invalid-input-response']]));

        $this->assertFalse($verifier->verify('bad-token'));
    }

    public function testExpiredOrAlreadyUsedTokenIsRejected(): void
    {
        $verifier = $this->verifierReturning(json_encode(['success' => false, 'error-codes' => ['timeout-or-duplicate']]));

        $this->assertFalse($verifier->verify('expired-token'));
    }

    public function testUnreadableResponseIsRejected(): void
    {
        $verifier = $this->verifierReturning('not json at all');

        $this->assertFalse($verifier->verify('some-token'));
    }

    public function testNetworkOrApiFailureFailsSafely(): void
    {
        $verifier = $this->verifierThrowing();

        $this->assertFalse($verifier->verify('some-token'));
    }
}
