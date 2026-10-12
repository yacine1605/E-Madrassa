<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/019_signed_context.sql')));
    }

    public function down(): void
    {
        // The functions as they were before: tenant and actor of 001, protect of 003, my_schools of 004, audit_account
        // of 007, audit, audit_tenant and node_subtree of 008, journal_watchers of 010, allowed, guard_writable,
        // support_grant_in_use, log_support_access, effective_permissions and file_categories of 015.
        foreach ([['001_foundation.sql', 'FUNCTION school\.tenant\(\)'], ['001_foundation.sql', 'FUNCTION school\.actor\(\)'],
                  ['003_school_core.sql', 'PROCEDURE school\.protect\('], ['004_sessions.sql', 'FUNCTION school\.my_schools\(\)'],
                  ['007_accounts.sql', 'FUNCTION school\.audit_account\('], ['008_referential.sql', 'FUNCTION school\.audit\(\)'],
                  ['008_referential.sql', 'FUNCTION platform\.audit_tenant\(\)'], ['008_referential.sql', 'FUNCTION school\.node_subtree\('],
                  ['010_services.sql', 'FUNCTION school\.journal_watchers\('], ['015_support.sql', 'FUNCTION school\.allowed\(t uuid\)'],
                  ['015_support.sql', 'FUNCTION school\.guard_writable\(\)'], ['015_support.sql', 'FUNCTION school\.support_grant_in_use\(\)'],
                  ['015_support.sql', 'FUNCTION school\.log_support_access\('], ['015_support.sql', 'FUNCTION school\.effective_permissions\('],
                  ['015_support.sql', 'FUNCTION school\.file_categories\(']]
                 as [$file, $routine]) {
            preg_match('/CREATE (?:OR REPLACE )?'.$routine.'.*?\$\$;/s', file_get_contents(database_path('schema/'.$file)), $previous);
            DB::unprepared('CREATE OR REPLACE '.preg_replace('/^CREATE (OR REPLACE )?/', '', $previous[0]));
        }
        DB::unprepared(<<<'SQL'
DO $$ DECLARE p record; BEGIN
  FOR p IN SELECT tablename FROM pg_policies WHERE schemaname = 'school' AND policyname = 'tenant' LOOP
    EXECUTE format('ALTER POLICY tenant ON school.%I USING (school.allowed(tenant_id)) WITH CHECK (school.allowed(tenant_id))', p.tablename);
  END LOOP;
END $$;
ALTER POLICY own_school ON platform.tenant USING (school.allowed(id));
ALTER POLICY own_membership ON school.membership USING (user_id = school.actor());
ALTER POLICY school_members ON school.membership USING (
  tenant_id = school.tenant() AND ((SELECT school.can('user.account.read')) OR (SELECT school.can('role.assignment.manage'))));
ALTER POLICY reader ON school.audit_log
  USING (tenant_id = school.tenant() AND object_type = ANY ((SELECT school.audit_readable_types())::text[]));
ALTER POLICY own_jobs ON school.job USING (tenant_id = school.tenant() AND user_id = school.actor())
  WITH CHECK (tenant_id = school.tenant() AND user_id = school.actor());
ALTER POLICY own_notifications ON school.notification USING (tenant_id = school.tenant() AND user_id = school.actor());
ALTER POLICY own_preferences ON school.notification_preference USING (tenant_id = school.tenant() AND user_id = school.actor())
  WITH CHECK (tenant_id = school.tenant() AND user_id = school.actor());
ALTER POLICY settings_reader ON school.outbox USING (tenant_id = school.tenant() AND (SELECT school.can_everywhere('tenant.settings.manage')));
ALTER POLICY reader ON school.file_object USING (tenant_id = school.tenant()
  AND category = ANY ((SELECT school.file_categories('read'))::text[])
  AND (created_by = school.actor() OR category <> ALL ((SELECT school.file_categories('author'))::text[])));
ALTER POLICY writer ON school.file_object WITH CHECK (tenant_id = school.tenant()
  AND category = ANY ((SELECT school.file_categories('write'))::text[]));
ALTER POLICY updater ON school.file_object USING (tenant_id = school.tenant()
  AND category = ANY ((SELECT school.file_categories('write'))::text[])
  AND (created_by = school.actor() OR category <> ALL ((SELECT school.file_categories('author'))::text[])));
ALTER POLICY author_writes ON school.export_log WITH CHECK (tenant_id = school.tenant() AND user_id = school.actor());
ALTER POLICY readers ON school.export_log USING (tenant_id = school.tenant()
  AND (user_id = school.actor() OR (SELECT school.can_everywhere('audit.log.read'))));
ALTER POLICY author ON school.import_batch USING (tenant_id = school.tenant() AND user_id = school.actor())
  WITH CHECK (tenant_id = school.tenant() AND user_id = school.actor());
ALTER POLICY follower ON school.import_batch USING (tenant_id = school.tenant() AND (SELECT school.can_everywhere('import.job.read')));
ALTER POLICY rows ON school.import_row USING (tenant_id = school.tenant()
  AND EXISTS (SELECT 1 FROM school.import_batch b WHERE b.tenant_id = import_row.tenant_id AND b.id = import_row.batch_id));
DROP INDEX school.membership_user;
DROP FUNCTION school.allowed_tenant(), school.context();
DROP TYPE school.request_context;
DROP SCHEMA secret CASCADE;
SQL);
    }
};
