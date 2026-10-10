<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/014_payroll_validation.sql')));
    }

    public function down(): void
    {
        // Refuses to run while a draft exists: earlier code treats every run as validated.
        DB::unprepared(<<<'SQL'
DO $$ BEGIN IF EXISTS (SELECT 1 FROM school.payroll_runs WHERE status='draft') THEN
  RAISE EXCEPTION 'Validate or discard every draft pay run before rolling back.';
END IF; END $$;
DROP TRIGGER payroll_expense_guard ON school.expenses;
DROP FUNCTION school.guard_payroll_expense();
DROP TRIGGER payroll_run_guard ON school.payroll_runs;
DROP FUNCTION school.guard_payroll_run();
ALTER TABLE school.payroll_runs DROP CONSTRAINT payroll_run_validation,
 DROP COLUMN status, DROP COLUMN created_by, DROP COLUMN validated_by;
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.payroll_runs FOR EACH ROW EXECUTE FUNCTION school.immutable();
SQL);
    }
};
