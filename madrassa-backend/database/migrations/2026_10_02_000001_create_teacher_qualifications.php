<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE school.teacher_qualifications (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 teacher_id uuid NOT NULL,
 level_id uuid NOT NULL,
 subject_id uuid NOT NULL,
 UNIQUE(tenant_id,teacher_id,level_id,subject_id),
 FOREIGN KEY(tenant_id,teacher_id) REFERENCES school.staff(tenant_id,id),
 FOREIGN KEY(tenant_id,level_id) REFERENCES school.levels(tenant_id,id),
 FOREIGN KEY(tenant_id,subject_id) REFERENCES school.subjects(tenant_id,id)
);
CREATE INDEX teacher_qualifications_lookup ON school.teacher_qualifications(tenant_id,level_id,subject_id);
SQL);
        // Require the privileged migration role; fail instead of silently skipping RLS-hidden rows.
        DB::statement('SET LOCAL row_security = off');
            DB::statement(<<<'SQL'
INSERT INTO school.teacher_qualifications(tenant_id,teacher_id,level_id,subject_id)
SELECT DISTINCT a.tenant_id,a.teacher_id,o.level_id,a.subject_id
FROM school.teacher_assignments a
JOIN school.cohorts c ON c.tenant_id=a.tenant_id AND c.id=a.cohort_id
JOIN school.offerings o ON o.tenant_id=c.tenant_id AND o.id=c.offering_id
ON CONFLICT DO NOTHING
SQL);
        DB::statement('SET LOCAL row_security = on');
        DB::unprepared(<<<'SQL'
ALTER TABLE school.teacher_qualifications ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.teacher_qualifications FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.teacher_qualifications USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));
SQL);
        if (DB::table('pg_roles')->where('rolname', 'school_runtime')->exists()) {
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON school.teacher_qualifications TO school_runtime');
        }
    }

    public function down(): void { DB::statement('DROP TABLE school.teacher_qualifications'); }
};
