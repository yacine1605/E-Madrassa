<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/008_referential.sql')));
    }

    public function down(): void
    {
        // Functions as they were before: the role assignment guard and settings guard of 007, the audit of 003.
        foreach ([['007_accounts.sql', 'school\.guard_role_assignment\(\)'], ['007_accounts.sql', 'school\.guard_setting\(\)'],
                  ['003_school_core.sql', 'school\.audit\(\)'], ['003_school_core.sql', 'platform\.audit_tenant\(\)']] as [$file, $function]) {
            preg_match('/CREATE (?:OR REPLACE )?FUNCTION '.$function.'.*?END \$\$;/s', file_get_contents(database_path('schema/'.$file)), $previous);
            DB::unprepared('CREATE OR REPLACE '.preg_replace('/^CREATE (OR REPLACE )?/', '', $previous[0]));
        }
        DB::unprepared(<<<'SQL'
DROP FUNCTION platform.apply_profile(uuid, text, integer), school.open_day(date), school.check_custom_values(text, jsonb),
 school.year_open(uuid), school.node_subtree(uuid[]);
ALTER TABLE school.setting DISABLE TRIGGER guard_writable;
DELETE FROM school.setting WHERE key IN ('calendar.open_days', 'calendar.first_day_of_week', 'locale.languages',
 'identity.address', 'identity.phone', 'identity.email');
ALTER TABLE school.setting ENABLE TRIGGER guard_writable;
DELETE FROM platform.setting_definition WHERE key IN ('calendar.open_days', 'calendar.first_day_of_week', 'locale.languages',
 'identity.address', 'identity.phone', 'identity.email');
ALTER TABLE platform.setting_definition DROP CONSTRAINT setting_definition_value_type_check;
ALTER TABLE platform.setting_definition ADD CONSTRAINT setting_definition_value_type_check CHECK (value_type IN ('integer', 'boolean'));
DROP TABLE school.custom_field, school.period, school.academic_year, school.subject, school.ref_node, school.node_type;
DROP FUNCTION school.guard_custom_field(), school.guard_period(), school.guard_academic_year(), school.guard_ref_node(),
 school.guard_subject_delete(),
 school.guard_node_type();
ALTER TABLE platform.tenant DROP COLUMN profile_version, DROP COLUMN profile_code;
DROP TABLE platform.profile_subject, platform.profile_node, platform.profile_node_type, platform.profile;
DROP FUNCTION platform.guard_profile(), school.valid_labels(jsonb), school.label_key(text);
DROP EXTENSION btree_gist;
SQL);
    }
};
