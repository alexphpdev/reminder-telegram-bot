<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('telegram_users', function (Blueprint $table) use ($driver): void {
            $column = $table->string('pseudonym')->nullable();

            // SQLite ignores column order; MySQL can place pseudonym before first_name.
            if ($driver === 'mysql') {
                $column->after('username');
            }
        });
    }

    public function down(): void
    {
        Schema::table('telegram_users', function (Blueprint $table): void {
            $table->dropColumn('pseudonym');
        });
    }
};
