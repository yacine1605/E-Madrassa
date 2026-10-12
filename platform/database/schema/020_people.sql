-- People apart (SECURITY-REVIEW.md, the rest of finding F2). A school's application read every
-- person's name and email, in every school: the person signed in is read before a school is
-- chosen. Now that the database knows the person for sure (019), row-level security confines
-- `public.users`. A school's application reads the person of the request and, in the school the
-- request reaches, its members, whatever their state, and the publisher's operators, whom the
-- school's journal names. The sign-in's role still reads the credentials of whoever signs in.

-- The people of the school the transaction reaches, by their identifier only. With its owner's
-- rights, so that the membership's own policies, which ask for a permission, do not apply: the
-- journal names its authors to whoever may read it. Nobody outside a school.
CREATE VIEW school.people AS
  SELECT m.user_id FROM school.membership m WHERE m.tenant_id = (SELECT school.allowed_tenant())
  UNION ALL
  SELECT o.user_id FROM platform.operator o WHERE (SELECT school.allowed_tenant()) IS NOT NULL;

ALTER TABLE public.users ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.users FORCE ROW LEVEL SECURITY;
-- Checked for each row by an index, since a list of people may hold a whole school.
CREATE POLICY people ON public.users FOR SELECT TO school_runtime
  USING (id = (SELECT school.actor()) OR EXISTS (SELECT 1 FROM school.people p WHERE p.user_id = users.id));
-- The person changes their own language only; an administrator changes the others' accounts
-- through the school's functions (007).
CREATE POLICY own_language ON public.users FOR UPDATE TO school_runtime USING (id = (SELECT school.actor()));
-- The sign-in reads a person's credentials before knowing them in any school (018).
CREATE POLICY sign_in ON public.users TO platform_auth USING (true);

-- The recovery's email goes out in the person's language, which the application can no longer
-- read before the person is known. Replaces 007's, with the language.
DROP FUNCTION platform.issue_recovery(text, text);
CREATE FUNCTION platform.issue_recovery(p_email text, p_token_hash text) RETURNS TABLE (user_id bigint, email text, name text, locale text)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE person public.users;
BEGIN
  SELECT * INTO person FROM public.users x WHERE x.email = lower(btrim(p_email)) AND x.password IS NOT NULL;
  IF NOT FOUND THEN RETURN; END IF;
  UPDATE public.account_token SET expires_at = least(public.account_token.expires_at, now())
  WHERE public.account_token.user_id = person.id AND purpose = 'recovery' AND used_at IS NULL;
  INSERT INTO public.account_token (user_id, purpose, token_hash, expires_at) VALUES (person.id, 'recovery', p_token_hash, now() + interval '1 hour');
  RETURN QUERY SELECT person.id, person.email::text, person.name::text, person.locale;
END $$;
-- Granted again, and given to its owner, by deploy/roles.sql.
REVOKE EXECUTE ON FUNCTION platform.issue_recovery(text, text) FROM PUBLIC;
