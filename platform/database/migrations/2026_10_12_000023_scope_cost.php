<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/023_scope_cost.sql')));
    }

    public function down(): void
    {
        // The policies as 021 wrote them, with `= ANY`.
        DB::unprepared(<<<'SQL'
DO $$ DECLARE spec record; action text; read_rule text; write_rule text; BEGIN
  FOR spec IN SELECT * FROM (VALUES
    ('student', 'student.record.read', ARRAY['student.record.update', 'student.record.archive']),
    ('guardian', 'guardian.record.read', ARRAY['guardian.record.update']),
    ('program', 'program.program.read', ARRAY['program.program.manage']),
    ('study_group', 'group.group.read', ARRAY['group.group.manage']),
    ('enrollment', 'enrollment.enrollment.read', ARRAY['enrollment.enrollment.update',
      'enrollment.enrollment.validate', 'enrollment.enrollment.suspend', 'enrollment.enrollment.cancel']),
    ('group_membership', 'group.group.read', ARRAY['group.membership.manage'])
  ) AS s(tbl, reader, writers) LOOP
    read_rule := format('(SELECT school.can_everywhere(%L)) OR id = ANY ((SELECT school.core_ids(%L, %L))::uuid[])', spec.reader, spec.reader, spec.tbl);
    EXECUTE format('ALTER POLICY resource_reader ON school.%I USING (%s)', spec.tbl, read_rule);
    write_rule := 'false';
    FOREACH action IN ARRAY spec.writers LOOP
      write_rule := write_rule || format(' OR (SELECT school.can_everywhere(%L)) OR id = ANY ((SELECT school.core_ids(%L, %L))::uuid[])', action, action, spec.tbl);
    END LOOP;
    EXECUTE format('ALTER POLICY resource_writer ON school.%I USING (%s) WITH CHECK (%s)', spec.tbl, write_rule, write_rule);
  END LOOP;
END $$;
ALTER POLICY resource_reader ON school.student_guardian USING (
  (SELECT school.can_everywhere('guardian.record.read')) OR
  (student_id = ANY ((SELECT school.core_ids('student.record.read', 'student'))::uuid[])
   AND guardian_id = ANY ((SELECT school.core_ids('guardian.record.read', 'guardian'))::uuid[])));
ALTER POLICY resource_reader ON school.program_price USING (
  (SELECT school.can_everywhere('program.program.read')) OR program_id = ANY ((SELECT school.core_ids('program.program.read', 'program'))::uuid[]));
ALTER POLICY resource_reader ON school.enrollment_status_history USING (
  (SELECT school.can_everywhere('enrollment.enrollment.read')) OR enrollment_id = ANY ((SELECT school.core_ids('enrollment.enrollment.read', 'enrollment'))::uuid[]));
ALTER POLICY core_resource_reader ON school.audit_log USING (
  object_type NOT IN ('student', 'guardian', 'student_guardian', 'program', 'study_group', 'enrollment', 'group_membership') OR
  (SELECT school.can_everywhere('audit.log.read')) OR
  (object_type = 'student' AND ((SELECT school.can_everywhere('student.record.read')) OR object_id = ANY ((SELECT school.core_ids('student.record.read', 'student'))::uuid[]))) OR
  (object_type = 'guardian' AND ((SELECT school.can_everywhere('guardian.record.read')) OR object_id = ANY ((SELECT school.core_ids('guardian.record.read', 'guardian'))::uuid[]))) OR
  (object_type = 'student_guardian' AND (SELECT school.can_everywhere('guardian.record.read'))) OR
  (object_type = 'program' AND ((SELECT school.can_everywhere('program.program.read')) OR object_id = ANY ((SELECT school.core_ids('program.program.read', 'program'))::uuid[]))) OR
  (object_type = 'study_group' AND ((SELECT school.can_everywhere('group.group.read')) OR object_id = ANY ((SELECT school.core_ids('group.group.read', 'study_group'))::uuid[]))) OR
  (object_type = 'enrollment' AND ((SELECT school.can_everywhere('enrollment.enrollment.read')) OR object_id = ANY ((SELECT school.core_ids('enrollment.enrollment.read', 'enrollment'))::uuid[]))) OR
  (object_type = 'group_membership' AND ((SELECT school.can_everywhere('group.group.read')) OR object_id = ANY ((SELECT school.core_ids('group.group.read', 'group_membership'))::uuid[]))));
DROP INDEX school.study_group_site, school.program_node, school.enrollment_student, school.group_membership_all;
SQL);
        // The functions as they were before: effective_permissions of 019, core_ids of 021.
        foreach ([['019_signed_context.sql', 'school\.effective_permissions\('], ['021_pupils_and_enrolments.sql', 'school\.core_ids\(']] as [$file, $function]) {
            preg_match('/CREATE (?:OR REPLACE )?FUNCTION '.$function.'.*?\$\$;/s', file_get_contents(database_path('schema/'.$file)), $previous);
            DB::unprepared('CREATE OR REPLACE '.preg_replace('/^CREATE (OR REPLACE )?/', '', $previous[0]));
        }
    }
};
