-- Credentials apart (SECURITY-REVIEW.md, findings F2 and F3). Password hashes, MFA secrets and
-- recovery codes are read and written by `platform_auth` only, the role of the sign-in and of
-- the person's own password and MFA. The schools' application (`school_runtime`) sees whether a
-- person has a password and MFA, never the secrets, and changes none of them: an SQL injection
-- in a school's screen reaches no credential. deploy/roles.sql grants the columns.
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'platform_auth') THEN
    CREATE ROLE platform_auth NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
  END IF;
END $$;

-- What an account's invitation needs to know: no password chosen yet.
ALTER TABLE public.users ADD COLUMN has_password boolean GENERATED ALWAYS AS (password IS NOT NULL) STORED;
