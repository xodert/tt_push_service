<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notification_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_id')->unique()->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key');

            // Idempotency keys are scoped per user: two clients may use the same key.
            $table->unique(['user_id', 'idempotency_key']);
            $table->enum('channel', ['sms', 'email']);
            $table->enum('type', ['high', 'low']);
            $table->text('message');
            // Per-status counts are aggregated on read from `notifications`
            // (index-only scan on (batch_id, status)) — denormalized counters
            // would drift on crashes and serialize workers on a hot row.
            $table->unsignedInteger('total_count')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_batches');
    }
};
