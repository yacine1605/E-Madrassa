<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') throw new RuntimeException('PostgreSQL 16+ requis.');
        DB::unprepared(file_get_contents(database_path('schema/001_school.sql')));
    }
    public function down(): void
    {
        throw new RuntimeException('Migration non destructive : restaurer une sauvegarde ou écrire une migration corrective explicite.');
    }
};
