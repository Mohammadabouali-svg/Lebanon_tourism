<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The admin audit log (Exercise 12, docs/decisions.md D25).
|
|   audit_events - one row per admin action: who (actor), what (action),
|                  on what (subject), and which fields changed (changes).
|
| - actor_id: FK RESTRICT. Users are never deleted (invariant 21), and an
|   event whose author could vanish would be worthless.
| - subject_type / subject_id: polymorphic, values from the morph map
|   ('user', 'tour'), deliberately WITHOUT a foreign key. Operators may
|   delete a tour that has no bookings; a FK would either block that
|   (RESTRICT) or erase which tour the event was about (SET NULL). The row
|   keeps the id, and the admin page shows "deleted".
| - changes: jsonb with only the fields the action changed, e.g.
|   {"status": {"from": "pending", "to": "approved"}}, or a pointer to the
|   ledger for wallet credits. No names, e-mails or free text are copied:
|   the page joins the current values, so personal data lives in one place.
| - created_at only: an event is never edited.
|
| Append-only is enforced by the database too: triggers refuse UPDATE,
| DELETE and TRUNCATE. That stops a bug or a careless tinker session; it
| does not stop the database owner, who can drop the trigger (D25).
|
| The action list is spelled out here instead of read from the PHP enum:
| a migration must produce the same schema whenever it runs, even after
| the enum gains cases (those get their own migration).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 40);
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id');
            $table->jsonb('changes')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            // The admin page lists newest first (ORDER BY id DESC) and filters
            // by one of these; each index ends with id so the sort is free.
            $table->index(['actor_id', 'id']);
            $table->index(['action', 'id']);
            $table->index(['subject_type', 'subject_id', 'id']);
        });

        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT audit_events_action_valid CHECK (action IN (
            'admin.created', 'operator.approved', 'operator.suspended', 'tour.status_changed',
            'user.suspended', 'user.unsuspended', 'wallet.credited'
        ))");
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT audit_events_subject_type_valid CHECK (subject_type IN ('user', 'tour'))");

        // CREATE OR REPLACE: migrate:fresh drops tables but not functions.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_events_refuse_change() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit_events is append-only: % is not allowed', TG_OP;
            END;
            $$;

            CREATE TRIGGER audit_events_append_only
                BEFORE UPDATE OR DELETE ON audit_events
                FOR EACH ROW EXECUTE FUNCTION audit_events_refuse_change();

            CREATE TRIGGER audit_events_no_truncate
                BEFORE TRUNCATE ON audit_events
                FOR EACH STATEMENT EXECUTE FUNCTION audit_events_refuse_change();
            SQL);
    }

    public function down(): void
    {
        // Dropping the table drops its triggers; DROP is not refused by them.
        Schema::dropIfExists('audit_events');
        DB::statement('DROP FUNCTION IF EXISTS audit_events_refuse_change()');
    }
};
