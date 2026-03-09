<?php

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
        Schema::create('reminder_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reminder_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chat_id')->nullable()->constrained('telegram_chats')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('telegram_users')->nullOnDelete();
            $table->bigInteger('telegram_message_id')->nullable();
            $table->string('delivery_status')->default('dispatching')->index();
            $table->text('message_text');
            $table->text('error_message')->nullable();
            $table->json('response_payload')->nullable();
            $table->json('status_payload')->nullable();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reminder_deliveries');
    }
};
