<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared("ALTER TABLE school.work_logs ADD COLUMN rejected_at timestamptz,
            ADD COLUMN review_reason text,
            ADD CONSTRAINT work_log_review CHECK (validated_at IS NULL OR rejected_at IS NULL);
        ALTER TABLE school.disbursements ADD COLUMN method text NOT NULL DEFAULT 'cash' CHECK(method IN ('cash','bank','ccp','card')),
            ADD COLUMN reference text;
        DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='school_runtime') THEN
            GRANT UPDATE(minutes,rejected_at,review_reason) ON school.work_logs TO school_runtime;
        END IF; END \$\$;");
    }
    public function down(): void
    {
        DB::unprepared('ALTER TABLE school.work_logs DROP CONSTRAINT work_log_review, DROP COLUMN rejected_at, DROP COLUMN review_reason;
            ALTER TABLE school.disbursements DROP COLUMN method, DROP COLUMN reference;');
    }
};
