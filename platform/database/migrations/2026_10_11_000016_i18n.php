<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/016_i18n.sql')));
    }

    public function down(): void
    {
        // The functions as they were before: label_key of 008, create_tenant of 009, claim_outbox of 010.
        foreach ([['008_referential.sql', 'school\.label_key\('], ['009_profiles.sql', 'platform\.create_tenant\('],
                  ['010_services.sql', 'platform\.claim_outbox\(']] as [$file, $function]) {
            preg_match('/CREATE (?:OR REPLACE )?FUNCTION '.$function.'.*?\$\$;/s', file_get_contents(database_path('schema/'.$file)), $previous);
            DB::unprepared('CREATE OR REPLACE '.preg_replace('/^CREATE (OR REPLACE )?/', '', $previous[0]));
        }
        DB::unprepared(<<<'SQL'
DROP FUNCTION school.search_key(text), platform.locale_for(bigint, uuid);
ALTER TABLE school.site DISABLE TRIGGER USER;
UPDATE school.site SET labels = labels || jsonb_build_object('fr', labels ->> 'ar') WHERE NOT labels ? 'fr';
ALTER TABLE school.site ENABLE TRIGGER USER;
ALTER TABLE school.site DROP CONSTRAINT site_labels_check;
ALTER TABLE school.site ADD CONSTRAINT site_labels_check CHECK (jsonb_typeof(labels) = 'object' AND labels ? 'fr');
ALTER TABLE school.setting DISABLE TRIGGER USER;
DELETE FROM school.setting WHERE key = 'locale.arabic_digits';
ALTER TABLE school.setting ENABLE TRIGGER USER;
DELETE FROM platform.setting_definition WHERE key = 'locale.arabic_digits';
ALTER TABLE public.users DROP COLUMN locale;
SQL);
    }
};
