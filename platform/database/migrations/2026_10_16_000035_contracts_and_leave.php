<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/035_contracts_and_leave.sql')));
    }

    public function down(): void
    {
        // The days off, their rules and the journal's policy as 034 wrote them, first: these read the leaves.
        $previous = file_get_contents(database_path('schema/034_availability.sql'));
        foreach (['guard_unavailability', 'check_availability'] as $function) {
            preg_match('/CREATE FUNCTION school\.'.$function.'\(\).*?END \$\$;/s', $previous, $body);
            DB::unprepared(str_replace('CREATE FUNCTION', 'CREATE OR REPLACE FUNCTION', $body[0]));
        }
        preg_match('/ALTER POLICY core_resource_reader ON school\.audit_log.*?;\r?\n/s', $previous, $policy);
        DB::unprepared($policy[0]);
        // A leave's unavailability goes with its leave; the declared ones stay. The journal keeps its entries.
        DB::unprepared(<<<'SQL'
            ALTER TABLE school.unavailability DISABLE TRIGGER USER;
            DELETE FROM school.unavailability WHERE leave_id IS NOT NULL;
            ALTER TABLE school.unavailability ENABLE TRIGGER USER;
            ALTER POLICY resource_creator ON school.unavailability WITH CHECK (
              (SELECT school.can_everywhere('staff.availability.manage')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.availability.manage')))));
            ALTER POLICY resource_writer ON school.unavailability USING (
              (SELECT school.can_everywhere('staff.availability.manage')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.availability.manage'))))
              OR (SELECT school.can_everywhere('staff.availability.approve')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.availability.approve')))))
              WITH CHECK ((SELECT school.can_everywhere('staff.availability.manage')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.availability.manage'))))
              OR (SELECT school.can_everywhere('staff.availability.approve')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.availability.approve')))));
            ALTER TABLE school.unavailability DROP CONSTRAINT unavailability_once, ADD CONSTRAINT unavailability_once
              EXCLUDE USING gist (tenant_id WITH =, staff_id WITH =, daterange(date_from, date_to, '[]') WITH &&) WHERE (status IN ('pending', 'approved'));
            ALTER TABLE school.unavailability DROP COLUMN leave_id;
            ALTER TABLE school.unavailability ALTER COLUMN reason SET NOT NULL;
            DROP TABLE school.staff_leave;
            DROP TABLE school.staff_contract;
            DROP FUNCTION school.follow_leave();
            DROP FUNCTION school.check_staff_terms();
            DROP FUNCTION school.guard_staff_leave();
            DROP FUNCTION school.guard_staff_contract();
            DELETE FROM platform.audit_domain WHERE object_type IN ('staff_contract', 'staff_leave');
            ALTER TABLE school.role_permission DISABLE TRIGGER USER;
            DELETE FROM school.role_permission rp USING school.role r
              WHERE r.tenant_id = rp.tenant_id AND r.id = rp.role_id AND r.template_code = 'teacher'
              AND rp.permission_code IN ('staff.contract.read', 'staff.leave.read');
            ALTER TABLE school.role_permission ENABLE TRIGGER USER;
            DELETE FROM platform.role_template_permission WHERE role_code = 'teacher' AND permission_code IN ('staff.contract.read', 'staff.leave.read');
            SQL);
    }
};
