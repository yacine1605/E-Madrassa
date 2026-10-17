<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/074_guardian_messages.sql')));
    }

    public function down(): void
    {
        // The guardians told no more: the messages and the setting go, the alerts stay.
        DB::unprepared(<<<'SQL'
            DROP FUNCTION platform.finish_guardian_message(uuid, text);
            DROP FUNCTION platform.claim_guardian_messages(text, integer, integer);
            DROP TRIGGER tell_guardians ON school.absence_alert;
            DROP FUNCTION school.tell_guardians();
            DROP TABLE school.guardian_message;
            ALTER TABLE school.setting DISABLE TRIGGER USER;
            DELETE FROM school.setting WHERE key = 'attendance.notify_guardians_email';
            ALTER TABLE school.setting ENABLE TRIGGER USER;
            DELETE FROM platform.setting_definition WHERE key = 'attendance.notify_guardians_email';
            SQL);
    }
};
