<?php

namespace Tests\Unit;

use App\Payments\StripeWebhookSignature;
use PHPUnit\Framework\TestCase;

/*
| Exercise 9: Stripe signs every webhook with a shared secret. Only a request
| whose `Stripe-Signature` header matches the body may change a booking, so
| this check is the whole security of the public webhook route.
|
| Header format (https://docs.stripe.com/webhooks#verify-manually):
|   t=<unix timestamp>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">[,v1=...]
*/
class StripeWebhookSignatureTest extends TestCase
{
    private const SECRET = 'whsec_unit_test_secret';

    private const NOW = 1_800_000_000;

    private const BODY = '{"id":"evt_1","type":"checkout.session.completed"}';

    private function header(string $body = self::BODY, int $timestamp = self::NOW, string $secret = self::SECRET): string
    {
        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }

    public function test_a_correctly_signed_body_is_accepted(): void
    {
        $this->assertTrue((new StripeWebhookSignature(self::SECRET))->verify(self::BODY, $this->header(), self::NOW));
    }

    public function test_a_body_changed_after_signing_is_rejected(): void
    {
        $tampered = str_replace('evt_1', 'evt_2', self::BODY);

        $this->assertFalse((new StripeWebhookSignature(self::SECRET))->verify($tampered, $this->header(), self::NOW));
    }

    public function test_a_signature_made_with_another_secret_is_rejected(): void
    {
        $header = $this->header(secret: 'whsec_attacker');

        $this->assertFalse((new StripeWebhookSignature(self::SECRET))->verify(self::BODY, $header, self::NOW));
    }

    public function test_an_old_signature_is_rejected_so_a_captured_request_cannot_be_replayed(): void
    {
        $header = $this->header(timestamp: self::NOW - 301);

        $this->assertFalse((new StripeWebhookSignature(self::SECRET))->verify(self::BODY, $header, self::NOW));
    }

    public function test_a_signature_within_five_minutes_is_accepted(): void
    {
        $header = $this->header(timestamp: self::NOW - 300);

        $this->assertTrue((new StripeWebhookSignature(self::SECRET))->verify(self::BODY, $header, self::NOW));
    }

    public function test_any_of_several_v1_signatures_may_match(): void
    {
        // During a secret rotation Stripe sends one v1 per active secret.
        $header = 't='.self::NOW.',v1='.str_repeat('0', 64).','.substr($this->header(), strlen('t='.self::NOW.','));

        $this->assertTrue((new StripeWebhookSignature(self::SECRET))->verify(self::BODY, $header, self::NOW));
    }

    public function test_a_missing_or_malformed_header_is_rejected(): void
    {
        $signature = new StripeWebhookSignature(self::SECRET);

        $this->assertFalse($signature->verify(self::BODY, null, self::NOW));
        $this->assertFalse($signature->verify(self::BODY, '', self::NOW));
        $this->assertFalse($signature->verify(self::BODY, 'v1='.hash_hmac('sha256', self::BODY, self::SECRET), self::NOW));
        $this->assertFalse($signature->verify(self::BODY, 't='.self::NOW, self::NOW));
    }

    public function test_nothing_is_accepted_when_no_secret_is_configured(): void
    {
        // With an empty key, anyone could compute a "valid" HMAC.
        $header = $this->header(secret: '');

        $this->assertFalse((new StripeWebhookSignature(''))->verify(self::BODY, $header, self::NOW));
    }
}
