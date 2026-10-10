<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/009_profiles.sql')));
    }

    public function down(): void
    {
        // School creation as it was before profiles: the definition in 006_authorization_catalog.sql.
        preg_match('/CREATE OR REPLACE FUNCTION platform\.create_tenant\(.*?END \$\$;/s',
            file_get_contents(database_path('schema/006_authorization_catalog.sql')), $previous);
        DB::unprepared('DROP FUNCTION platform.create_tenant(uuid, text, text, text, text, date, date, integer, integer, text, text, bigint, text)');
        DB::unprepared($previous[0]);
        // Published profiles are protected by their trigger; a development rollback removes them all the same.
        DB::unprepared(<<<'SQL'
UPDATE platform.tenant SET profile_code = NULL, profile_version = NULL WHERE profile_code IS NOT NULL;
ALTER TABLE platform.profile_subject DISABLE TRIGGER guard;
ALTER TABLE platform.profile_node DISABLE TRIGGER guard;
ALTER TABLE platform.profile_node_type DISABLE TRIGGER guard;
ALTER TABLE platform.profile DISABLE TRIGGER guard;
DELETE FROM platform.profile_subject;
DELETE FROM platform.profile_node;
DELETE FROM platform.profile_node_type;
DELETE FROM platform.profile;
ALTER TABLE platform.profile_subject ENABLE TRIGGER guard;
ALTER TABLE platform.profile_node ENABLE TRIGGER guard;
ALTER TABLE platform.profile_node_type ENABLE TRIGGER guard;
ALTER TABLE platform.profile ENABLE TRIGGER guard;
SQL);
    }
};
