<?php

namespace App\Payments;

/*
| Checks that a webhook request really comes from Stripe.
|
| The webhook route is public: Stripe has no session or CSRF token, so
| anyone on the internet can POST to it. Without this check, a traveler could
| send a forged "checkout.session.completed" for their own pending booking
| and get the tour for free. This check is the endpoint's only protection.
|
| How Stripe signs (https://docs.stripe.com/webhooks#verify-manually):
|   Stripe-Signature: t=<unix time>,v1=<signature>[,v1=<signature>...]
|   signature = hex( HMAC-SHA256( key: endpoint secret, data: "<t>.<raw body>" ) )
|
| - The *raw* body is signed. It must be checked before the JSON is decoded:
|   re-encoding decoded JSON can change spaces or key order.
| - The timestamp is inside the signature, so it cannot be altered. A request
|   older than five minutes is refused: someone who captured a genuine
|   request cannot replay it later.
| - hash_equals() compares in constant time, so the response time does not
|   reveal how many leading characters of a guess were right.
| - Several v1 values appear while a secret is being rotated; any may match.
| - An empty secret refuses everything: HMAC with an empty key is something
|   an attacker can compute too.
|
| Written by hand (about 20 lines) instead of adding the stripe/stripe-php
| SDK for it: see D26.
*/
final readonly class StripeWebhookSignature
{
    public const TOLERANCE_SECONDS = 300;

    public function __construct(
        private string $secret,
    ) {}

    public function verify(string $payload, ?string $header, int $now): bool
    {
        if ($this->secret === '' || $header === null || $header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === [] || abs($now - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
