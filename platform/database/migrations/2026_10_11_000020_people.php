<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/020_people.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP POLICY people ON public.users;
DROP POLICY own_language ON public.users;
DROP POLICY sign_in ON public.users;
ALTER TABLE public.users NO FORCE ROW LEVEL SECURITY;
ALTER TABLE public.users DISABLE ROW LEVEL SECURITY;
DROP VIEW school.people;
DROP FUNCTION platform.issue_recovery(text, text);
SQL);
        // The recovery as it was before: 007's, without the language.
        preg_match('/CREATE FUNCTION platform\.issue_recovery\(.*?\$\$;/s', file_get_contents(database_path('schema/007_accounts.sql')), $previous);
        DB::unprepared($previous[0]);
        DB::unprepared('REVOKE EXECUTE ON FUNCTION platform.issue_recovery(text, text) FROM PUBLIC');
    }
};
