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
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('message');
            $table->foreignId('chat_id')->nullable()->constrained('telegram_chats')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('telegram_users')->nullOnDelete();
            $table->string('mention_override')->nullable();
            $table->string('status')->default('active')->index();
            $table->string('schedule_type')->default('once');
            $table->string('cron_expression')->nullable();
            $table->unsignedInteger('interval_minutes')->nullable();
            $table->string('timezone')->default('Europe/Berlin');
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->timestamp('last_sent_at')->nullable();
            $table->boolean('ask_status')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
