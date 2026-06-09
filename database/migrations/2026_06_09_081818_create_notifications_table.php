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
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('notification_id')->unique()->index();
            $table->foreignId('batch_id')->constrained('notification_batches')->cascadeOnDelete();
            $table->string('recipient_id')->index();
            $table->enum('channel', ['sms', 'email']);
            $table->enum('type', ['high', 'low']);
            $table->text('message');
            $table->enum('status', ['queued', 'sent', 'delivered', 'rejected'])->default('queued')->index();
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            // Serves the batch status aggregation (COUNT ... GROUP BY status).
            $table->index(['batch_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
