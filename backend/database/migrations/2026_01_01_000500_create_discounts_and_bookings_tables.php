<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            // Stored upper-case (see Discount::code()), so codes are case-insensitive.
            $table->string('code', 40)->unique();
            $table->string('description')->nullable();
            // fixed: `value` is an amount in cents. percent: `value` is 1-100.
            $table->string('type', 10);
            $table->unsignedInteger('value');
            // null = unlimited.
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('redemptions_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE discounts ADD CONSTRAINT discounts_type_valid CHECK (type IN ('fixed', 'percent'))");
        DB::statement("ALTER TABLE discounts ADD CONSTRAINT discounts_percent_range CHECK (type <> 'percent' OR value BETWEEN 1 AND 100)");
        DB::statement('ALTER TABLE discounts ADD CONSTRAINT discounts_within_limit CHECK (max_redemptions IS NULL OR redemptions_count <= max_redemptions)');

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            // A short human-friendly code for e-mails and support calls.
            $table->string('reference', 20)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('tour_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('spots');

            // A *snapshot* of the price at booking time. If the operator
            // changes the tour price next week, this booking must not change.
            $table->unsignedInteger('unit_price_cents');
            $table->unsignedInteger('subtotal_cents');
            $table->foreignId('discount_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('total_cents');

            $table->string('payment_method', 10);   // wallet | card
            $table->string('payment_reference')->nullable(); // id from the payment gateway
            $table->string('status', 20);           // pending | confirmed | cancelled | failed
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['tour_id', 'status']);
        });

        DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_spots_positive CHECK (spots > 0)');
        DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_totals_consistent CHECK (subtotal_cents = unit_price_cents * spots AND total_cents = subtotal_cents - discount_cents)');
        DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_status_valid CHECK (status IN ('pending', 'confirmed', 'cancelled', 'failed'))");
        DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_payment_method_valid CHECK (payment_method IN ('wallet', 'card'))");

        // A partial unique index: each user may redeem a given discount code
        // once, but a cancelled or failed booking does not count as a use.
        DB::statement("CREATE UNIQUE INDEX bookings_one_discount_per_user ON bookings (user_id, discount_id) WHERE discount_id IS NOT NULL AND status IN ('pending', 'confirmed')");
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('discounts');
    }
};
