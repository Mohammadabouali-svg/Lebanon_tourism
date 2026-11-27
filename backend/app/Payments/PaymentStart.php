<?php

namespace App\Payments;

/*
| What a gateway answers when a card payment is started.
|
| Before Exercise 9 a payment was a single call that came back "approved" or
| "declined". Stripe Checkout works differently: the traveler pays on
| Stripe's page and the answer arrives later, in a webhook. So a gateway can
| now answer in four ways:
|
|   paid         the money is taken, confirm now          (fake gateway)
|   declined     the card was refused, release the spots  (fake gateway)
|   redirect     send the traveler to `checkoutUrl`; the  (Stripe)
|                webhook settles the booking later
|   unavailable  the provider could not be reached; we    (Stripe)
|                know nothing was paid, release the spots
|
| A small immutable "value object" with named constructors, so an invalid
| combination (a redirect without a URL, ...) cannot be built.
*/
final readonly class PaymentStart
{
    private function __construct(
        public PaymentOutcome $outcome,
        public ?string $reference = null,
        public ?string $sessionId = null,
        public ?string $checkoutUrl = null,
        public ?string $failureMessage = null,
    ) {}

    public static function paid(string $reference): self
    {
        return new self(PaymentOutcome::Paid, reference: $reference);
    }

    public static function declined(string $message): self
    {
        return new self(PaymentOutcome::Declined, failureMessage: $message);
    }

    public static function redirect(string $sessionId, string $checkoutUrl): self
    {
        return new self(PaymentOutcome::Redirect, sessionId: $sessionId, checkoutUrl: $checkoutUrl);
    }

    public static function unavailable(): self
    {
        return new self(PaymentOutcome::Unavailable);
    }
}
