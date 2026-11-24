<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| A wallet is two tables:
|   wallets             - the current balance, one row per user
|   wallet_transactions - an append-only ledger of every change
|
| The balance alone tells you *how much*, the ledger tells you *why*. When a
| customer asks "where did my $50 go?", the ledger has the answer, including
| which admin added credit and which booking spent it.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->bigInteger('balance_cents')->default(0);
            $table->timestamps();
        });

        // Last line of defence: even a buggy or concurrent update can never
        // leave a wallet below zero. Postgres rejects the write instead.
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_balance_not_negative CHECK (balance_cents >= 0)');

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->string('type', 10); // credit | debit
            $table->bigInteger('amount_cents');
            $table->bigInteger('balance_after_cents');
            $table->string('description');
            // What caused this entry, e.g. a Booking. Nullable for manual credits.
            $table->nullableMorphs('reference');
            // The admin who added manual credit, for the audit trail.
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            // Ledger rows are never edited, so there is no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['wallet_id', 'id']);
        });

        DB::statement('ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_amount_positive CHECK (amount_cents > 0)');
        DB::statement("ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_type_valid CHECK (type IN ('credit', 'debit'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
    }
};
