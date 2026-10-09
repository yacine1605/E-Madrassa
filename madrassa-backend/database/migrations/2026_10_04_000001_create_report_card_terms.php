<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE school.assessments DROP CONSTRAINT assessments_kind_check");
        DB::statement("ALTER TABLE school.assessments ADD CONSTRAINT assessments_kind_check CHECK (kind IN ('continu','devoir','examen'))");
        DB::unprepared("CREATE TABLE school.report_card_terms (
            id uuid PRIMARY KEY, tenant_id uuid NOT NULL REFERENCES school.institutions(id),
            level_id uuid NOT NULL, period_id uuid NOT NULL, trimester smallint NOT NULL CHECK(trimester BETWEEN 1 AND 3),
            starts_on date NOT NULL, ends_on date NOT NULL CHECK(ends_on >= starts_on),
            scale smallint NOT NULL CHECK(scale IN (10,20)), reference text NOT NULL,
            rules jsonb NOT NULL, created_by bigint NOT NULL REFERENCES public.users(id), created_at timestamptz NOT NULL DEFAULT now(),
            UNIQUE(tenant_id,id), UNIQUE(tenant_id,level_id,period_id,trimester),
            FOREIGN KEY(tenant_id,level_id) REFERENCES school.levels(tenant_id,id),
            FOREIGN KEY(tenant_id,period_id) REFERENCES school.periods(tenant_id,id)
        );
        ALTER TABLE school.report_card_terms ENABLE ROW LEVEL SECURITY;
        ALTER TABLE school.report_card_terms FORCE ROW LEVEL SECURITY;
        CREATE POLICY tenant ON school.report_card_terms USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));
        CREATE TABLE school.report_card_observations (
            id uuid PRIMARY KEY, tenant_id uuid NOT NULL REFERENCES school.institutions(id),
            term_id uuid NOT NULL, enrollment_id uuid NOT NULL, subject_id uuid NOT NULL,
            observation text NOT NULL, UNIQUE(tenant_id,term_id,enrollment_id,subject_id),
            FOREIGN KEY(tenant_id,term_id) REFERENCES school.report_card_terms(tenant_id,id),
            FOREIGN KEY(tenant_id,enrollment_id) REFERENCES school.enrollments(tenant_id,id),
            FOREIGN KEY(tenant_id,subject_id) REFERENCES school.subjects(tenant_id,id)
        );
        ALTER TABLE school.report_card_observations ENABLE ROW LEVEL SECURITY;
        ALTER TABLE school.report_card_observations FORCE ROW LEVEL SECURITY;
        CREATE POLICY tenant ON school.report_card_observations USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));");
        DB::unprepared("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='school_runtime') THEN
            GRANT SELECT, INSERT ON school.report_card_terms TO school_runtime;
            GRANT SELECT, INSERT, UPDATE(observation) ON school.report_card_observations TO school_runtime;
        END IF; END \$\$;");
    }
    public function down(): void
    {
        DB::statement('DROP TABLE school.report_card_observations');
        DB::statement('DROP TABLE school.report_card_terms');
        // Preserve existing continuous assessments rather than dropping their type.
    }
};
