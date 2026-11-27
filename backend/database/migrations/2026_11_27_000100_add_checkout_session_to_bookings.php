<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Stripe Checkout (Exercise 9, docs/decisions.md D26).
|
| A card booking paid through Stripe is `pending` until Stripe's webhook
| reports the payment. The webhook names the Checkout Session, so the
| booking stores its id to be found again, plus the Checkout URL so the
| traveler can return to an unfinished payment.
|
| - payment_session_id is unique: one session belongs to one booking, so a
|   webhook can never settle two bookings.
| - payment_reference becomes unique: one payment (Stripe PaymentIntent, or a
|   fake gateway reference) pays for one booking. A repeated webhook, or a
|   bug, cannot attach the same payment to a second booking. Postgres
|   treats NULLs as distinct, so unpaid bookings are unaffected.
| - A CHECK keeps the session columns to card bookings: a wallet booking
|   has nothing to do with Stripe.
|
| All nullable, so existing rows need no backfill.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('payment_session_id')->nullable()->unique();
            $table->text('checkout_url')->nullable();
            $table->unique('payment_reference');
        });

        DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_checkout_only_for_cards CHECK ((payment_session_id IS NULL AND checkout_url IS NULL) OR payment_method = 'card')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT bookings_checkout_only_for_cards');

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique(['payment_reference']);
            $table->dropUnique(['payment_session_id']);
            $table->dropColumn(['payment_session_id', 'checkout_url']);
        });
    }
};
