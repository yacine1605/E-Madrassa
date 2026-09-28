<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE school.assessments ADD COLUMN kind text CHECK (kind IN ('devoir','examen'))");
    }
    public function down(): void
    {
        DB::statement('ALTER TABLE school.assessments DROP COLUMN kind');
    }
};
