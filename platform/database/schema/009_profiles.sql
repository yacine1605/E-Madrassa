-- The publisher's profiles (FR-PLT-03), version 1, and school creation applying one.
-- Labels of private_school_dz await validation by the school (OD-13).

INSERT INTO platform.profile (code, version, labels, subject_kind) VALUES
  ('private_school_dz', 1, '{"fr": "École privée (Algérie)", "ar": "مدرسة خاصة (الجزائر)", "en": "Private school (Algeria)"}', 'subject'),
  ('training_center', 1, '{"fr": "Centre de formation", "ar": "مركز تكوين", "en": "Training centre"}', 'module');

INSERT INTO platform.profile_node_type (profile_code, version, code, parent_code, labels, level_order, is_required) VALUES
  ('private_school_dz', 1, 'cycle', NULL, '{"fr": "Cycle", "ar": "الطور", "en": "Cycle"}', 1, true),
  ('private_school_dz', 1, 'grade', 'cycle', '{"fr": "Niveau", "ar": "المستوى", "en": "Grade"}', 2, true),
  ('private_school_dz', 1, 'track', 'grade', '{"fr": "Filière", "ar": "الشعبة", "en": "Track"}', 3, false),
  ('training_center', 1, 'domain', NULL, '{"fr": "Domaine", "ar": "المجال", "en": "Domain"}', 1, true),
  ('training_center', 1, 'specialty', 'domain', '{"fr": "Spécialité", "ar": "التخصص", "en": "Specialty"}', 2, true),
  ('training_center', 1, 'pathway', 'specialty', '{"fr": "Parcours", "ar": "المسار", "en": "Pathway"}', 3, false);

-- The four cycles; the preschool has sections and neither tracks nor compulsory subjects.
INSERT INTO platform.profile_node (profile_code, version, type_code, code, parent_code, labels, sort_order) VALUES
  ('private_school_dz', 1, 'cycle', 'PRESCOLAIRE', NULL, '{"fr": "Préscolaire", "ar": "التعليم التحضيري", "en": "Preschool"}', 1),
  ('private_school_dz', 1, 'cycle', 'PRIMAIRE', NULL, '{"fr": "Primaire", "ar": "التعليم الابتدائي", "en": "Primary school"}', 2),
  ('private_school_dz', 1, 'cycle', 'MOYEN', NULL, '{"fr": "Moyen", "ar": "التعليم المتوسط", "en": "Middle school"}', 3),
  ('private_school_dz', 1, 'cycle', 'SECONDAIRE', NULL, '{"fr": "Secondaire", "ar": "التعليم الثانوي", "en": "Secondary school"}', 4),
  ('private_school_dz', 1, 'grade', 'PS', 'PRESCOLAIRE', '{"fr": "Petite section", "ar": "القسم الصغير"}', 1),
  ('private_school_dz', 1, 'grade', 'MS', 'PRESCOLAIRE', '{"fr": "Moyenne section", "ar": "القسم المتوسط"}', 2),
  ('private_school_dz', 1, 'grade', 'GS', 'PRESCOLAIRE', '{"fr": "Grande section", "ar": "القسم الكبير"}', 3),
  ('private_school_dz', 1, 'grade', '1AP', 'PRIMAIRE', '{"fr": "1re année primaire", "ar": "السنة الأولى ابتدائي"}', 1),
  ('private_school_dz', 1, 'grade', '2AP', 'PRIMAIRE', '{"fr": "2e année primaire", "ar": "السنة الثانية ابتدائي"}', 2),
  ('private_school_dz', 1, 'grade', '3AP', 'PRIMAIRE', '{"fr": "3e année primaire", "ar": "السنة الثالثة ابتدائي"}', 3),
  ('private_school_dz', 1, 'grade', '4AP', 'PRIMAIRE', '{"fr": "4e année primaire", "ar": "السنة الرابعة ابتدائي"}', 4),
  ('private_school_dz', 1, 'grade', '5AP', 'PRIMAIRE', '{"fr": "5e année primaire", "ar": "السنة الخامسة ابتدائي"}', 5),
  ('private_school_dz', 1, 'grade', '1AM', 'MOYEN', '{"fr": "1re année moyenne", "ar": "السنة الأولى متوسط"}', 1),
  ('private_school_dz', 1, 'grade', '2AM', 'MOYEN', '{"fr": "2e année moyenne", "ar": "السنة الثانية متوسط"}', 2),
  ('private_school_dz', 1, 'grade', '3AM', 'MOYEN', '{"fr": "3e année moyenne", "ar": "السنة الثالثة متوسط"}', 3),
  ('private_school_dz', 1, 'grade', '4AM', 'MOYEN', '{"fr": "4e année moyenne", "ar": "السنة الرابعة متوسط"}', 4),
  ('private_school_dz', 1, 'grade', '1AS', 'SECONDAIRE', '{"fr": "1re année secondaire", "ar": "السنة الأولى ثانوي"}', 1),
  ('private_school_dz', 1, 'grade', '2AS', 'SECONDAIRE', '{"fr": "2e année secondaire", "ar": "السنة الثانية ثانوي"}', 2),
  ('private_school_dz', 1, 'grade', '3AS', 'SECONDAIRE', '{"fr": "3e année secondaire", "ar": "السنة الثالثة ثانوي"}', 3),
  ('private_school_dz', 1, 'track', '1AS_TCST', '1AS', '{"fr": "Tronc commun sciences et technologie", "ar": "جذع مشترك علوم وتكنولوجيا"}', 1),
  ('private_school_dz', 1, 'track', '1AS_TCL', '1AS', '{"fr": "Tronc commun lettres", "ar": "جذع مشترك آداب"}', 2),
  ('private_school_dz', 1, 'track', '2AS_SE', '2AS', '{"fr": "Sciences expérimentales", "ar": "علوم تجريبية"}', 1),
  ('private_school_dz', 1, 'track', '2AS_M', '2AS', '{"fr": "Mathématiques", "ar": "رياضيات"}', 2),
  ('private_school_dz', 1, 'track', '2AS_TM', '2AS', '{"fr": "Technique mathématique", "ar": "تقني رياضي"}', 3),
  ('private_school_dz', 1, 'track', '2AS_GE', '2AS', '{"fr": "Gestion et économie", "ar": "تسيير واقتصاد"}', 4),
  ('private_school_dz', 1, 'track', '2AS_LP', '2AS', '{"fr": "Lettres et philosophie", "ar": "آداب وفلسفة"}', 5),
  ('private_school_dz', 1, 'track', '2AS_LE', '2AS', '{"fr": "Langues étrangères", "ar": "لغات أجنبية"}', 6),
  ('private_school_dz', 1, 'track', '3AS_SE', '3AS', '{"fr": "Sciences expérimentales", "ar": "علوم تجريبية"}', 1),
  ('private_school_dz', 1, 'track', '3AS_M', '3AS', '{"fr": "Mathématiques", "ar": "رياضيات"}', 2),
  ('private_school_dz', 1, 'track', '3AS_TM', '3AS', '{"fr": "Technique mathématique", "ar": "تقني رياضي"}', 3),
  ('private_school_dz', 1, 'track', '3AS_GE', '3AS', '{"fr": "Gestion et économie", "ar": "تسيير واقتصاد"}', 4),
  ('private_school_dz', 1, 'track', '3AS_LP', '3AS', '{"fr": "Lettres et philosophie", "ar": "آداب وفلسفة"}', 5),
  ('private_school_dz', 1, 'track', '3AS_LE', '3AS', '{"fr": "Langues étrangères", "ar": "لغات أجنبية"}', 6);

-- One entry per subject, whatever the level (the hours of each level belong to programs,
-- FR-PRG-02); from madrassa-backend/config/algerian_curriculum.php, codes in the SRS format.
INSERT INTO platform.profile_subject (profile_code, version, code, labels, sort_order) VALUES
  ('private_school_dz', 1, 'AR', '{"fr": "Langue arabe", "ar": "اللغة العربية"}', 1),
  ('private_school_dz', 1, 'AR_LIT', '{"fr": "Langue et littérature arabes", "ar": "اللغة العربية وآدابها"}', 2),
  ('private_school_dz', 1, 'MATH', '{"fr": "Mathématiques", "ar": "الرياضيات"}', 3),
  ('private_school_dz', 1, 'MATH_PREP', '{"fr": "Éveil mathématique", "ar": "التهيئة للرياضيات"}', 4),
  ('private_school_dz', 1, 'FR', '{"fr": "Langue française", "ar": "اللغة الفرنسية"}', 5),
  ('private_school_dz', 1, 'EN', '{"fr": "Langue anglaise", "ar": "اللغة الإنجليزية"}', 6),
  ('private_school_dz', 1, 'ES', '{"fr": "Langue espagnole", "ar": "اللغة الإسبانية"}', 7),
  ('private_school_dz', 1, 'AMAZ', '{"fr": "Langue amazighe", "ar": "اللغة الأمازيغية"}', 8),
  ('private_school_dz', 1, 'ISLAM_EDU', '{"fr": "Éducation islamique", "ar": "التربية الإسلامية"}', 9),
  ('private_school_dz', 1, 'ISLAM_SCI', '{"fr": "Sciences islamiques", "ar": "العلوم الإسلامية"}', 10),
  ('private_school_dz', 1, 'CIV', '{"fr": "Éducation civique", "ar": "التربية المدنية"}', 11),
  ('private_school_dz', 1, 'HG', '{"fr": "Histoire et géographie", "ar": "التاريخ والجغرافيا"}', 12),
  ('private_school_dz', 1, 'HIST', '{"fr": "Histoire", "ar": "التاريخ"}', 13),
  ('private_school_dz', 1, 'SCI_TECH', '{"fr": "Éducation scientifique et technologique", "ar": "التربية العلمية والتكنولوجية"}', 14),
  ('private_school_dz', 1, 'SVT', '{"fr": "Sciences de la nature et de la vie", "ar": "علوم الطبيعة والحياة"}', 15),
  ('private_school_dz', 1, 'PHYS_TECH', '{"fr": "Sciences physiques et technologie", "ar": "العلوم الفيزيائية والتكنولوجيا"}', 16),
  ('private_school_dz', 1, 'PHYS', '{"fr": "Sciences physiques", "ar": "العلوم الفيزيائية"}', 17),
  ('private_school_dz', 1, 'TECH', '{"fr": "Technologie", "ar": "التكنولوجيا"}', 18),
  ('private_school_dz', 1, 'IT', '{"fr": "Informatique", "ar": "الإعلام الآلي"}', 19),
  ('private_school_dz', 1, 'SPORT', '{"fr": "Éducation physique et sportive", "ar": "التربية البدنية والرياضية"}', 20),
  ('private_school_dz', 1, 'ART', '{"fr": "Éducation artistique", "ar": "التربية الفنية"}', 21),
  ('private_school_dz', 1, 'ART_PLAST', '{"fr": "Arts plastiques", "ar": "التربية الفنية التشكيلية"}', 22),
  ('private_school_dz', 1, 'MUSIC', '{"fr": "Éducation musicale", "ar": "التربية الموسيقية"}', 23),
  ('private_school_dz', 1, 'ART_MOTOR', '{"fr": "Arts et motricité fine", "ar": "الفنون والمهارات الحركية الدقيقة"}', 24),
  ('private_school_dz', 1, 'DISCOVERY', '{"fr": "Découverte du monde et jeux éducatifs", "ar": "اكتشاف العالم والألعاب التربوية"}', 25),
  ('private_school_dz', 1, 'REST', '{"fr": "Repos et activités calmes", "ar": "الراحة والأنشطة الهادئة"}', 26),
  ('private_school_dz', 1, 'WORKSHOP', '{"fr": "Ateliers et activités dirigées", "ar": "ورشات وأنشطة موجهة"}', 27),
  ('private_school_dz', 1, 'SUPPORT', '{"fr": "Ateliers de soutien et activités dirigées", "ar": "ورشات الدعم والأنشطة الموجهة"}', 28);

UPDATE platform.profile SET published_at = now() WHERE version = 1;

-- FR-PLT-01 step 2: creating a school also applies its profile. Replaces the version of 006,
-- with the profile as last argument (none: an empty referential, applied later on request).
DROP FUNCTION platform.create_tenant(uuid, text, text, text, text, date, date, integer, integer, text, text, bigint);
CREATE FUNCTION platform.create_tenant(p_creation_key uuid, p_slug text, p_name text, p_default_locale text, p_plan text,
  p_valid_from date, p_valid_until date, p_quota_active_students integer, p_quota_storage_mb integer,
  p_site_code text, p_site_name text, p_admin_user_id bigint, p_profile text) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid; m text;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended(p_creation_key::text, 10));
  SELECT id INTO t FROM platform.tenant WHERE creation_key = p_creation_key;
  IF t IS NOT NULL THEN
    IF NOT EXISTS (SELECT 1 FROM platform.tenant WHERE id = t AND slug = p_slug AND name = p_name) THEN
      RAISE EXCEPTION 'creation_key_reused' USING ERRCODE = '23514';
    END IF;
    RETURN t;
  END IF;
  INSERT INTO platform.tenant (slug, name, default_locale, creation_key, created_by)
  VALUES (p_slug, p_name, p_default_locale, p_creation_key, school.actor()) RETURNING id INTO t;
  INSERT INTO platform.tenant_entitlement (tenant_id, plan_code, quota_active_students, quota_storage_mb, valid_from, valid_until)
  VALUES (t, p_plan, p_quota_active_students, p_quota_storage_mb, p_valid_from, p_valid_until);
  PERFORM platform.enable_module(t, 'core');
  FOR m IN SELECT module_key FROM platform.plan_module WHERE plan_code = p_plan LOOP
    PERFORM platform.enable_module(t, m);
  END LOOP;
  INSERT INTO school.site (tenant_id, code, labels, is_default) VALUES (t, p_site_code, jsonb_build_object('fr', p_site_name), true);
  IF p_profile IS NOT NULL THEN PERFORM platform.apply_profile(t, p_profile); END IF;
  INSERT INTO school.role (tenant_id, code, kind, labels, template_code)
  SELECT t, code, kind, labels, code FROM platform.role_template ORDER BY sort_order;
  INSERT INTO school.role_permission (tenant_id, role_id, permission_code)
  SELECT t, r.id, tp.permission_code FROM platform.role_template_permission tp
  JOIN school.role r ON r.tenant_id = t AND r.template_code = tp.role_code;
  IF p_admin_user_id IS NOT NULL THEN
    INSERT INTO school.membership (tenant_id, user_id, status) VALUES (t, p_admin_user_id, 'invited');
    INSERT INTO school.role_assignment (tenant_id, user_id, role_id, scope_type)
    SELECT t, p_admin_user_id, r.id, 'tenant' FROM school.role r
    JOIN platform.role_template rt ON rt.code = r.template_code AND rt.initial_admin
    WHERE r.tenant_id = t;
  END IF;
  RETURN t;
END $$;
