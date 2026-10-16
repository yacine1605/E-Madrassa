<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/038_leads.sql')));
    }

    public function down(): void
    {
        // Modules enabled and the journal read as 037 and 035 left them, first: these name the prospects.
        preg_match('/CREATE OR REPLACE FUNCTION platform\.enable_module\(.*?END \$\$;/s', file_get_contents(database_path('schema/037_rooms.sql')), $function);
        DB::unprepared($function[0]);
        preg_match('/ALTER POLICY core_resource_reader ON school\.audit_log.*?;\r?\n/s', file_get_contents(database_path('schema/035_contracts_and_leave.sql')), $policy);
        DB::unprepared($policy[0]);
        // The prospects go with their children, interactions, reminders and lists; the pupils they became stay. The journal keeps its entries.
        DB::unprepared(<<<'SQL'
            DROP TABLE school.lead_reminder_notice;
            DROP TABLE school.lead_reminder;
            DROP TABLE school.lead_interaction;
            DROP TABLE school.lead_child;
            DROP TABLE school.lead;
            DROP TABLE school.lead_option;
            DROP TABLE platform.lead_option_default;
            DROP FUNCTION platform.notify_due_reminders(integer);
            DROP FUNCTION school.guard_lead_reminder();
            DROP FUNCTION school.guard_lead_interaction();
            DROP FUNCTION school.guard_lead_child();
            DROP FUNCTION school.close_lead_reminders();
            DROP FUNCTION school.guard_lead();
            DROP FUNCTION school.lead_in_scope(text, uuid);
            DROP FUNCTION school.lead_assignee_valid(bigint, uuid);
            DROP FUNCTION school.lead_assignees(uuid);
            DROP FUNCTION platform.lead_reader(uuid, bigint, uuid);
            DROP FUNCTION school.guard_lead_option();
            DROP FUNCTION school.lead_status_closed(text);
            DROP FUNCTION school.lead_status_reserved(text);
            DELETE FROM school.outbox WHERE event = 'crm.reminder_due';
            DELETE FROM school.notification WHERE event = 'crm.reminder_due';
            DELETE FROM school.notification_preference WHERE event = 'crm.reminder_due';
            DELETE FROM platform.notification_event WHERE code = 'crm.reminder_due';
            DELETE FROM platform.audit_domain WHERE object_type IN ('lead', 'lead_child', 'lead_interaction', 'lead_reminder', 'lead_option');
            ALTER TABLE school.role_permission DISABLE TRIGGER USER;
            DELETE FROM school.role_permission rp USING school.role r
              WHERE r.tenant_id = rp.tenant_id AND r.id = rp.role_id AND r.template_code IN ('direction', 'academic_hr')
              AND rp.permission_code = 'crm.lead.read';
            ALTER TABLE school.role_permission ENABLE TRIGGER USER;
            DELETE FROM platform.role_template_permission WHERE role_code IN ('direction', 'academic_hr') AND permission_code = 'crm.lead.read';
            SQL);
    }
};
