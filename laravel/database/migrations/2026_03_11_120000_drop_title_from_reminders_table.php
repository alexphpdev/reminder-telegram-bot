<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('reminders', 'title')) {
            return;
        }

        Schema::table('reminders', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('reminders', 'title')) {
            return;
        }

        Schema::table('reminders', function (Blueprint $table) {
            $table->string('title')->default('');
        });
    }
};
