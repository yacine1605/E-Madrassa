-- Dedicated deployment: binds this database to exactly one school.
CREATE TABLE school.deployment (id integer PRIMARY KEY CHECK (id = 1), institution_id uuid NOT NULL UNIQUE REFERENCES school.institutions(id));
DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='school_runtime') THEN
  GRANT SELECT ON school.deployment TO school_runtime;
END IF; END $$;
