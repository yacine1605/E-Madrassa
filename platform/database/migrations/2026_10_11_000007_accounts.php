<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/007_accounts.sql')));
    }

    public function down(): void
    {
        // The role assignment guard as it was before accounts: the definition in 005_authorization.sql.
        preg_match('/CREATE FUNCTION school\.guard_role_assignment\(\).*?END \$\$;/s',
            file_get_contents(database_path('schema/005_authorization.sql')), $previous);
        DB::unprepared('CREATE OR REPLACE '.substr($previous[0], strlen('CREATE ')));
        DB::unprepared(<<<'SQL'
DROP TABLE school.setting, platform.setting_definition;
DROP FUNCTION school.guard_setting(), school.setting_value(text), school.mfa_required(), platform.find_login(text, text),
 platform.peek_token(text, text),
 platform.redeem_token(text, text, text), platform.issue_recovery(text, text), platform.invite_admin(uuid, text),
 school.issue_activation(bigint, text), school.set_account_status(bigint, text, text), school.reset_password(bigint, text),
 school.update_account(bigint, text, text, text, text), school.create_account(text, text, text, text, text),
 school.shared_account(uuid, bigint), school.holds_sensitive_role(uuid, bigint), school.require_everywhere(text);
DROP TRIGGER audit ON school.membership;
DROP TRIGGER guard_writable ON school.membership;
DROP FUNCTION school.audit_account(uuid, bigint, text);
ALTER TABLE school.membership DROP COLUMN activated_at, DROP COLUMN invited_by, DROP COLUMN external_ref,
 DROP COLUMN username, DROP COLUMN id;
DROP TABLE public.account_token, public.mfa_recovery_code;
ALTER TABLE public.users DROP CONSTRAINT users_mfa_secret, DROP CONSTRAINT users_email_normalized,
 DROP COLUMN mfa_last_step, DROP COLUMN mfa_enabled_at, DROP COLUMN mfa_secret, DROP COLUMN password_changed_at,
 DROP COLUMN must_change_password;
SQL);
    }
};
