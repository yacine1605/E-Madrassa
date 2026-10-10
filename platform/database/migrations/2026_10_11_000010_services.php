<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/010_services.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER notify_sensitive ON school.role_assignment;
DROP TRIGGER notify_sensitive ON school.role_permission;
DROP FUNCTION school.retry_delivery(uuid), platform.finish_outbox(uuid, text), platform.claim_outbox(integer, integer),
 school.notify_sensitive(), school.journal_watchers(uuid);
DROP TABLE school.outbox, school.notification_preference, school.notification, platform.notification_event, school.job;
DROP FUNCTION school.notify(text, bigint[], jsonb), school.guard_job();
DROP POLICY reader ON school.audit_log;
CREATE POLICY tenant_read ON school.audit_log FOR SELECT USING (school.allowed(tenant_id));
DROP FUNCTION school.audit_readable_types();
DROP TABLE platform.audit_domain;
SQL);
    }
};
