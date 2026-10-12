<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/021_pupils_and_enrolments.sql')));
    }

    public function down(): void
    {
        // The journal as it was before: 019's.
        preg_match('/CREATE (?:OR REPLACE )?FUNCTION school\.audit\(\).*?\$\$;/s', file_get_contents(database_path('schema/019_signed_context.sql')), $previous);
        DB::unprepared('CREATE OR REPLACE '.preg_replace('/^CREATE (OR REPLACE )?/', '', $previous[0]));
        preg_match('/CREATE OR REPLACE FUNCTION school\.guard_role_assignment\(\).*?\$\$;/s', file_get_contents(database_path('schema/008_referential.sql')), $assignment);
        DB::unprepared($assignment[0]);
        DB::unprepared(<<<'SQL'
DROP POLICY core_resource_reader ON school.audit_log;
DELETE FROM platform.audit_domain WHERE object_type IN ('student', 'guardian', 'student_guardian', 'program', 'study_group', 'enrollment', 'group_membership');
DROP FUNCTION school.enroll(uuid, uuid, uuid), school.move_to_group(uuid, uuid);
DROP TABLE school.group_membership, school.enrollment_status_history, school.enrollment, school.study_group, school.program_price, school.program,
  school.student_guardian, school.guardian, school.matricule_counter, school.student;
DROP FUNCTION school.check_group_membership(), school.guard_group_membership(), school.follow_enrollment(), school.check_enrollment(),
  school.guard_enrollment(), school.check_group_places(), school.guard_study_group(), school.version_program_price(), school.guard_program(),
  school.guard_guardian(), school.guard_student(), school.person_name_valid(text, text, text, text), school.require(text),
  school.core_ids(text, text), school.require_core(text, text, uuid), school.next_matricule(uuid), school.group_scope_valid(uuid, uuid[]);
ALTER TABLE school.setting DISABLE TRIGGER USER;
DELETE FROM school.setting WHERE key = 'student.majority_age';
ALTER TABLE school.setting ENABLE TRIGGER USER;
DELETE FROM platform.setting_definition WHERE key = 'student.majority_age';
SQL);
    }
};
