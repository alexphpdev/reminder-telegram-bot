<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weather_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weather_location_id')->constrained('weather_locations')->cascadeOnDelete();
            $table->string('provider')->default('open-meteo')->index();
            $table->string('response_status')->default('success')->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamp('requested_at')->index();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weather_history');
    }
};
