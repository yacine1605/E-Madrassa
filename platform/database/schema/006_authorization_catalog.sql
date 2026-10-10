-- Annex A permissions, the school roles of section 3.2 with their default permissions
-- (annex A and the access matrix of section 3.3), and the segregation-of-duties rules.

INSERT INTO platform.permission (code, module_key, sensitive, labels) VALUES
  ('tenant.settings.manage', 'core', false, '{"fr": "Gérer les paramètres de l''établissement"}'),
  ('referential.node.read', 'core', false, '{"fr": "Lire le référentiel"}'),
  ('referential.node.manage', 'core', false, '{"fr": "Gérer le référentiel"}'),
  ('custom_field.definition.manage', 'core', false, '{"fr": "Gérer les champs personnalisés"}'),
  ('user.account.read', 'core', false, '{"fr": "Lire les comptes"}'),
  ('user.account.create', 'core', false, '{"fr": "Créer des comptes"}'),
  ('user.account.update', 'core', false, '{"fr": "Modifier des comptes"}'),
  ('user.account.suspend', 'core', false, '{"fr": "Suspendre des comptes"}'),
  ('role.definition.manage', 'core', true, '{"fr": "Gérer les rôles"}'),
  ('role.assignment.manage', 'core', true, '{"fr": "Gérer les assignations de rôles"}'),
  ('audit.log.read', 'core', true, '{"fr": "Lire le journal d''audit"}'),
  ('import.job.create', 'core', false, '{"fr": "Lancer un import"}'),
  ('import.job.read', 'core', false, '{"fr": "Suivre les imports"}'),
  ('export.job.create', 'core', false, '{"fr": "Lancer un export"}'),
  ('subject.subject.manage', 'core', false, '{"fr": "Gérer le catalogue des matières et modules"}'),
  ('student.record.read', 'students_crm', false, '{"fr": "Lire les dossiers élèves"}'),
  ('student.record.create', 'students_crm', false, '{"fr": "Créer des dossiers élèves"}'),
  ('student.record.update', 'students_crm', false, '{"fr": "Modifier des dossiers élèves"}'),
  ('student.record.archive', 'students_crm', false, '{"fr": "Archiver des dossiers élèves"}'),
  ('student.sensitive.read', 'students_crm', true, '{"fr": "Lire les champs sensibles des élèves"}'),
  ('guardian.record.read', 'students_crm', false, '{"fr": "Lire les responsables légaux"}'),
  ('guardian.record.create', 'students_crm', false, '{"fr": "Créer des responsables légaux"}'),
  ('guardian.record.update', 'students_crm', false, '{"fr": "Modifier des responsables légaux"}'),
  ('crm.lead.read', 'students_crm', false, '{"fr": "Lire les prospects"}'),
  ('crm.lead.create', 'students_crm', false, '{"fr": "Créer des prospects"}'),
  ('crm.lead.update', 'students_crm', false, '{"fr": "Modifier des prospects"}'),
  ('crm.lead.convert', 'students_crm', false, '{"fr": "Convertir un prospect en élève"}'),
  ('program.program.read', 'enrollment_groups', false, '{"fr": "Lire les programmes"}'),
  ('program.program.manage', 'enrollment_groups', false, '{"fr": "Gérer les programmes et tarifs"}'),
  ('program.subject.manage', 'enrollment_groups', false, '{"fr": "Gérer les matières et volumes d''un programme"}'),
  ('group.group.read', 'enrollment_groups', false, '{"fr": "Lire les groupes"}'),
  ('group.group.manage', 'enrollment_groups', false, '{"fr": "Gérer les groupes"}'),
  ('group.membership.manage', 'enrollment_groups', false, '{"fr": "Gérer la composition des groupes"}'),
  ('enrollment.enrollment.read', 'enrollment_groups', false, '{"fr": "Lire les inscriptions"}'),
  ('enrollment.enrollment.create', 'enrollment_groups', false, '{"fr": "Créer des inscriptions"}'),
  ('enrollment.enrollment.update', 'enrollment_groups', false, '{"fr": "Modifier des inscriptions"}'),
  ('enrollment.enrollment.validate', 'enrollment_groups', false, '{"fr": "Valider des inscriptions"}'),
  ('enrollment.enrollment.suspend', 'enrollment_groups', false, '{"fr": "Suspendre des inscriptions"}'),
  ('enrollment.enrollment.cancel', 'enrollment_groups', false, '{"fr": "Annuler des inscriptions"}'),
  ('enrollment.progression.decide', 'enrollment_groups', false, '{"fr": "Décider du passage annuel"}'),
  ('site.site.manage', 'sites_rooms', false, '{"fr": "Gérer les sites"}'),
  ('room.room.manage', 'sites_rooms', false, '{"fr": "Gérer les salles"}'),
  ('staff.profile.read', 'staff', false, '{"fr": "Lire les fiches du personnel"}'),
  ('staff.profile.create', 'staff', false, '{"fr": "Créer des fiches du personnel"}'),
  ('staff.profile.update', 'staff', false, '{"fr": "Modifier des fiches du personnel"}'),
  ('staff.qualification.manage', 'staff', false, '{"fr": "Gérer les habilitations"}'),
  ('staff.availability.manage', 'staff', false, '{"fr": "Gérer les disponibilités"}'),
  ('staff.availability.approve', 'staff', false, '{"fr": "Approuver les disponibilités"}'),
  ('staff.contract.read', 'staff', false, '{"fr": "Lire les contrats (sans montants)"}'),
  ('staff.contract.manage', 'staff', false, '{"fr": "Gérer les contrats (sans montants)"}'),
  ('staff.leave.read', 'staff', false, '{"fr": "Lire les congés et absences"}'),
  ('staff.leave.manage', 'staff', false, '{"fr": "Gérer les congés et absences"}'),
  ('schedule.session.read', 'scheduling_manual', false, '{"fr": "Lire le planning"}'),
  ('schedule.session.create', 'scheduling_manual', false, '{"fr": "Créer des séances"}'),
  ('schedule.session.update', 'scheduling_manual', false, '{"fr": "Modifier des séances"}'),
  ('schedule.session.cancel', 'scheduling_manual', false, '{"fr": "Annuler des séances"}'),
  ('schedule.timetable.publish', 'scheduling_manual', false, '{"fr": "Publier un planning"}'),
  ('attendance.teacher.entry.create', 'attendance', false, '{"fr": "Saisir le pointage des enseignants"}'),
  ('attendance.teacher.entry.update', 'attendance', false, '{"fr": "Corriger le pointage des enseignants"}'),
  ('attendance.teacher.validation.approve', 'attendance', true, '{"fr": "Valider le pointage des enseignants"}'),
  ('attendance.student.entry.create', 'attendance', false, '{"fr": "Faire l''appel des élèves"}'),
  ('attendance.student.entry.update', 'attendance', false, '{"fr": "Corriger l''appel des élèves"}'),
  ('hours.validated.read', 'attendance', false, '{"fr": "Lire les heures assurées validées"}'),
  ('finance.payment.read', 'student_finance', false, '{"fr": "Lire les paiements"}'),
  ('finance.payment.create', 'student_finance', false, '{"fr": "Saisir un paiement"}'),
  ('finance.payment.create.delegated', 'student_finance', true, '{"fr": "Encaisser sur délégation"}'),
  ('finance.payment.validate', 'student_finance', true, '{"fr": "Valider un paiement"}'),
  ('finance.payment.reverse', 'student_finance', true, '{"fr": "Annuler un paiement par contre-opération"}'),
  ('finance.discount.grant', 'student_finance', true, '{"fr": "Accorder une remise"}'),
  ('finance.schedule.manage', 'student_finance', true, '{"fr": "Gérer les échéanciers"}'),
  ('finance.refund.request', 'student_finance', true, '{"fr": "Demander un remboursement"}'),
  ('finance.refund.approve', 'student_finance', true, '{"fr": "Approuver un remboursement"}'),
  ('finance.period.close', 'student_finance', true, '{"fr": "Clôturer une période financière"}'),
  ('finance.period.reopen', 'student_finance', true, '{"fr": "Rouvrir une période financière"}'),
  ('expense.expense.read', 'expenses', false, '{"fr": "Lire les dépenses"}'),
  ('expense.expense.create', 'expenses', false, '{"fr": "Saisir des dépenses"}'),
  ('expense.expense.update', 'expenses', false, '{"fr": "Modifier des dépenses"}'),
  ('expense.expense.validate', 'expenses', true, '{"fr": "Valider des dépenses"}'),
  ('expense.expense.pay', 'expenses', true, '{"fr": "Payer des dépenses"}'),
  ('expense.expense.reverse', 'expenses', true, '{"fr": "Corriger des dépenses par contre-opération"}'),
  ('expense.attachment.read', 'expenses', true, '{"fr": "Lire les justificatifs"}'),
  ('payroll.rule.manage', 'payroll', true, '{"fr": "Gérer les règles de rémunération"}'),
  ('payroll.amount.read', 'payroll', true, '{"fr": "Voir les montants salariaux"}'),
  ('payroll.run.generate', 'payroll', true, '{"fr": "Générer les rémunérations"}'),
  ('payroll.run.validate', 'payroll', true, '{"fr": "Valider les rémunérations"}'),
  ('payroll.payout.record', 'payroll', true, '{"fr": "Enregistrer un versement"}'),
  ('payroll.self_view', 'payroll', false, '{"fr": "Voir ses propres paiements"}'),
  ('dashboard.finance.read', 'dashboards', false, '{"fr": "Tableau de bord financier"}'),
  ('dashboard.pedagogy.read', 'dashboards', false, '{"fr": "Tableau de bord pédagogique"}'),
  ('dashboard.global.read', 'dashboards', false, '{"fr": "Tableau de bord global"}'),
  ('dashboard.operations.read', 'dashboards', false, '{"fr": "Tableau de bord opérationnel"}'),
  ('stock.item.manage', 'stock', false, '{"fr": "Gérer les articles"}'),
  ('stock.movement.create', 'stock', false, '{"fr": "Enregistrer des mouvements de stock"}'),
  ('stock.inventory.manage', 'stock', false, '{"fr": "Gérer les inventaires"}');

INSERT INTO platform.role_template (code, kind, labels, initial_admin, sort_order) VALUES
  ('tenant_admin', 'internal', '{"fr": "Administrateur de l''établissement"}', true, 1),
  ('direction', 'internal', '{"fr": "Direction"}', false, 2),
  ('academic_hr', 'internal', '{"fr": "Responsable pédagogique et RH"}', false, 3),
  ('supervisor', 'internal', '{"fr": "Surveillant général"}', false, 4),
  ('front_office', 'internal', '{"fr": "Secrétariat et admission"}', false, 5),
  ('accountant', 'internal', '{"fr": "Comptable / financier"}', false, 6),
  ('stock_manager', 'internal', '{"fr": "Gestionnaire de stock"}', false, 7),
  ('teacher', 'internal', '{"fr": "Enseignant / formateur"}', false, 8),
  ('student', 'student', '{"fr": "Élève"}', false, 9),
  ('parent', 'parent', '{"fr": "Parent"}', false, 10);

INSERT INTO platform.role_template_permission (role_code, permission_code)
SELECT 'tenant_admin', unnest(ARRAY['tenant.settings.manage', 'referential.node.read', 'referential.node.manage',
  'custom_field.definition.manage', 'user.account.read', 'user.account.create', 'user.account.update', 'user.account.suspend',
  'role.definition.manage', 'role.assignment.manage', 'audit.log.read', 'import.job.create', 'import.job.read', 'export.job.create',
  'subject.subject.manage', 'program.subject.manage', 'program.program.read', 'site.site.manage', 'room.room.manage'])
UNION ALL SELECT 'direction', unnest(ARRAY['referential.node.read', 'audit.log.read', 'export.job.create', 'student.record.read',
  'student.sensitive.read', 'program.program.read', 'group.group.read', 'enrollment.enrollment.read', 'enrollment.enrollment.validate',
  'enrollment.enrollment.suspend', 'enrollment.enrollment.cancel', 'enrollment.progression.decide', 'staff.profile.read',
  'staff.contract.read', 'staff.leave.read', 'schedule.session.read', 'hours.validated.read', 'finance.payment.read',
  'finance.refund.approve', 'finance.period.close', 'finance.period.reopen', 'expense.expense.read', 'expense.expense.validate',
  'expense.expense.pay', 'expense.expense.reverse', 'expense.attachment.read', 'payroll.amount.read', 'payroll.run.validate',
  'dashboard.global.read', 'dashboard.finance.read', 'dashboard.pedagogy.read'])
UNION ALL SELECT 'academic_hr', unnest(ARRAY['referential.node.read', 'import.job.create', 'import.job.read', 'export.job.create',
  'student.record.read', 'student.record.create', 'student.record.update', 'student.record.archive', 'student.sensitive.read',
  'guardian.record.read', 'guardian.record.create', 'guardian.record.update', 'program.program.read', 'program.program.manage',
  'subject.subject.manage', 'program.subject.manage', 'group.group.read', 'group.group.manage', 'group.membership.manage',
  'enrollment.enrollment.read', 'enrollment.enrollment.create', 'enrollment.enrollment.update', 'enrollment.enrollment.validate',
  'enrollment.enrollment.suspend', 'enrollment.enrollment.cancel', 'enrollment.progression.decide', 'site.site.manage',
  'room.room.manage', 'staff.profile.read', 'staff.profile.create', 'staff.profile.update', 'staff.qualification.manage',
  'staff.availability.manage', 'staff.availability.approve', 'staff.contract.read', 'staff.contract.manage', 'staff.leave.read',
  'staff.leave.manage', 'schedule.session.read', 'schedule.session.create', 'schedule.session.update', 'schedule.session.cancel',
  'schedule.timetable.publish', 'attendance.teacher.validation.approve', 'hours.validated.read', 'dashboard.pedagogy.read'])
UNION ALL SELECT 'supervisor', unnest(ARRAY['referential.node.read', 'student.record.read', 'program.program.read', 'group.group.read',
  'schedule.session.read', 'attendance.teacher.entry.create', 'attendance.teacher.entry.update', 'attendance.student.entry.create',
  'attendance.student.entry.update', 'dashboard.operations.read'])
UNION ALL SELECT 'front_office', unnest(ARRAY['referential.node.read', 'import.job.create', 'import.job.read', 'export.job.create',
  'student.record.read', 'student.record.create', 'student.record.update', 'student.record.archive', 'guardian.record.read',
  'guardian.record.create', 'guardian.record.update', 'crm.lead.read', 'crm.lead.create', 'crm.lead.update', 'crm.lead.convert',
  'program.program.read', 'group.group.read', 'group.membership.manage', 'enrollment.enrollment.read', 'enrollment.enrollment.create',
  'enrollment.enrollment.update', 'schedule.session.read', 'dashboard.operations.read'])
UNION ALL SELECT 'accountant', unnest(ARRAY['referential.node.read', 'audit.log.read', 'import.job.create', 'import.job.read',
  'export.job.create', 'student.record.read', 'program.program.read', 'enrollment.enrollment.read', 'staff.profile.read',
  'finance.payment.read', 'finance.payment.create', 'finance.payment.validate', 'finance.payment.reverse', 'finance.discount.grant',
  'finance.schedule.manage', 'finance.refund.request', 'finance.period.close', 'expense.expense.read', 'expense.expense.create',
  'expense.expense.update', 'expense.expense.validate', 'expense.expense.pay', 'expense.expense.reverse', 'expense.attachment.read',
  'payroll.rule.manage', 'payroll.amount.read', 'payroll.run.generate', 'payroll.payout.record', 'hours.validated.read',
  'dashboard.finance.read'])
UNION ALL SELECT 'stock_manager', unnest(ARRAY['referential.node.read', 'import.job.create', 'import.job.read', 'export.job.create',
  'stock.item.manage', 'stock.movement.create', 'stock.inventory.manage'])
UNION ALL SELECT 'teacher', unnest(ARRAY['referential.node.read', 'program.program.read', 'group.group.read', 'schedule.session.read',
  'attendance.student.entry.create', 'attendance.student.entry.update', 'staff.availability.manage', 'hours.validated.read',
  'payroll.self_view'])
UNION ALL SELECT 'student', unnest(ARRAY['referential.node.read', 'schedule.session.read'])
UNION ALL SELECT 'parent', unnest(ARRAY['referential.node.read', 'schedule.session.read']);

INSERT INTO platform.sod_rule (entity, entry_action, approve_action, exception_setting) VALUES
  ('teacher_attendance', 'attendance.teacher.entry.create', 'attendance.teacher.validation.approve', NULL),
  ('expense', 'expense.expense.create', 'expense.expense.validate', NULL),
  ('refund', 'finance.refund.request', 'finance.refund.approve', NULL),
  ('payment_reversal', 'finance.payment.reverse', 'finance.payment.validate', NULL),
  ('payroll_run', 'payroll.run.generate', 'payroll.run.validate', NULL),
  ('finance_period', 'finance.period.close', 'finance.period.reopen', NULL),
  ('payment', 'finance.payment.create', 'finance.payment.validate', 'finance.allow_self_validation');

-- FR-PLT-01 and FR-IAM-03: a new school also receives its own copy of the roles, and its
-- invited administrator the initial administrator role, for the whole school.
CREATE OR REPLACE FUNCTION platform.create_tenant(p_creation_key uuid, p_slug text, p_name text, p_default_locale text, p_plan text,
  p_valid_from date, p_valid_until date, p_quota_active_students integer, p_quota_storage_mb integer,
  p_site_code text, p_site_name text, p_admin_user_id bigint) RETURNS uuid
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
