<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/041_composition_notices.sql')));
    }

    public function down(): void
    {
        // The notifications of the event go with it, their emails first; the groups keep their composition.
        DB::unprepared(<<<'SQL'
            DROP TRIGGER notify_composition ON school.group_membership;
            DROP FUNCTION school.notify_composition();
            DELETE FROM school.outbox WHERE event = 'group.membership_changed';
            DELETE FROM school.notification WHERE event = 'group.membership_changed';
            DELETE FROM school.notification_preference WHERE event = 'group.membership_changed';
            DELETE FROM platform.notification_event WHERE code = 'group.membership_changed';
            SQL);
    }
};
