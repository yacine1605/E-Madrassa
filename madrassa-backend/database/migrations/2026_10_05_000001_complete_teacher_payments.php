<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/010_teacher_payments.sql')));
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE school.work_logs DROP CONSTRAINT work_log_review, DROP COLUMN rejected_at, DROP COLUMN review_reason;
            ALTER TABLE school.disbursements DROP COLUMN method, DROP COLUMN reference;');
    }
};
