<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/051_receipts.sql')));
    }

    public function down(): void
    {
        // The receipts go, with their counter and their language's setting.
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.issue_receipt(uuid, uuid);
            DROP FUNCTION platform.balance_at(uuid, uuid, timestamptz);
            DROP TABLE school.receipt;
            DROP TABLE school.receipt_counter;
            DELETE FROM platform.audit_domain WHERE object_type = 'receipt';
            ALTER TABLE school.setting DISABLE TRIGGER USER;
            DELETE FROM school.setting WHERE key = 'finance.receipt.locale';
            ALTER TABLE school.setting ENABLE TRIGGER USER;
            DELETE FROM platform.setting_definition WHERE key = 'finance.receipt.locale';
            SQL);
    }
};
