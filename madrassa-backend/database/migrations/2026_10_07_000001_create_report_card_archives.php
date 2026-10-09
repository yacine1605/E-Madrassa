<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE school.report_card_archives (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 kind text NOT NULL CHECK(kind IN ('term','annual')), term_id uuid,
 cohort_id uuid NOT NULL, period_id uuid NOT NULL,
 status text NOT NULL CHECK(status IN ('draft','validated','closed')),
 payload jsonb, content_hash char(64), rendered jsonb,
 validated_by bigint REFERENCES public.users(id), validated_at timestamptz,
 closed_by bigint REFERENCES public.users(id), closed_at timestamptz,
 created_by bigint NOT NULL REFERENCES public.users(id), created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,term_id) REFERENCES school.report_card_terms(tenant_id,id),
 FOREIGN KEY(tenant_id,cohort_id) REFERENCES school.cohorts(tenant_id,id),
 FOREIGN KEY(tenant_id,period_id) REFERENCES school.periods(tenant_id,id),
 CHECK((kind='term' AND term_id IS NOT NULL) OR (kind='annual' AND term_id IS NULL)),
 CHECK((status='draft' AND payload IS NULL AND content_hash IS NULL AND validated_by IS NULL AND validated_at IS NULL)
    OR (status IN ('validated','closed') AND payload IS NOT NULL AND content_hash ~ '^[0-9a-f]{64}$' AND validated_by IS NOT NULL AND validated_at IS NOT NULL)),
 CHECK((status='closed' AND rendered IS NOT NULL AND closed_by IS NOT NULL AND closed_at IS NOT NULL)
    OR (status<>'closed' AND rendered IS NULL AND closed_by IS NULL AND closed_at IS NULL))
);
CREATE UNIQUE INDEX report_card_term_archive ON school.report_card_archives(tenant_id,term_id,cohort_id) WHERE kind='term';
CREATE UNIQUE INDEX report_card_annual_archive ON school.report_card_archives(tenant_id,period_id,cohort_id) WHERE kind='annual';
ALTER TABLE school.report_card_archives ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.report_card_archives FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.report_card_archives USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE FUNCTION school.guard_report_card_archive() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'report_card_archive_delete_forbidden' USING ERRCODE='23514'; END IF;
 IF TG_OP='INSERT' THEN
   IF NEW.status<>'validated' THEN RAISE EXCEPTION 'report_card_archive_requires_validation' USING ERRCODE='23514'; END IF;
 ELSE
   IF OLD.status='closed' THEN RAISE EXCEPTION 'closed_report_card_immutable' USING ERRCODE='23514'; END IF;
   IF ROW(NEW.id,NEW.tenant_id,NEW.kind,NEW.term_id,NEW.cohort_id,NEW.period_id,NEW.created_by,NEW.created_at)
      IS DISTINCT FROM ROW(OLD.id,OLD.tenant_id,OLD.kind,OLD.term_id,OLD.cohort_id,OLD.period_id,OLD.created_by,OLD.created_at)
      THEN RAISE EXCEPTION 'report_card_archive_identity_immutable' USING ERRCODE='23514'; END IF;
   IF NOT ((OLD.status='draft' AND NEW.status='validated') OR (OLD.status='validated' AND NEW.status IN ('draft','closed')))
      THEN RAISE EXCEPTION 'invalid_report_card_transition' USING ERRCODE='23514'; END IF;
   IF NEW.status='closed' AND ROW(NEW.payload,NEW.content_hash,NEW.validated_by,NEW.validated_at)
      IS DISTINCT FROM ROW(OLD.payload,OLD.content_hash,OLD.validated_by,OLD.validated_at)
      THEN RAISE EXCEPTION 'validated_report_card_changed' USING ERRCODE='23514'; END IF;
 END IF;
 IF NOT EXISTS(SELECT 1 FROM school.cohorts c JOIN school.offerings o ON o.tenant_id=c.tenant_id AND o.id=c.offering_id
    WHERE c.tenant_id=NEW.tenant_id AND c.id=NEW.cohort_id AND o.period_id=NEW.period_id
    AND (NEW.term_id IS NULL OR EXISTS(SELECT 1 FROM school.report_card_terms t WHERE t.tenant_id=NEW.tenant_id
       AND t.id=NEW.term_id AND t.level_id=o.level_id AND t.period_id=o.period_id)))
    THEN RAISE EXCEPTION 'report_card_archive_class_mismatch' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER protect BEFORE INSERT OR UPDATE OR DELETE ON school.report_card_archives FOR EACH ROW EXECUTE FUNCTION school.guard_report_card_archive();
CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.report_card_archives FOR EACH ROW EXECUTE FUNCTION school.audit();
DO $$ BEGIN IF EXISTS(SELECT 1 FROM pg_roles WHERE rolname='school_runtime') THEN
 GRANT SELECT, INSERT, UPDATE(status,payload,content_hash,rendered,validated_by,validated_at,closed_by,closed_at) ON school.report_card_archives TO school_runtime;
END IF; END $$;
SQL);
    }
    public function down(): void
    {
        DB::statement('DROP TABLE school.report_card_archives');
        DB::statement('DROP FUNCTION school.guard_report_card_archive()');
    }
};
