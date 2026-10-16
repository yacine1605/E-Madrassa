<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/043_lead_imports.sql')));
    }

    public function down(): void
    {
        // The frozen codes as 001 wrote them and the prospects' run as 042 did, first.
        preg_match('/CREATE FUNCTION school\.freeze_code\(\).*?END \$\$;/s', file_get_contents(database_path('schema/001_foundation.sql')), $freeze);
        DB::unprepared(str_replace('CREATE FUNCTION', 'CREATE OR REPLACE FUNCTION', $freeze[0]));
        preg_match('/CREATE FUNCTION platform\.apply_lead_retention\(.*?END \$\$;/s', file_get_contents(database_path('schema/042_lead_retention.sql')), $run);
        DB::unprepared(str_replace('CREATE FUNCTION', 'CREATE OR REPLACE FUNCTION', $run[0]));
        // The prospects keep everything but the reference of their file.
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.discard_lead(uuid);
            DROP TRIGGER external_ref_frozen ON school.lead;
            ALTER TABLE school.lead DROP COLUMN external_ref;
            SQL);
    }
};
