<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table): void {
            $table->id();

            /*
             * Customer-facing booking code, generated in PHP so that creating a
             * reservation never needs a second round trip to read it back.
             */
            $table->string('reference', 32)->unique();

            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('party_size');
            $table->timestamp('reserved_for');

            /*
             * How long this booking occupies the table. Stored per row rather
             * than derived from config at read time, so that changing the
             * configured dwell times later never rewrites history.
             */
            $table->unsignedSmallInteger('duration_minutes');

            $table->string('status', 16)->default('confirmed');

            /*
             * Which entry point created the booking: mcp, vapi or console.
             * Reservations are never updated, only inserted, so this table is
             * its own append-only audit trail.
             */
            $table->string('created_via', 16)->default('mcp');

            $table->text('notes')->nullable();
            $table->timestamps();

            /*
             * Used by `reservations:verify` and by capacity reconciliation,
             * both of which scan confirmed bookings within a date range.
             */
            $table->index(['reserved_for', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
