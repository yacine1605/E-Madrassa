-- Run as database administrator AFTER migrations, on every deployment. Safe to run again.
-- Passwords are set apart (ALTER ROLE ... PASSWORD), never in this file.
ALTER ROLE school_runtime LOGIN;
ALTER ROLE platform_admin LOGIN;
ALTER ROLE platform_auth LOGIN;

-- A function runs only where this file grants it, never for everyone. PostgreSQL grants EXECUTE
-- to PUBLIC on every new function, and a per-schema default privilege cannot take that back
-- (001's did nothing): without this, the restricted roles could call every function of the
-- schemas they use, the publisher's definer functions included. Applied again on every
-- deployment, so that a function added later is closed too (SECURITY-REVIEW.md).
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA platform, school, secret FROM PUBLIC;
REVOKE ALL ON ALL PROCEDURES IN SCHEMA school, secret FROM PUBLIC;

-- Schools' application: its own school's data only, through RLS. No table ownership,
-- no BYPASSRLS, no memberships or audit entries written directly, no deletion.
GRANT USAGE ON SCHEMA school, platform TO school_runtime;
-- A person as the schools see them: no password hash, MFA secret or recovery code (018,
-- findings F2 and F3). Revoked first, so that a deployment also takes back what an older one
-- granted; the person changes only their language. Accounts, memberships and links are
-- written by the definer functions only (007).
REVOKE ALL ON public.users, public.mfa_recovery_code FROM school_runtime;
GRANT SELECT (id, name, email, email_verified_at, locale, must_change_password, password_changed_at, mfa_enabled_at, has_password,
  created_at, updated_at) ON public.users TO school_runtime;
GRANT UPDATE (locale, updated_at) ON public.users TO school_runtime;
-- Whose rows: the person, and the people of the school the request reaches (020, rest of F2).
GRANT SELECT ON school.people TO school_runtime;
GRANT SELECT ON school.membership, school.audit_log TO school_runtime;
GRANT SELECT, INSERT ON school.site TO school_runtime;
GRANT UPDATE (labels, version, archived_at) ON school.site TO school_runtime;
GRANT SELECT ON platform.tenant, platform.module, platform.module_dependency, platform.plan TO school_runtime;
-- The request's context, signed by the application and checked by school.context() (019, F7).
GRANT EXECUTE ON FUNCTION school.context(), school.tenant(), school.actor(), school.allowed_tenant(), school.allowed(uuid),
  school.module_enabled(text), school.my_schools(), school.my_modules(), platform.tenant_mode(uuid) TO school_runtime;
-- Roles and assignments: written by the application, checked by triggers (permission held,
-- never on oneself, scope, last administrator). A role is archived, never deleted (FR-IAM-03);
-- a permission is taken off a role by deleting the link, which the audit keeps.
GRANT SELECT ON platform.permission, platform.role_template, platform.role_template_permission, platform.sod_rule TO school_runtime;
GRANT SELECT ON school.role, school.role_permission, school.role_assignment TO school_runtime;
GRANT INSERT (tenant_id, code, kind, labels) ON school.role TO school_runtime;
GRANT UPDATE (labels, version, archived_at) ON school.role TO school_runtime;
GRANT INSERT (tenant_id, role_id, permission_code), DELETE ON school.role_permission TO school_runtime;
GRANT INSERT (tenant_id, user_id, role_id, scope_type, scope_ids, valid_from, valid_until) ON school.role_assignment TO school_runtime;
GRANT UPDATE (scope_type, scope_ids, valid_until, version, archived_at) ON school.role_assignment TO school_runtime;
GRANT EXECUTE ON FUNCTION school.is_system(), school.my_permissions(), school.can(text), school.can_everywhere(text),
  school.check_segregation(text, bigint, boolean) TO school_runtime;
GRANT EXECUTE ON FUNCTION school.create_account(text, text, text, text, text), school.update_account(bigint, text, text, text, text),
  school.reset_password(bigint, text), school.set_account_status(bigint, text, text), school.issue_activation(bigint, text),
  platform.issue_recovery(text, text), platform.redeem_token(text, text, text), platform.peek_token(text, text),
  platform.find_login(text, text),
  school.mfa_required(), school.setting_value(text) TO school_runtime;
-- Referential (008): the school's tree, subjects, years, periods and custom fields. Types come
-- from the profile and are only renamed or turned off; nodes, years and periods are archived,
-- never deleted; a subject is deleted only while nothing references it (FR-REF-03).
GRANT SELECT ON platform.profile, platform.profile_node_type, platform.profile_node, platform.profile_subject TO school_runtime;
GRANT SELECT ON school.node_type, school.ref_node, school.subject, school.academic_year, school.period, school.custom_field TO school_runtime;
GRANT UPDATE (labels, active, version) ON school.node_type TO school_runtime;
GRANT INSERT (tenant_id, type_code, parent_id, code, labels, sort_order, platform_code) ON school.ref_node TO school_runtime;
GRANT UPDATE (labels, sort_order, version, archived_at) ON school.ref_node TO school_runtime;
GRANT INSERT (tenant_id, code, kind, labels, description, sort_order), DELETE ON school.subject TO school_runtime;
GRANT UPDATE (labels, description, sort_order, version, archived_at) ON school.subject TO school_runtime;
GRANT INSERT (tenant_id, code, start_date, end_date, status) ON school.academic_year TO school_runtime;
GRANT UPDATE (start_date, end_date, status, version) ON school.academic_year TO school_runtime;
GRANT INSERT (tenant_id, year_id, code, labels, start_date, end_date, sort_order) ON school.period TO school_runtime;
GRANT UPDATE (labels, start_date, end_date, sort_order, version, archived_at) ON school.period TO school_runtime;
GRANT INSERT (tenant_id, entity, key, field_type, options, required, is_sensitive, labels, sort_order) ON school.custom_field TO school_runtime;
GRANT UPDATE (options, required, is_sensitive, labels, sort_order, version, archived_at) ON school.custom_field TO school_runtime;
GRANT EXECUTE ON FUNCTION school.label_key(text), school.valid_labels(jsonb), school.node_subtree(uuid[]), school.year_open(uuid),
  school.check_custom_values(text, jsonb), school.open_day(date) TO school_runtime;
-- Shared services (010): the journal read by domain (its policy decides), the user's own jobs,
-- notifications and preferences; emails leave through the outbox, worked by the definer functions.
GRANT SELECT ON platform.audit_domain, platform.notification_event TO school_runtime;
GRANT SELECT, INSERT (tenant_id, user_id, kind, idempotency_key, input) ON school.job TO school_runtime;
GRANT UPDATE (status, progress, result, error_code, error_message, cancel_requested_at) ON school.job TO school_runtime;
GRANT SELECT, UPDATE (read_at) ON school.notification TO school_runtime;
GRANT SELECT, INSERT, UPDATE (email) ON school.notification_preference TO school_runtime;
GRANT SELECT ON school.outbox TO school_runtime;
GRANT EXECUTE ON FUNCTION school.audit_readable_types(), school.notify(text, bigint[], jsonb), platform.claim_outbox(integer, integer),
  platform.finish_outbox(uuid, text), school.retry_delivery(uuid) TO school_runtime;
-- Files (011): the register, read and written within the categories the user's permissions
-- open (row-level security); the bytes are the application's, in private storage.
GRANT SELECT ON platform.file_category TO school_runtime;
GRANT SELECT, INSERT (tenant_id, category, storage_key, original_name, file_type, media_type, size_bytes, sha256, owner_type, owner_id, expires_at)
  ON school.file_object TO school_runtime;
GRANT UPDATE (owner_type, owner_id, archived_at, version) ON school.file_object TO school_runtime;
GRANT EXECUTE ON FUNCTION school.file_categories(text), school.storage_use(uuid), school.file_for_link(uuid, uuid),
  platform.expired_files(integer), platform.mark_purged(uuid) TO school_runtime;
-- Export log (012): written by the author of each export, never changed.
GRANT SELECT, INSERT (tenant_id, user_id, kind, format, filters, columns, row_count, file_id) ON school.export_log TO school_runtime;
-- Imports (013): batches and their rows, worked by their author; imported accounts nobody
-- used yet are discarded only through the definer function.
GRANT SELECT, INSERT (tenant_id, user_id, kind, file_id, options) ON school.import_batch TO school_runtime;
GRANT UPDATE (status, error_message) ON school.import_batch TO school_runtime;
GRANT SELECT, INSERT (tenant_id, batch_id, line, external_ref, data, outcome, action, error_code, error_message, target_type, target_id, previous)
  ON school.import_row TO school_runtime;
GRANT UPDATE (outcome, action, error_code, error_message, target_type, target_id, previous) ON school.import_row TO school_runtime;
GRANT EXECUTE ON FUNCTION school.discard_account(bigint) TO school_runtime;
-- Support access (015): consents written by the school; a support session reads under one.
GRANT SELECT, INSERT (tenant_id, include_sensitive, reason, starts_at, expires_at) ON school.support_grant TO school_runtime;
GRANT UPDATE (revoked_at, version) ON school.support_grant TO school_runtime;
GRANT EXECUTE ON FUNCTION school.support_grant_in_use(), school.log_support_access(text) TO school_runtime;
-- Languages (016): the person chooses their own (granted above); the rule resolving it, the search key.
GRANT EXECUTE ON FUNCTION platform.locale_for(bigint, uuid), school.search_key(text) TO school_runtime;
-- Settings: a school stores the values it changes, within the catalog's bounds.
GRANT SELECT ON platform.setting_definition TO school_runtime;
GRANT SELECT, INSERT (tenant_id, key, value) ON school.setting TO school_runtime;
GRANT UPDATE (value, version, archived_at) ON school.setting TO school_runtime;
-- Pupils, guardians, programmes, groups, enrolments (021). Archived, never deleted (BR-02); the
-- matricule, a price version, an enrolment's price and state history are written by the database.
GRANT SELECT ON school.student, school.guardian, school.student_guardian, school.program, school.program_price, school.study_group,
  school.enrollment, school.enrollment_status_history, school.group_membership TO school_runtime;
GRANT INSERT (tenant_id, matricule, last_name_ar, first_name_ar, last_name_lat, first_name_lat, birth_date, birth_place, gender, phone,
  email, address, status, status_reason, origin_school, custom, external_ref) ON school.student TO school_runtime;
GRANT UPDATE (last_name_ar, first_name_ar, last_name_lat, first_name_lat, birth_date, birth_place, gender, phone, email, address, status,
  status_reason, origin_school, custom, version, archived_at) ON school.student TO school_runtime;
-- A pupil's photo (026): two files the database accepts as theirs; it retires the previous ones.
GRANT UPDATE (photo_file_id, photo_thumb_file_id) ON school.student TO school_runtime;
GRANT INSERT (tenant_id, last_name_ar, first_name_ar, last_name_lat, first_name_lat, phone, email, profession, custom, external_ref)
  ON school.guardian TO school_runtime;
GRANT UPDATE (last_name_ar, first_name_ar, last_name_lat, first_name_lat, phone, email, profession, custom, version, archived_at)
  ON school.guardian TO school_runtime;
GRANT INSERT (tenant_id, student_id, guardian_id, relationship, is_primary_contact, is_payer, emergency_contact) ON school.student_guardian TO school_runtime;
GRANT UPDATE (relationship, is_primary_contact, is_payer, emergency_contact, version, archived_at) ON school.student_guardian TO school_runtime;
GRANT INSERT (tenant_id, year_id, node_id, code, labels, description, duration_value, duration_unit, base_price, payment_terms, status)
  ON school.program TO school_runtime;
GRANT UPDATE (labels, description, duration_value, duration_unit, base_price, payment_terms, status, version, archived_at) ON school.program TO school_runtime;
GRANT INSERT (tenant_id, year_id, code, labels, group_type, program_id, site_id, capacity, valid_from, valid_to, status) ON school.study_group TO school_runtime;
GRANT UPDATE (labels, capacity, valid_to, status, version, archived_at) ON school.study_group TO school_runtime;
GRANT INSERT (tenant_id, student_id, program_id, idempotency_key) ON school.enrollment TO school_runtime;
GRANT UPDATE (status, status_reason, version) ON school.enrollment TO school_runtime;
GRANT INSERT (tenant_id, enrollment_id, group_id, valid_from) ON school.group_membership TO school_runtime;
GRANT UPDATE (valid_to, version) ON school.group_membership TO school_runtime;
GRANT EXECUTE ON FUNCTION school.require(text), school.person_name_valid(text, text, text, text), school.enroll(uuid, uuid, uuid),
  school.move_to_group(uuid, uuid), school.next_matricule(uuid), school.core_ids(text, text), school.require_core(text, text, uuid),
  school.group_scope_valid(uuid, uuid[]) TO school_runtime;
GRANT EXECUTE ON FUNCTION school.student_enrollment_count(uuid) TO school_runtime;
-- Undoing an import of pupils, guardians or enrolments (025): what it created, nothing built on since.
GRANT EXECUTE ON FUNCTION school.discard_student(uuid), school.discard_guardian(uuid), school.discard_guardian_link(uuid),
  school.discard_enrollment(uuid) TO school_runtime;
-- A transfer from one programme to another (027): with the writer's rights, each step its permission.
GRANT EXECUTE ON FUNCTION school.transfer_enrollment(uuid, uuid, uuid, text, integer) TO school_runtime;
-- A programme's subjects (028): a line's programme, subject and first day stay; its volumes are
-- versioned by the database. A line never used is deleted (BR-02), as a subject of the catalogue.
GRANT SELECT ON school.program_subject, school.program_subject_volume TO school_runtime;
GRANT INSERT (tenant_id, program_id, subject_id, total_hours, weekly_hours, session_count, session_duration_minutes, coefficient,
  valid_from, valid_to) ON school.program_subject TO school_runtime;
GRANT UPDATE (total_hours, weekly_hours, session_count, session_duration_minutes, coefficient, valid_to, version) ON school.program_subject TO school_runtime;
GRANT DELETE ON school.program_subject TO school_runtime;
-- A pupil's site (029): set when they are recorded, within the writer's sites; moved with the update's grant on the new one.
GRANT INSERT (site_id) ON school.student, school.guardian TO school_runtime;
GRANT UPDATE (site_id) ON school.student, school.guardian TO school_runtime;
GRANT EXECUTE ON FUNCTION school.site_scope_ids(text, text) TO school_runtime;
-- Retention (030): what the next run will do, for whoever manages the settings; the daily run, by the scheduler.
GRANT EXECUTE ON FUNCTION school.retention_run(), school.retention_due(), platform.apply_retention(integer) TO school_runtime;
GRANT EXECUTE ON FUNCTION platform.apply_lead_retention(integer) TO school_runtime;
GRANT EXECUTE ON FUNCTION platform.apply_staff_retention(integer) TO school_runtime;
-- The prospects' import (043): a prospect keeps the reference of its file; one an import created is undone by its function.
GRANT INSERT (external_ref) ON school.lead TO school_runtime;
GRANT EXECUTE ON FUNCTION school.discard_lead(uuid) TO school_runtime;
-- A programme copied to a later year (044), with the caller's rights; the copy keeps its source.
GRANT INSERT (copied_from) ON school.program TO school_runtime;
GRANT EXECUTE ON FUNCTION school.copy_program(uuid, uuid) TO school_runtime;
-- Programmes and groups from files (045): one an import created is undone by its function.
GRANT EXECUTE ON FUNCTION school.discard_program(uuid), school.discard_group(uuid) TO school_runtime;
-- A pupil's history (047): their record's own journal entries, for whoever reads the pupil, without the values.
GRANT EXECUTE ON FUNCTION school.student_history(uuid) TO school_runtime;
-- What an enrolment owes (048): adjustments granted with their reason and decided once; schedules written whole, by version.
GRANT SELECT ON school.adjustment, school.payment_schedule, school.installment TO school_runtime;
GRANT INSERT (tenant_id, enrollment_id, kind, amount, reason, idempotency_key) ON school.adjustment TO school_runtime;
GRANT UPDATE (status, decision_reason, version) ON school.adjustment TO school_runtime;
GRANT INSERT (tenant_id, enrollment_id, version, total, reason, idempotency_key) ON school.payment_schedule TO school_runtime;
GRANT INSERT (tenant_id, schedule_id, seq, due_date, amount) ON school.installment TO school_runtime;
GRANT EXECUTE ON FUNCTION school.finance_in_scope(text, uuid), school.enrollment_due(uuid), school.current_schedule(uuid),
  school.set_schedule(uuid, jsonb, text, uuid) TO school_runtime;
-- Payments (049): recorded and corrected while they wait, validated or withdrawn once; reversed by a linked reversal.
GRANT SELECT ON school.payment TO school_runtime;
GRANT INSERT (tenant_id, enrollment_id, kind, reverses_payment_id, amount, paid_on, method_code, external_ref, overpayment, reason, idempotency_key)
  ON school.payment TO school_runtime;
GRANT UPDATE (amount, paid_on, method_code, external_ref, overpayment, reason, status, cancel_reason, version) ON school.payment TO school_runtime;
GRANT EXECUTE ON FUNCTION school.payment_balance(uuid, uuid), school.next_payment_reference(uuid), school.check_payment(school.payment),
  school.record_payment(uuid, numeric, date, text, text, boolean, uuid), school.reverse_payment(uuid, text, uuid) TO school_runtime;
-- Refunds (050): asked with their reason, decided by someone else, executed once approved; a carry-over pays the pupil's other enrolment.
GRANT SELECT ON school.refund TO school_runtime;
GRANT INSERT (tenant_id, enrollment_id, kind, target_enrollment_id, amount, reason, idempotency_key) ON school.refund TO school_runtime;
GRANT UPDATE (status, decision_reason, method_code, paid_on, external_ref, version) ON school.refund TO school_runtime;
GRANT INSERT (carried_from_refund_id) ON school.payment TO school_runtime;
GRANT EXECUTE ON FUNCTION school.request_refund(uuid, text, uuid, numeric, text, uuid), school.next_refund_reference(uuid) TO school_runtime;
-- Receipts (051): issued once by their function, read with their payment or refund, never changed.
GRANT SELECT ON school.receipt TO school_runtime;
GRANT EXECUTE ON FUNCTION school.issue_receipt(uuid, uuid) TO school_runtime;
-- Arrears (052): read as the reader's scope allows; the finance's daily reminder run by the scheduler.
GRANT EXECUTE ON FUNCTION school.arrears(date, uuid), platform.notify_installments(date) TO school_runtime;
-- Financial periods (053): closed by their function, reopened with a reason by someone else; closed months refuse their dates.
GRANT SELECT ON school.finance_period TO school_runtime;
GRANT UPDATE (status, reopen_reason, version) ON school.finance_period TO school_runtime;
GRANT EXECUTE ON FUNCTION school.close_period(date), school.reconciliation(date), school.period_closed(uuid, date) TO school_runtime;
-- A payment's document (054): set or replaced by its author while it waits; the replaced one archived by the database.
GRANT UPDATE (attachment_file_id) ON school.payment TO school_runtime;
-- Expenses (055): categories named by whoever validates for the whole school; an expense entered once per key,
-- corrected and submitted by its author, decided by someone else; never deleted.
GRANT SELECT ON school.expense_category, platform.expense_category_default, school.expense TO school_runtime;
GRANT INSERT (tenant_id, code, labels, needs_label, sort_order) ON school.expense_category TO school_runtime;
GRANT UPDATE (labels, needs_label, sort_order, version, archived_at) ON school.expense_category TO school_runtime;
GRANT INSERT (tenant_id, expense_date, amount, category_id, label, beneficiary, reason, program_id, idempotency_key) ON school.expense TO school_runtime;
GRANT UPDATE (expense_date, amount, category_id, label, beneficiary, reason, program_id, status, decision_reason, version) ON school.expense TO school_runtime;
GRANT EXECUTE ON FUNCTION school.check_expense(school.expense), school.expense_category_system(text),
  school.record_expense(date, numeric, uuid, text, text, text, uuid, uuid) TO school_runtime;
-- An expense's payments (056): paid by `expense.expense.pay`, a payment taken back by its linked reversal,
-- decided by someone else; never changed nor deleted.
GRANT SELECT ON school.expense_payment TO school_runtime;
GRANT INSERT (tenant_id, expense_id, kind, reverses_payment_id, amount, paid_on, method_code, external_ref, reason, idempotency_key)
  ON school.expense_payment TO school_runtime;
GRANT UPDATE (status, decision_reason, version) ON school.expense_payment TO school_runtime;
GRANT EXECUTE ON FUNCTION school.expense_paid(uuid, uuid), school.expense_payment_state(text, numeric, numeric),
  school.record_expense_payment(uuid, numeric, date, text, text, uuid), school.reverse_expense_payment(uuid, text, uuid) TO school_runtime;
-- An expense's corrections (057): a linked negative entry asked for by `expense.expense.reverse`, decided by someone
-- else; never changed nor deleted.
GRANT SELECT ON school.expense_correction TO school_runtime;
GRANT INSERT (tenant_id, expense_id, kind, amount, corrected_on, reason, idempotency_key) ON school.expense_correction TO school_runtime;
GRANT UPDATE (status, decision_reason, version) ON school.expense_correction TO school_runtime;
GRANT EXECUTE ON FUNCTION school.expense_committed(uuid, uuid), school.correct_expense(uuid, text, numeric, text, uuid) TO school_runtime;
-- Remuneration rules (058): versions from the first day of a month, their rates and bonuses, by `payroll.rule.manage`;
-- a version begun keeps them, one not begun is deleted.
GRANT SELECT, DELETE ON school.pay_rule, school.pay_rule_rate, school.pay_rule_bonus TO school_runtime;
GRANT INSERT (tenant_id, staff_id, kind, monthly_amount, variable_basis, included_quantity, valid_from, valid_to, idempotency_key) ON school.pay_rule TO school_runtime;
GRANT UPDATE (valid_to, end_reason, version) ON school.pay_rule TO school_runtime;
GRANT INSERT (tenant_id, pay_rule_id, node_id, unit_amount) ON school.pay_rule_rate TO school_runtime;
GRANT UPDATE (unit_amount, version) ON school.pay_rule_rate TO school_runtime;
GRANT INSERT (tenant_id, pay_rule_id, label, amount) ON school.pay_rule_bonus TO school_runtime;
GRANT UPDATE (label, amount, version) ON school.pay_rule_bonus TO school_runtime;
GRANT EXECUTE ON FUNCTION school.pay_month_start(), school.pay_staff(uuid, uuid), school.check_pay_rule(uuid, uuid),
  school.save_pay_rule(uuid, text, numeric, text, numeric, date, jsonb, jsonb, uuid), school.pay_rule_on(uuid, uuid, date),
  school.pay_rate(uuid, uuid, uuid) TO school_runtime;
-- Pays (059): a draft a member and month, generated by `payroll.run.generate` as often as asked, its units and
-- adjustments entered, its lines computed; validated by `payroll.run.validate`; never deleted, an adjustment never changed.
GRANT SELECT ON school.payroll_run, school.payroll_unit, school.payroll_adjustment, school.payroll_line TO school_runtime;
GRANT INSERT (tenant_id, staff_id, period, pay_rule_id, kind, monthly_amount, variable_basis, included_quantity, days_paid, days_in_month, computed_at)
  ON school.payroll_run TO school_runtime;
GRANT UPDATE (status, pay_rule_id, kind, monthly_amount, variable_basis, included_quantity, days_paid, days_in_month, fixed_amount, variable_amount,
  bonus_amount, adjustment_amount, net_amount, missing_rates, version) ON school.payroll_run TO school_runtime;
GRANT INSERT (tenant_id, run_id, node_id, worked_on, quantity), DELETE ON school.payroll_unit TO school_runtime;
GRANT INSERT (tenant_id, run_id, kind, amount, reason, corrects_run_id, idempotency_key) ON school.payroll_adjustment TO school_runtime;
GRANT INSERT (tenant_id, run_id, line_no, kind, node_id, node_code, node_labels, worked_on, quantity, included, unit_amount, amount, label, adjustment_id),
  DELETE ON school.payroll_line TO school_runtime;
GRANT EXECUTE ON FUNCTION school.lock_payroll_run(uuid, date), school.payroll_staff(uuid, uuid), school.payroll_basis(uuid, uuid, date),
  school.payroll_unit_kind(text, text), school.payroll_detail(uuid, uuid), school.payroll_stale(uuid, uuid), school.refresh_payroll_run(uuid, uuid),
  school.generate_payroll(date, uuid), school.save_payroll_units(uuid, jsonb), school.adjust_payroll(uuid, text, numeric, text, uuid, uuid) TO school_runtime;
-- Payouts (060): a validated pay paid by `payroll.payout.record`, once per key, never beyond its net; a payout taken back by
-- its linked reversal, decided by `payroll.run.validate`; its expense written by the system (BR-06). A member reads their own (FR-REM-05).
GRANT SELECT ON school.payroll_payout TO school_runtime;
GRANT INSERT (tenant_id, run_id, kind, reverses_payout_id, amount, paid_on, method_code, external_ref, reason, idempotency_key)
  ON school.payroll_payout TO school_runtime;
GRANT UPDATE (status, decision_reason, version) ON school.payroll_payout TO school_runtime;
GRANT EXECUTE ON FUNCTION school.payroll_paid(uuid, uuid), school.payroll_state(text, numeric, numeric), school.own_pay(uuid, text),
  school.record_payroll_payout(uuid, numeric, date, text, text, uuid), school.reverse_payroll_payout(uuid, text, uuid) TO school_runtime;
-- The calendar (061): dated exceptions for the whole school or a site and the grid of time slots, by `schedule.calendar.manage`;
-- an exception cancelled, a slot archived, never deleted; neither changes otherwise.
GRANT SELECT ON school.calendar_exception, school.time_slot TO school_runtime;
GRANT INSERT (tenant_id, site_id, kind, date_from, date_to, reason) ON school.calendar_exception TO school_runtime;
GRANT UPDATE (status, version) ON school.calendar_exception TO school_runtime;
GRANT INSERT (tenant_id, code, start_time, end_time) ON school.time_slot TO school_runtime;
GRANT UPDATE (version, archived_at) ON school.time_slot TO school_runtime;
GRANT EXECUTE ON FUNCTION school.day_open(date, uuid) TO school_runtime;
-- Sessions (062): a weekly series planned by `schedule.session.create` with its sessions, changed and ended through its
-- functions by `schedule.session.update`; a session changed on its own or cancelled with its reason, deleted by its series' end only.
GRANT SELECT ON school.session_series, school.class_session TO school_runtime;
GRANT INSERT (tenant_id, group_id, subject_id, teacher_id, room_id, weekday, start_time, end_time, slot_id, date_from, date_to, idempotency_key)
  ON school.session_series TO school_runtime;
GRANT UPDATE (teacher_id, room_id, start_time, end_time, slot_id, date_to, version) ON school.session_series TO school_runtime;
GRANT INSERT (tenant_id, group_id, subject_id, teacher_id, room_id, series_id, starts_at, ends_at) ON school.class_session TO school_runtime;
GRANT UPDATE (teacher_id, room_id, starts_at, ends_at, status, cancel_reason, version) ON school.class_session TO school_runtime;
GRANT DELETE ON school.class_session TO school_runtime;
GRANT EXECUTE ON FUNCTION school.local_day(timestamptz), school.local_at(date, time),
  school.session_conflicts(uuid, uuid, uuid, uuid, timestamptz, timestamptz, uuid), school.series_days(integer, date, date),
  school.series_conflicts(uuid, uuid, uuid, uuid, integer, time, time, date, date),
  school.plan_series(uuid, uuid, uuid, uuid, integer, time, time, uuid, date, date, uuid),
  school.change_series(uuid, date, uuid, uuid, time, time, integer), school.end_series(uuid, date, integer), school.session_volume(uuid),
  school.sessions_planned_on(uuid, date, date) TO school_runtime;
-- Replacements (064): a session's teacher replaced with its reason; the history of the sessions' changes read as the sessions
-- are, written by the database alone.
GRANT UPDATE (replace_reason) ON school.class_session TO school_runtime;
GRANT SELECT ON school.session_change TO school_runtime;
GRANT EXECUTE ON FUNCTION school.substitutes(uuid) TO school_runtime;
-- Staff (031): a record with or without an account (P7), on a site; its number given or made once; archived after
-- leaving, never deleted (BR-02). The dates of a departure and the number made are the database's.
GRANT SELECT ON school.staff TO school_runtime;
GRANT INSERT (tenant_id, staff_number, last_name_ar, first_name_ar, last_name_lat, first_name_lat, staff_type, phone, email, hire_date,
  site_id, custom, user_id) ON school.staff TO school_runtime;
GRANT UPDATE (last_name_ar, first_name_ar, last_name_lat, first_name_lat, staff_type, phone, email, hire_date, status, left_on, site_id,
  custom, user_id, version, archived_at) ON school.staff TO school_runtime;
GRANT EXECUTE ON FUNCTION school.staff_ids(text), school.require_staff(text, uuid), school.next_staff_number(uuid) TO school_runtime;
-- A teacher's qualifications (032): a line keeps its teacher, subject, node and first day; it ends, or is deleted while
-- nothing uses it (a configuration line, as a programme's subject). Its coverage of a node's descendants is computed.
GRANT SELECT, DELETE ON school.qualification TO school_runtime;
GRANT INSERT (tenant_id, staff_id, subject_id, node_id, valid_from, valid_to) ON school.qualification TO school_runtime;
GRANT UPDATE (valid_to, version) ON school.qualification TO school_runtime;
GRANT EXECUTE ON FUNCTION school.qualified(uuid, uuid, uuid, date) TO school_runtime;
-- A group's lead teacher (033): named with the group, among the teachers the writer reads.
GRANT INSERT (lead_teacher_id), UPDATE (lead_teacher_id) ON school.study_group TO school_runtime;
-- A teacher's availability (034): a declaration or a day off is decided once (status and its reason), withdrawn while
-- pending; one in force never changes. Its state, its decision's author and day are the database's.
GRANT SELECT, DELETE ON school.availability, school.unavailability TO school_runtime;
GRANT INSERT (tenant_id, staff_id, valid_from, slots, min_weekly_hours, max_daily_hours, max_weekly_hours) ON school.availability TO school_runtime;
GRANT INSERT (tenant_id, staff_id, date_from, date_to, reason) ON school.unavailability TO school_runtime;
GRANT UPDATE (status, decision_reason, version) ON school.availability, school.unavailability TO school_runtime;
GRANT EXECUTE ON FUNCTION school.is_own_staff(uuid), school.availability_in_force(uuid), school.availability_slots(jsonb),
  school.availability_hours(jsonb), school.availability_on(uuid, date), school.available(uuid, date, time, time) TO school_runtime;
-- Contracts and leave (035): a contract's version keeps what it says, its end only comes earlier, one not begun is deleted;
-- a leave is cancelled, never deleted. A teacher's leave writes and cancels its unavailability (leave_id).
GRANT SELECT, DELETE ON school.staff_contract TO school_runtime;
GRANT INSERT (tenant_id, staff_id, contract_type, reference, valid_from, valid_to, weekly_hours, observation) ON school.staff_contract TO school_runtime;
GRANT UPDATE (valid_to, end_reason, version) ON school.staff_contract TO school_runtime;
GRANT SELECT ON school.staff_leave TO school_runtime;
GRANT INSERT (tenant_id, staff_id, leave_type, date_from, date_to, observation) ON school.staff_leave TO school_runtime;
GRANT UPDATE (status, version) ON school.staff_leave TO school_runtime;
GRANT INSERT (leave_id) ON school.unavailability TO school_runtime;
-- The staff's import (036): a member keeps the reference of their file; one an import created is undone by its function.
GRANT INSERT (external_ref) ON school.staff TO school_runtime;
GRANT EXECUTE ON FUNCTION school.discard_staff(uuid) TO school_runtime;
-- Rooms (037): a site's rooms, their closures and the school's lists of types and equipment, archived or cancelled,
-- never deleted; a group's main room. A room keeps its site and code, a list's entry its kind and code.
GRANT SELECT ON school.room, school.room_closure, school.room_option, platform.room_option_default TO school_runtime;
GRANT INSERT (tenant_id, site_id, code, labels, capacity, room_type, equipment, status) ON school.room TO school_runtime;
GRANT UPDATE (labels, capacity, room_type, equipment, status, version, archived_at) ON school.room TO school_runtime;
GRANT INSERT (tenant_id, room_id, date_from, date_to, reason) ON school.room_closure TO school_runtime;
GRANT UPDATE (status, version) ON school.room_closure TO school_runtime;
GRANT INSERT (tenant_id, kind, code, labels, sort_order) ON school.room_option TO school_runtime;
GRANT UPDATE (labels, sort_order, version, archived_at) ON school.room_option TO school_runtime;
GRANT INSERT (main_room_id), UPDATE (main_room_id) ON school.study_group TO school_runtime;
GRANT EXECUTE ON FUNCTION school.room_available(uuid, date) TO school_runtime;
-- Admissions (038): a prospect, its children, interactions and reminders, on a site; never deleted. An interaction
-- never changes; a reminder is done or cancelled; a child is converted once, its pupil and enrolment linked for good.
-- The school's lists of sources and statuses, archived, never deleted. The run notifies the reminders due.
GRANT SELECT ON school.lead, school.lead_child, school.lead_interaction, school.lead_reminder, school.lead_reminder_notice, school.lead_option,
  platform.lead_option_default TO school_runtime;
GRANT INSERT (tenant_id, site_id, last_name, first_name, phone, email, relationship, source, status, assigned_to, custom) ON school.lead TO school_runtime;
GRANT UPDATE (site_id, last_name, first_name, phone, email, relationship, source, status, lost_reason, assigned_to, custom, version)
  ON school.lead TO school_runtime;
GRANT INSERT (tenant_id, lead_id, last_name, first_name, birth_date, level_id) ON school.lead_child TO school_runtime;
GRANT UPDATE (last_name, first_name, birth_date, level_id, student_id, enrollment_id, conversion_key, version, archived_at)
  ON school.lead_child TO school_runtime;
GRANT INSERT (tenant_id, lead_id, kind, occurred_at, body) ON school.lead_interaction TO school_runtime;
GRANT INSERT (tenant_id, lead_id, due_at, body) ON school.lead_reminder TO school_runtime;
GRANT UPDATE (status, version) ON school.lead_reminder TO school_runtime;
GRANT INSERT (tenant_id, kind, code, labels, sort_order) ON school.lead_option TO school_runtime;
GRANT UPDATE (labels, sort_order, version, archived_at) ON school.lead_option TO school_runtime;
GRANT EXECUTE ON FUNCTION school.lead_status_reserved(text), school.lead_status_closed(text), school.lead_assignees(uuid),
  school.lead_assignee_valid(bigint, uuid), school.lead_in_scope(text, uuid), platform.notify_due_reminders(integer) TO school_runtime;
-- Access to courses (039): computed when asked, from the enrolment's state, its payments and the school's setting.
GRANT EXECUTE ON FUNCTION school.enrollment_paid(uuid, text), school.access_open(text, uuid), school.access_granted(uuid) TO school_runtime;
-- The annual progression (040): one decision per enrolment, written by its function and never changed.
GRANT SELECT ON school.progression TO school_runtime;
GRANT INSERT (tenant_id, enrollment_id, decision, reason, target_group_id, due_date, target_enrollment_id) ON school.progression TO school_runtime;
GRANT EXECUTE ON FUNCTION school.progression_refusal(uuid, text, uuid), school.decide_progression(uuid, text, text, uuid, date) TO school_runtime;

-- Sign-in and the person's own password and MFA (018): the credentials, and nothing else. No
-- schema of the platform's, no function, no other column of a person.
REVOKE ALL ON public.users, public.mfa_recovery_code FROM platform_auth;
GRANT SELECT (id, password, mfa_secret, mfa_enabled_at, mfa_last_step) ON public.users TO platform_auth;
GRANT UPDATE (password, must_change_password, password_changed_at, mfa_secret, mfa_enabled_at, mfa_last_step, updated_at)
  ON public.users TO platform_auth;
GRANT SELECT, INSERT, DELETE ON public.mfa_recovery_code TO platform_auth;
GRANT UPDATE (used_at) ON public.mfa_recovery_code TO platform_auth;

-- Publisher console: platform data and its functions only. No privilege at all on the
-- `school` schema, so no school data without the school's consent (FR-PLT-04 AC1).
GRANT USAGE ON SCHEMA platform TO platform_admin;
GRANT SELECT ON ALL TABLES IN SCHEMA platform TO platform_admin;
GRANT EXECUTE ON FUNCTION platform.create_tenant(uuid, text, text, text, text, date, date, integer, integer, text, text, bigint, text),
  platform.set_tenant_status(uuid, text, text), platform.enable_module(uuid, text), platform.disable_module(uuid, text),
  platform.set_entitlement(uuid, date, date, integer, integer, integer, integer), platform.tenant_mode(uuid),
  platform.module_enabled(uuid, text), platform.invite_admin(uuid, text), platform.apply_profile(uuid, text, integer),
  platform.ensure_user(text, text), platform.supervision(), platform.support_grants(uuid), platform.locale_for(bigint, uuid) TO platform_admin;

-- The provisioner owns the SECURITY DEFINER functions and holds exactly the privileges
-- they need. It bypasses RLS but cannot log in.
GRANT USAGE ON SCHEMA platform, school TO platform_provisioner;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA platform TO platform_provisioner;
GRANT SELECT, INSERT ON school.site, school.membership, school.audit_log TO platform_provisioner;
GRANT SELECT, INSERT ON school.role, school.role_permission, school.role_assignment TO platform_provisioner;
GRANT SELECT, INSERT ON school.node_type, school.ref_node, school.subject TO platform_provisioner;
GRANT SELECT, INSERT ON school.notification TO platform_provisioner;
GRANT SELECT, INSERT, UPDATE, DELETE ON school.outbox TO platform_provisioner;
GRANT SELECT ON school.notification_preference TO platform_provisioner;
GRANT SELECT, UPDATE ON school.file_object TO platform_provisioner;
GRANT DELETE ON school.membership, public.users, public.account_token TO platform_provisioner;
GRANT SELECT ON school.job, school.support_grant, school.setting TO platform_provisioner;
GRANT EXECUTE ON FUNCTION school.can_everywhere(text) TO platform_provisioner;
GRANT EXECUTE ON FUNCTION school.label_key(text), school.valid_labels(jsonb) TO platform_provisioner;
GRANT SELECT, INSERT ON public.users TO platform_provisioner;
GRANT UPDATE (name, email, password, must_change_password, password_changed_at, remember_token, updated_at) ON public.users TO platform_provisioner;
GRANT USAGE ON SEQUENCE public.users_id_seq TO platform_provisioner;
GRANT SELECT, INSERT, UPDATE ON public.account_token TO platform_provisioner;
GRANT UPDATE (status, username, external_ref, activated_at, updated_at) ON school.membership TO platform_provisioner;
GRANT EXECUTE ON FUNCTION school.tenant(), school.actor(), school.allowed_tenant(), school.allowed(uuid), school.is_system() TO platform_provisioner;
-- The key that signs the request's context (019, F7): read by school.context() alone, which the
-- provisioner owns; no other role reaches the `secret` schema.
GRANT USAGE ON SCHEMA secret TO platform_provisioner;
GRANT SELECT ON secret.context_key TO platform_provisioner;
ALTER FUNCTION school.context() OWNER TO platform_provisioner;
ALTER FUNCTION school.allowed_tenant() OWNER TO platform_provisioner;
-- Read with its owner's rights: the membership's policies do not apply to it (020).
ALTER VIEW school.people OWNER TO platform_provisioner;
-- The rules of pupils, groups and enrolments that read other rows (021): capacity, quota, history.
GRANT SELECT ON school.student, school.guardian, school.student_guardian, school.program, school.enrollment, school.group_membership
  TO platform_provisioner;
-- PostgreSQL row locks also require an UPDATE privilege. These columns are never written by
-- the rule functions; the grant permits locking eligibility, price and enrolment state.
GRANT UPDATE (archived_at) ON school.student, school.program, school.enrollment TO platform_provisioner;
GRANT SELECT, INSERT, UPDATE (last) ON school.matricule_counter TO platform_provisioner;
GRANT SELECT, UPDATE (archived_at) ON school.study_group TO platform_provisioner;
GRANT SELECT, INSERT ON school.program_price, school.enrollment_status_history TO platform_provisioner;
GRANT UPDATE (valid_to, updated_at, updated_by, version) ON school.group_membership TO platform_provisioner;
GRANT SELECT ON school.academic_year TO platform_provisioner;
GRANT EXECUTE ON FUNCTION school.require(text), school.require_core(text, text, uuid) TO platform_provisioner;
ALTER FUNCTION school.version_program_price() OWNER TO platform_provisioner;
ALTER FUNCTION school.check_group_places() OWNER TO platform_provisioner;
ALTER FUNCTION school.check_enrollment() OWNER TO platform_provisioner;
ALTER FUNCTION school.follow_enrollment() OWNER TO platform_provisioner;
ALTER FUNCTION school.check_group_membership() OWNER TO platform_provisioner;
ALTER FUNCTION school.core_ids(text, text) OWNER TO platform_provisioner;
ALTER FUNCTION school.next_matricule(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.group_scope_valid(uuid, uuid[]) OWNER TO platform_provisioner;
ALTER FUNCTION school.student_enrollment_count(uuid) OWNER TO platform_provisioner;
-- Undoing an import (025): the rows an import still in place created, deleted by these functions
-- alone; the rows locked first, the import's own report read to know what it created.
GRANT SELECT ON school.import_batch, school.import_row TO platform_provisioner;
GRANT UPDATE (archived_at) ON school.guardian, school.student_guardian TO platform_provisioner;
GRANT DELETE ON school.student, school.guardian, school.student_guardian, school.enrollment, school.enrollment_status_history,
  school.group_membership TO platform_provisioner;
GRANT EXECUTE ON FUNCTION school.import_created(text, uuid) TO platform_provisioner;
ALTER FUNCTION school.discard_student(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.discard_guardian(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.discard_guardian_link(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.discard_enrollment(uuid) OWNER TO platform_provisioner;
-- A pupil's photo (026): its files checked whatever the writer may read, the previous ones retired.
GRANT EXECUTE ON FUNCTION school.setting_value(text) TO platform_provisioner;
ALTER FUNCTION school.check_student_photo() OWNER TO platform_provisioner;
ALTER FUNCTION school.retire_student_photo() OWNER TO platform_provisioner;
-- An enrolled minor's primary contact, a transfer's link (027): rows the writer may not see, the pupil's row locked.
ALTER FUNCTION school.keep_primary_contact(uuid, uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.check_primary_contact() OWNER TO platform_provisioner;
ALTER FUNCTION school.link_transfer() OWNER TO platform_provisioner;
-- A programme's subjects (028): its year and the lines already there, whatever the writer may read; its volumes.
GRANT SELECT ON school.program_subject TO platform_provisioner;
GRANT SELECT, INSERT ON school.program_subject_volume TO platform_provisioner;
ALTER FUNCTION school.check_program_subject() OWNER TO platform_provisioner;
ALTER FUNCTION school.version_program_subject() OWNER TO platform_provisioner;
-- A pupil's site (029): the sites a grant names, and the pupils, guardians and groups on them, whatever the reader may see.
ALTER FUNCTION school.site_scope_ids(text, text) OWNER TO platform_provisioner;
-- Retention (030): the run anonymises pupils and guardians and deletes the journal's old entries, alone (its flag).
GRANT EXECUTE ON FUNCTION school.retention_run(), school.check_custom_values(text, jsonb), school.search_key(text),
  school.person_name_valid(text, text, text, text) TO platform_provisioner;
GRANT UPDATE (last_name_ar, first_name_ar, last_name_lat, first_name_lat, birth_date, birth_place, phone, email, address, origin_school, custom,
  external_ref, user_id, photo_file_id, photo_thumb_file_id, status_reason, anonymised_at) ON school.student TO platform_provisioner;
GRANT UPDATE (last_name_ar, first_name_ar, last_name_lat, first_name_lat, phone, email, profession, custom, external_ref, user_id, anonymised_at)
  ON school.guardian TO platform_provisioner;
GRANT DELETE ON school.audit_log TO platform_provisioner;
ALTER FUNCTION platform.retention_value(uuid, text) OWNER TO platform_provisioner;
ALTER FUNCTION platform.retention_candidates(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION platform.apply_retention(integer) OWNER TO platform_provisioner;
ALTER FUNCTION school.retention_due() OWNER TO platform_provisioner;
-- Staff (031): the records a scope reaches, the next number and an account's one record, whatever the writer may read.
GRANT SELECT ON school.staff TO platform_provisioner;
ALTER FUNCTION school.staff_ids(text) OWNER TO platform_provisioner;
ALTER FUNCTION school.next_staff_number(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.check_staff_account() OWNER TO platform_provisioner;
-- A teacher's qualifications (032): the teacher's state, the catalogue's and the lines already there, whatever the writer may read.
GRANT SELECT ON school.qualification TO platform_provisioner;
ALTER FUNCTION school.check_qualification() OWNER TO platform_provisioner;
-- A group's lead teacher (033): the teacher's state, whatever the writer may read.
ALTER FUNCTION school.check_lead_teacher() OWNER TO platform_provisioner;
-- A teacher's availability (034): the teacher's state, whether a record is the caller's own, the declarations and days off already there.
GRANT SELECT ON school.availability, school.unavailability TO platform_provisioner;
ALTER FUNCTION school.is_own_staff(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.check_availability() OWNER TO platform_provisioner;
-- Contracts and leave (035): the member's state and the terms already there, whatever the writer may read.
GRANT SELECT ON school.staff_contract, school.staff_leave TO platform_provisioner;
ALTER FUNCTION school.check_staff_terms() OWNER TO platform_provisioner;
-- The staff's import (036): a member an import still in place created, deleted by this function alone, the row locked
-- first (a lock needs a column to update: the one the pupils' also grants, which nothing of the provisioner's writes here).
GRANT UPDATE (archived_at), DELETE ON school.staff TO platform_provisioner;
ALTER FUNCTION school.discard_staff(uuid) OWNER TO platform_provisioner;
-- Rooms (037): a school's lists of room types and equipment, given with the sites and rooms module (enable_module).
GRANT SELECT, INSERT ON school.room_option TO platform_provisioner;
-- Admissions (038): the schools' lists of sources and statuses (enable_module); who reads a site's prospects, whatever the
-- writer may read; a closed prospect's reminders cancelled, whoever closed it; the reminders due notified once by the run.
GRANT SELECT, INSERT ON school.lead_option TO platform_provisioner;
GRANT SELECT ON school.lead TO platform_provisioner;
GRANT SELECT, UPDATE (status) ON school.lead_reminder TO platform_provisioner;
GRANT SELECT, INSERT ON school.lead_reminder_notice TO platform_provisioner;
GRANT EXECUTE ON FUNCTION school.lead_status_reserved(text), school.lead_status_closed(text), school.lead_in_scope(text, uuid) TO platform_provisioner;
ALTER FUNCTION platform.lead_reader(uuid, bigint, uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.lead_assignees(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.lead_assignee_valid(bigint, uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.close_lead_reminders() OWNER TO platform_provisioner;
ALTER FUNCTION platform.notify_due_reminders(integer) OWNER TO platform_provisioner;
-- A group's composition (041): its lead teacher's account and roles, whatever the writer may read, notified.
ALTER FUNCTION school.notify_composition() OWNER TO platform_provisioner;
-- The prospects' retention (042): a lost prospect anonymised with its children, interactions and reminders, by the run alone (its flag).
GRANT SELECT, UPDATE (first_name, last_name, birth_date) ON school.lead_child TO platform_provisioner;
GRANT UPDATE (last_name, first_name, phone, email, custom, lost_reason, anonymised_at) ON school.lead TO platform_provisioner;
GRANT SELECT, UPDATE (body) ON school.lead_interaction TO platform_provisioner;
GRANT UPDATE (body) ON school.lead_reminder TO platform_provisioner;
ALTER FUNCTION platform.lead_retention_candidates(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION platform.apply_lead_retention(integer) OWNER TO platform_provisioner;
-- The prospects' import (043): a prospect an import created, deleted with its children by this function alone; its reference
-- cleared by the retention run.
GRANT UPDATE (external_ref), DELETE ON school.lead TO platform_provisioner;
GRANT DELETE ON school.lead_child TO platform_provisioner;
ALTER FUNCTION school.discard_lead(uuid) OWNER TO platform_provisioner;
-- Programmes and groups from files (045): one an import created, deleted by these functions alone (a programme with its first price).
GRANT DELETE ON school.program, school.program_price, school.study_group TO platform_provisioner;
GRANT SELECT ON school.progression TO platform_provisioner;
ALTER FUNCTION school.discard_program(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.discard_group(uuid) OWNER TO platform_provisioner;
-- The staff's retention (046): a member archived long enough anonymised with their contracts, leaves and days off, by the run alone (its flag).
GRANT UPDATE (last_name_lat, first_name_lat, last_name_ar, first_name_ar, phone, email, custom, external_ref, user_id, anonymised_at)
  ON school.staff TO platform_provisioner;
GRANT UPDATE (reference, observation, end_reason) ON school.staff_contract TO platform_provisioner;
GRANT UPDATE (observation) ON school.staff_leave TO platform_provisioner;
GRANT UPDATE (reason, decision_reason) ON school.unavailability TO platform_provisioner;
GRANT UPDATE (decision_reason) ON school.availability TO platform_provisioner;
ALTER FUNCTION platform.staff_retention_candidates(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION platform.apply_staff_retention(integer) OWNER TO platform_provisioner;
-- A pupil's history (047): the journal's entries of the pupil's record (read already), whoever may read the journal.
ALTER FUNCTION school.student_history(uuid) OWNER TO platform_provisioner;
-- What an enrolment owes (048): its adjustments and its pupil's site, whatever the reader may see; the amount for the finance only.
GRANT SELECT ON school.adjustment TO platform_provisioner;
ALTER FUNCTION school.finance_in_scope(text, uuid) OWNER TO platform_provisioner;
ALTER FUNCTION platform.enrollment_due_of(uuid, uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.enrollment_due(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.enrollment_paid(uuid, text) OWNER TO platform_provisioner;
-- Payments (049): what is paid and waits, whatever the reader may see; the schedule's first instalment for the access; the references' counter.
GRANT SELECT ON school.payment, school.payment_schedule, school.installment TO platform_provisioner;
GRANT SELECT, INSERT, UPDATE (last) ON school.payment_counter TO platform_provisioner;
ALTER FUNCTION platform.enrollment_paid_of(uuid, uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.payment_balance(uuid, uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.next_payment_reference(uuid) OWNER TO platform_provisioner;
-- Refunds (050): what was refunded comes out of what is paid; the refunds' counter.
GRANT SELECT ON school.refund TO platform_provisioner;
GRANT SELECT, INSERT, UPDATE (last) ON school.refund_counter TO platform_provisioner;
ALTER FUNCTION school.next_refund_reference(uuid) OWNER TO platform_provisioner;
-- Receipts (051): numbered from their counter, their content read from the payment, the refund, the pupil and the school.
GRANT SELECT, INSERT ON school.receipt TO platform_provisioner;
GRANT SELECT, INSERT, UPDATE (last) ON school.receipt_counter TO platform_provisioner;
ALTER FUNCTION platform.balance_at(uuid, uuid, timestamptz) OWNER TO platform_provisioner;
ALTER FUNCTION school.issue_receipt(uuid, uuid) OWNER TO platform_provisioner;
-- Arrears (052): the schedules, payments and members of every school for the daily reminder, once a day.
GRANT SELECT, INSERT ON school.finance_reminder_run TO platform_provisioner;
GRANT EXECUTE ON FUNCTION platform.reminder_days(text) TO platform_provisioner;
ALTER FUNCTION platform.arrears_of(uuid, uuid[], date) OWNER TO platform_provisioner;
ALTER FUNCTION school.arrears(date, uuid) OWNER TO platform_provisioner;
ALTER FUNCTION platform.notify_installments(date) OWNER TO platform_provisioner;
-- Financial periods (053): a closure written with its reconciliation of the whole school's month.
GRANT SELECT, INSERT ON school.finance_period TO platform_provisioner;
ALTER FUNCTION school.period_closed(uuid, date) OWNER TO platform_provisioner;
ALTER FUNCTION platform.reconciliation(uuid, date, date) OWNER TO platform_provisioner;
ALTER FUNCTION school.reconciliation(date) OWNER TO platform_provisioner;
ALTER FUNCTION school.close_period(date) OWNER TO platform_provisioner;
-- A payment's document (054): checked and retired with the payment, whoever may read the file.
ALTER FUNCTION school.check_payment_attachment() OWNER TO platform_provisioner;
ALTER FUNCTION school.retire_payment_attachment() OWNER TO platform_provisioner;
-- Expenses (055): the publisher's categories given with the module; an expense's documents checked against it.
GRANT SELECT, INSERT ON school.expense_category TO platform_provisioner;
GRANT SELECT ON school.expense TO platform_provisioner;
ALTER FUNCTION school.guard_expense_file() OWNER TO platform_provisioner;
-- An expense's payments (056): read by a month's reconciliation and closure.
GRANT SELECT ON school.expense_payment TO platform_provisioner;
-- An expense's corrections (057): read by a month's reconciliation and closure.
GRANT SELECT ON school.expense_correction TO platform_provisioner;
-- Remuneration rules (058): a version reads its member's type and whether they left.
ALTER FUNCTION school.pay_staff(uuid, uuid) OWNER TO platform_provisioner;
-- Pays (059): a pay reads when its member joined and left.
ALTER FUNCTION school.payroll_staff(uuid, uuid) OWNER TO platform_provisioner;
-- Payouts (060): a payout writes its `salaries` expense, the expense's payment, and on its reversal their reversal and cancellation.
GRANT SELECT ON school.payroll_run TO platform_provisioner;
GRANT SELECT, UPDATE (status, version) ON school.payroll_payout TO platform_provisioner;
GRANT INSERT (tenant_id, expense_date, amount, category_id, beneficiary, reason, payroll_payout_id, idempotency_key) ON school.expense TO platform_provisioner;
GRANT INSERT (tenant_id, expense_id, kind, reverses_payment_id, amount, paid_on, method_code, external_ref, reason, idempotency_key),
  UPDATE (status, version) ON school.expense_payment TO platform_provisioner;
GRANT INSERT (tenant_id, expense_id, kind, amount, corrected_on, reason, idempotency_key) ON school.expense_correction TO platform_provisioner;
GRANT EXECUTE ON FUNCTION school.lock_payroll_run(uuid, date), school.expense_paid(uuid, uuid), school.expense_committed(uuid, uuid) TO platform_provisioner;
ALTER FUNCTION school.book_payroll_payout() OWNER TO platform_provisioner;
-- Sessions (062): a session's conflicts read every group's sessions, a pupil's other groups, the teacher's qualifications,
-- availability and other sessions, the rooms and the calendar, of the school in context only.
GRANT SELECT ON school.class_session, school.room, school.room_closure, school.calendar_exception TO platform_provisioner;
GRANT EXECUTE ON FUNCTION school.local_day(timestamptz), school.local_at(date, time), school.day_open(date, uuid), school.open_day(date),
  school.qualified(uuid, uuid, uuid, date), school.available(uuid, date, time, time), school.availability_on(uuid, date),
  school.room_available(uuid, date) TO platform_provisioner;
ALTER FUNCTION school.session_conflicts(uuid, uuid, uuid, uuid, timestamptz, timestamptz, uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.check_class_session() OWNER TO platform_provisioner;
ALTER FUNCTION school.sessions_planned_on(uuid, date, date) OWNER TO platform_provisioner;
-- Replacements (064): each change written to the history and told to the teachers and pupils concerned.
GRANT SELECT, INSERT ON school.session_change TO platform_provisioner;
ALTER FUNCTION school.record_session_change() OWNER TO platform_provisioner;
ALTER FUNCTION platform.finance_pending(uuid, date, date) OWNER TO platform_provisioner;
ALTER FUNCTION platform.create_tenant(uuid, text, text, text, text, date, date, integer, integer, text, text, bigint, text) OWNER TO platform_provisioner;
ALTER FUNCTION platform.set_tenant_status(uuid, text, text) OWNER TO platform_provisioner;
ALTER FUNCTION platform.enable_module(uuid, text) OWNER TO platform_provisioner;
ALTER FUNCTION platform.disable_module(uuid, text) OWNER TO platform_provisioner;
ALTER FUNCTION platform.set_entitlement(uuid, date, date, integer, integer, integer, integer) OWNER TO platform_provisioner;
ALTER FUNCTION platform.tenant_mode(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION platform.module_enabled(uuid, text) OWNER TO platform_provisioner;
ALTER FUNCTION school.module_enabled(text) OWNER TO platform_provisioner;
ALTER FUNCTION school.audit() OWNER TO platform_provisioner;
ALTER FUNCTION platform.audit_tenant() OWNER TO platform_provisioner;
ALTER FUNCTION school.my_schools() OWNER TO platform_provisioner;
ALTER FUNCTION school.my_modules() OWNER TO platform_provisioner;
ALTER FUNCTION school.effective_permissions(bigint, uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.my_permissions() OWNER TO platform_provisioner;
ALTER FUNCTION school.can(text) OWNER TO platform_provisioner;
ALTER FUNCTION school.can_everywhere(text) OWNER TO platform_provisioner;
ALTER FUNCTION school.manager_count(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.role_manages(uuid, uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.manager_count_before(uuid, text, jsonb) OWNER TO platform_provisioner;
ALTER FUNCTION school.guard_last_manager() OWNER TO platform_provisioner;
ALTER FUNCTION school.check_segregation(text, bigint, boolean) OWNER TO platform_provisioner;
ALTER FUNCTION school.require_everywhere(text) OWNER TO platform_provisioner;
ALTER FUNCTION school.audit_account(uuid, bigint, text) OWNER TO platform_provisioner;
ALTER FUNCTION school.holds_sensitive_role(uuid, bigint) OWNER TO platform_provisioner;
ALTER FUNCTION school.shared_account(uuid, bigint) OWNER TO platform_provisioner;
ALTER FUNCTION school.create_account(text, text, text, text, text) OWNER TO platform_provisioner;
ALTER FUNCTION school.update_account(bigint, text, text, text, text) OWNER TO platform_provisioner;
ALTER FUNCTION school.reset_password(bigint, text) OWNER TO platform_provisioner;
ALTER FUNCTION school.set_account_status(bigint, text, text) OWNER TO platform_provisioner;
ALTER FUNCTION school.issue_activation(bigint, text) OWNER TO platform_provisioner;
ALTER FUNCTION platform.invite_admin(uuid, text) OWNER TO platform_provisioner;
ALTER FUNCTION platform.issue_recovery(text, text) OWNER TO platform_provisioner;
ALTER FUNCTION platform.redeem_token(text, text, text) OWNER TO platform_provisioner;
ALTER FUNCTION platform.find_login(text, text) OWNER TO platform_provisioner;
ALTER FUNCTION platform.peek_token(text, text) OWNER TO platform_provisioner;
ALTER FUNCTION platform.apply_profile(uuid, text, integer) OWNER TO platform_provisioner;
ALTER FUNCTION school.audit_readable_types() OWNER TO platform_provisioner;
ALTER FUNCTION school.notify(text, bigint[], jsonb) OWNER TO platform_provisioner;
ALTER FUNCTION school.journal_watchers(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.notify_sensitive() OWNER TO platform_provisioner;
ALTER FUNCTION platform.claim_outbox(integer, integer) OWNER TO platform_provisioner;
ALTER FUNCTION platform.finish_outbox(uuid, text) OWNER TO platform_provisioner;
ALTER FUNCTION school.retry_delivery(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.file_categories(text) OWNER TO platform_provisioner;
ALTER FUNCTION school.storage_use(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.file_for_link(uuid, uuid) OWNER TO platform_provisioner;
ALTER FUNCTION platform.expired_files(integer) OWNER TO platform_provisioner;
ALTER FUNCTION platform.mark_purged(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.discard_account(bigint) OWNER TO platform_provisioner;
ALTER FUNCTION platform.ensure_user(text, text) OWNER TO platform_provisioner;
ALTER FUNCTION platform.supervision() OWNER TO platform_provisioner;
ALTER FUNCTION school.support_grant_in_use() OWNER TO platform_provisioner;
ALTER FUNCTION school.log_support_access(text) OWNER TO platform_provisioner;
ALTER FUNCTION platform.support_grants(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION platform.locale_for(bigint, uuid) OWNER TO platform_provisioner;
