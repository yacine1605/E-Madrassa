<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/052_arrears.sql')));
    }

    public function down(): void
    {
        // The arrears' reading and the daily reminder go, with their notifications, runs and setting.
        DB::unprepared(<<<'SQL'
            DROP FUNCTION platform.notify_installments(date);
            DROP FUNCTION school.arrears(date, uuid);
            DROP FUNCTION platform.arrears_of(uuid, uuid[], date);
            DROP FUNCTION platform.reminder_days(text);
            DROP TABLE school.finance_reminder_run;
            DELETE FROM school.outbox WHERE event = 'finance.installments_due';
            DELETE FROM school.notification WHERE event = 'finance.installments_due';
            DELETE FROM school.notification_preference WHERE event = 'finance.installments_due';
            DELETE FROM platform.notification_event WHERE code = 'finance.installments_due';
            ALTER TABLE school.setting DISABLE TRIGGER USER;
            DELETE FROM school.setting WHERE key = 'finance.reminder.offsets';
            ALTER TABLE school.setting ENABLE TRIGGER USER;
            DELETE FROM platform.setting_definition WHERE key = 'finance.reminder.offsets';
            SQL);
    }
};
