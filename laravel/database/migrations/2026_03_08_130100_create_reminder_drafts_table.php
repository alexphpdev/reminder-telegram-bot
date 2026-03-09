<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chat_id')->constrained('telegram_chats')->cascadeOnDelete();
            $table->foreignId('initiator_user_id')->constrained('telegram_users')->cascadeOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('telegram_users')->nullOnDelete();
            $table->string('status')->default('active')->index();
            $table->string('step')->default('awaiting_text')->index();
            $table->json('payload')->nullable();
            $table->json('tracked_message_ids')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['chat_id', 'initiator_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_drafts');
    }
};
