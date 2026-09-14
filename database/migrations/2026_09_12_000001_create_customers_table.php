<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');

            /*
             * The unique index is load bearing: it is what lets the repository
             * use a single atomic "INSERT ... ON CONFLICT (email) DO UPDATE"
             * instead of a read-then-write, which would race under concurrency.
             */
            $table->string('email')->unique();

            $table->string('phone', 32)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
