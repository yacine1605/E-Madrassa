<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE school.sessions ADD COLUMN activity text NOT NULL DEFAULT 'course' CHECK(activity IN ('course','support'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE school.sessions DROP COLUMN activity');
    }
};
