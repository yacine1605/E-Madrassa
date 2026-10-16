<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/037_rooms.sql')));
    }

    public function down(): void
    {
        // Modules enabled as 002 enabled them, first: this one gives the lists of rooms.
        preg_match('/CREATE FUNCTION platform\.enable_module\(.*?END \$\$;/s', file_get_contents(database_path('schema/002_platform.sql')), $function);
        DB::unprepared(str_replace('CREATE FUNCTION', 'CREATE OR REPLACE FUNCTION', $function[0]));
        // The groups keep everything but their main room; the rooms go with their closures and lists; the journal keeps its entries.
        DB::unprepared(<<<'SQL'
            DROP TRIGGER main_room ON school.study_group;
            ALTER TABLE school.study_group DROP COLUMN main_room_id;
            DROP TRIGGER rooms ON school.site;
            DROP FUNCTION school.room_available(uuid, date);
            DROP TABLE school.room_closure;
            DROP TABLE school.room;
            DROP TABLE school.room_option;
            DROP TABLE platform.room_option_default;
            DROP FUNCTION school.guard_site_rooms();
            DROP FUNCTION school.check_main_room();
            DROP FUNCTION school.guard_room_closure();
            DROP FUNCTION school.guard_room();
            DROP FUNCTION school.guard_room_option();
            DELETE FROM platform.audit_domain WHERE object_type IN ('room', 'room_closure', 'room_option');
            SQL);
    }
};
