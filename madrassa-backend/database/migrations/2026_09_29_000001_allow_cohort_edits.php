<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS immutable ON school.cohorts');
    }

    public function down(): void
    {
        DB::unprepared('CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.cohorts FOR EACH ROW EXECUTE FUNCTION school.immutable()');
    }
};
