<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/029_pupil_sites.sql')));
    }

    public function down(): void
    {
        $schema = fn (string $file) => file_get_contents(database_path('schema/'.$file));
        // The policies as 023 (readers and writers) and 021 (creators) wrote them, first: these ones read the sites.
        preg_match('/DO \$\$ DECLARE spec record; action text; read_rule text; write_rule text; BEGIN.*?END \$\$;/s', $schema('023_scope_cost.sql'), $policies);
        DB::unprepared($policies[0]);
        DB::unprepared(<<<'SQL'
            DO $$ DECLARE spec record; BEGIN
              FOR spec IN SELECT * FROM (VALUES ('student', 'student.record.create'), ('guardian', 'guardian.record.create'),
                ('enrollment', 'enrollment.enrollment.create'), ('study_group', 'group.group.manage'), ('group_membership', 'group.membership.manage')) AS s(tbl, creator) LOOP
                EXECUTE format('ALTER POLICY resource_creator ON school.%I WITH CHECK ((SELECT school.can_everywhere(%L)))', spec.tbl, spec.creator);
              END LOOP;
            END $$;
            ALTER POLICY resource_creator ON school.student_guardian WITH CHECK ((SELECT school.can_everywhere('guardian.record.create')));
            ALTER POLICY resource_writer ON school.student_guardian USING ((SELECT school.can_everywhere('guardian.record.update')))
              WITH CHECK ((SELECT school.can_everywhere('guardian.record.update')));
            SQL);
        // The scopes' IDs as 023 computed them, the matricule's check as 021 made it.
        preg_match('/CREATE OR REPLACE FUNCTION school\.core_ids\(.*?END \$\$;/s', $schema('023_scope_cost.sql'), $coreIds);
        DB::unprepared($coreIds[0]);
        preg_match('/CREATE FUNCTION school\.next_matricule\(.*?END \$\$;/s', $schema('021_pupils_and_enrolments.sql'), $matricule);
        DB::unprepared(preg_replace('/^CREATE FUNCTION/', 'CREATE OR REPLACE FUNCTION', $matricule[0]));
        // The sites recorded go with their column; the journal keeps them.
        DB::unprepared(<<<'SQL'
            DROP TRIGGER authorize ON school.student_guardian;
            DROP TRIGGER authorize ON school.group_membership;
            DROP TRIGGER authorize ON school.study_group;
            DROP TRIGGER authorize ON school.enrollment;
            DROP FUNCTION school.authorize_creation();
            DROP TRIGGER attach_site ON school.guardian;
            DROP TRIGGER attach_site ON school.student;
            DROP FUNCTION school.attach_site();
            DROP FUNCTION school.site_scope_ids(text, text);
            ALTER TABLE school.guardian DROP COLUMN site_id;
            ALTER TABLE school.student DROP COLUMN site_id;
            SQL);
    }
};
