ALTER TABLE school.staff ADD COLUMN role text;
DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='school_runtime') THEN
  GRANT UPDATE (role) ON school.staff TO school_runtime;
END IF; END $$;
