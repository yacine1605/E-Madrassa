<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('schema/022_pupil_admission.sql')));
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION school.student_enrollment_count(uuid)');
    }
};
