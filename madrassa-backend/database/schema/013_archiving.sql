-- BR-02: the application archives business records instead of deleting them.
ALTER TABLE school.campuses ADD COLUMN archived_at timestamptz;
ALTER TABLE school.periods ADD COLUMN archived_at timestamptz;
ALTER TABLE school.programs ADD COLUMN archived_at timestamptz;
ALTER TABLE school.levels ADD COLUMN archived_at timestamptz;
ALTER TABLE school.subjects ADD COLUMN archived_at timestamptz;
ALTER TABLE school.curriculum ADD COLUMN archived_at timestamptz;
ALTER TABLE school.offerings ADD COLUMN archived_at timestamptz;
ALTER TABLE school.cohorts ADD COLUMN archived_at timestamptz;
ALTER TABLE school.rooms ADD COLUMN archived_at timestamptz;
ALTER TABLE school.students ADD COLUMN archived_at timestamptz;
ALTER TABLE school.guardians ADD COLUMN archived_at timestamptz;
ALTER TABLE school.student_guardians ADD COLUMN archived_at timestamptz;
ALTER TABLE school.staff ADD COLUMN archived_at timestamptz;

-- BR-18: codes and registration numbers are permanent once created.
CREATE FUNCTION school.freeze_code() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF to_jsonb(NEW)->TG_ARGV[0] IS DISTINCT FROM to_jsonb(OLD)->TG_ARGV[0] THEN
 RAISE EXCEPTION 'code_immutable' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER code_frozen BEFORE UPDATE ON school.programs FOR EACH ROW EXECUTE FUNCTION school.freeze_code('code');
CREATE TRIGGER code_frozen BEFORE UPDATE ON school.levels FOR EACH ROW EXECUTE FUNCTION school.freeze_code('code');
CREATE TRIGGER code_frozen BEFORE UPDATE ON school.subjects FOR EACH ROW EXECUTE FUNCTION school.freeze_code('code');
CREATE TRIGGER code_frozen BEFORE UPDATE ON school.staff FOR EACH ROW EXECUTE FUNCTION school.freeze_code('code');
CREATE TRIGGER code_frozen BEFORE UPDATE ON school.students FOR EACH ROW EXECUTE FUNCTION school.freeze_code('matricule');

-- The edit API updates these columns; codes are deliberately absent.
DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='school_runtime') THEN
  GRANT UPDATE(name, wilaya_code, address, archived_at) ON school.campuses TO school_runtime;
  GRANT UPDATE(name, starts_on, ends_on, archived_at) ON school.periods TO school_runtime;
  GRANT UPDATE(name, kind, description, active, archived_at) ON school.programs TO school_runtime;
  GRANT UPDATE(program_id, name, position, archived_at) ON school.levels TO school_runtime;
  GRANT UPDATE(name, archived_at) ON school.subjects TO school_runtime;
  GRANT UPDATE(level_id, subject_id, hours, coefficient, archived_at) ON school.curriculum TO school_runtime;
  GRANT UPDATE(level_id, period_id, campus_id, name, price_minor, active, archived_at) ON school.offerings TO school_runtime;
  GRANT UPDATE(offering_id, name, capacity, archived_at) ON school.cohorts TO school_runtime;
  GRANT UPDATE(campus_id, name, capacity, archived_at) ON school.rooms TO school_runtime;
  GRANT UPDATE(first_name, last_name, birth_date, phone, email, active, archived_at) ON school.students TO school_runtime;
  GRANT UPDATE(name, phone, email, archived_at) ON school.guardians TO school_runtime;
  GRANT UPDATE(student_id, guardian_id, relationship, can_collect, archived_at) ON school.student_guardians TO school_runtime;
  GRANT UPDATE(name, role, is_teacher, active, archived_at) ON school.staff TO school_runtime;
END IF; END $$;
