<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/054_payment_attachments.sql')));
    }

    public function down(): void
    {
        // The payments lose their document; the files stay, archived, owned by nothing readable but the finance's.
        DB::unprepared(<<<'SQL'
            ALTER POLICY reader ON school.file_object USING (tenant_id = (SELECT school.tenant())
              AND category = ANY ((SELECT school.file_categories('read'))::text[])
              AND (created_by = (SELECT school.actor()) OR category <> ALL ((SELECT school.file_categories('author'))::text[]))
              AND (category <> 'student_photo' OR EXISTS (SELECT 1 FROM school.student s WHERE s.tenant_id = file_object.tenant_id AND s.id = file_object.owner_id)));
            ALTER POLICY writer ON school.file_object WITH CHECK (tenant_id = (SELECT school.tenant())
              AND category = ANY ((SELECT school.file_categories('write'))::text[])
              AND (category <> 'student_photo' OR (owner_type = 'student'
                AND EXISTS (SELECT 1 FROM school.student s WHERE s.tenant_id = file_object.tenant_id AND s.id = file_object.owner_id))));
            ALTER POLICY updater ON school.file_object USING (tenant_id = (SELECT school.tenant())
              AND category = ANY ((SELECT school.file_categories('write'))::text[])
              AND (created_by = (SELECT school.actor()) OR category <> ALL ((SELECT school.file_categories('author'))::text[]))
              AND (category <> 'student_photo' OR EXISTS (SELECT 1 FROM school.student s WHERE s.tenant_id = file_object.tenant_id AND s.id = file_object.owner_id)));
            DROP TRIGGER retire_attachment ON school.payment;
            DROP TRIGGER attachment ON school.payment;
            DROP FUNCTION school.retire_payment_attachment();
            DROP FUNCTION school.check_payment_attachment();
            ALTER TABLE school.payment DROP COLUMN attachment_file_id;
            UPDATE platform.file_category SET write_any = array_remove(write_any, 'finance.payment.create.delegated') WHERE code = 'finance_attachment';
            SQL);
    }
};
