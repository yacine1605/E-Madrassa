<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

/**
 * Turns a rule enforced by the database into a clear API answer (NFR-UX-04). Never returns
 * SQL, bindings or personal data (NFR-SEC-06).
 */
final class DatabaseErrors
{
    /** Rules raised by the schema's triggers; each one's message is in the catalog `errors`, in the reader's language. */
    private const RULES = [
        'stale_version', 'last_site', 'code_immutable', 'identity_immutable', 'immutable_record', 'last_admin', 'self_assignment',
        'self_elevation', 'role_in_use', 'role_archived', 'scope_personal_required', 'scope_unknown', 'permission_module_disabled',
        'segregation_of_duties', 'token_invalid', 'password_already_set', 'password_required', 'account_shared', 'account_unknown',
        'email_required', 'self_account', 'reason_required', 'activation_required', 'not_invited', 'login_required', 'name_required',
        'membership_revoked', 'status_invalid', 'setting_invalid', 'type_required', 'type_in_use', 'type_inactive', 'parent_invalid',
        'node_has_children', 'subject_in_use', 'year_closed', 'period_outside_year', 'options_invalid', 'option_removed',
        'custom_field_limit', 'custom_value_invalid', 'custom_value_required', 'custom_field_unknown', 'profile_unknown',
        'profile_mismatch', 'profile_published', 'job_finished', 'not_failed', 'tenant_required', 'file_type_refused', 'file_too_large',
        'dependent_operation', 'storage_quota_exceeded',
        'student_former', 'birth_date_future', 'status_transition_refused', 'matricule_exhausted',
        'node_archived', 'group_closed', 'program_other_year', 'capacity_below_members', 'enrollment_cancelled',
        'main_group_required', 'program_not_active', 'student_blocked', 'primary_contact_required', 'student_quota_exceeded',
        'membership_ended', 'enrollment_not_assignable', 'group_not_active', 'group_other_year', 'group_other_program',
        'outside_group_dates', 'group_full', 'idempotency_key_reused', 'student_photo_invalid', 'student_photo_disabled',
        'primary_contact_kept', 'transfer_same_program', 'transfer_invalid', 'subject_archived', 'program_subject_volume_required',
        'program_subject_overlap', 'program_subject_ended', 'program_subject_outside_year', 'program_subject_end_before_start',
        'site_required', 'site_archived',
        'staff_archived', 'staff_archive_left', 'left_on_future', 'left_before_hire', 'staff_account_linked',
        'qualification_not_teacher', 'staff_left', 'qualification_overlap', 'qualification_ended', 'qualification_end_before_start',
        'lead_teacher_not_teacher',
        'availability_not_teacher', 'availability_past', 'availability_slots_invalid', 'availability_slots_overlap',
        'availability_below_minimum', 'availability_same_day', 'availability_decided', 'unavailability_overlap', 'unavailability_ended',
        'self_approval', 'approval_required',
        'contract_overlap', 'contract_end_required', 'contract_end_before_start', 'contract_end_later', 'contract_started',
        'leave_overlap', 'leave_end_before_start', 'leave_ended', 'leave_kept', 'unavailability_from_leave',
        'room_option_unknown', 'room_option_archived', 'room_archived', 'room_in_use', 'room_too_small', 'room_other_site', 'room_unavailable',
        'room_closure_past', 'room_closure_end_before_start', 'room_closure_overlap', 'room_closure_ended', 'site_has_rooms',
        'lead_option_unknown', 'lead_option_archived', 'lead_status_reserved', 'lead_closed', 'lead_not_converted', 'reopen_reason_required',
        'lost_reason_required', 'lead_assignee_invalid', 'lead_already_converted', 'lead_conversion_mismatch', 'lead_child_archived',
        'interaction_future', 'interaction_body_required', 'reminder_past',
        'progression_decided', 'progression_not_validated', 'progression_target_required', 'progression_target_invalid', 'progression_same_year',
        'progression_level_changed', 'progression_level_same', 'progression_target_enrolled', 'lead_anonymised',
        'program_copy_year', 'program_code_taken',
        'adjustment_exceeds_due', 'adjustment_decided', 'schedule_invalid', 'schedule_sum_mismatch',
        'payment_exceeds_balance', 'payment_future_date', 'payment_method_unknown', 'payment_immutable', 'payment_not_author',
        'payment_not_reversible', 'payment_reference_exhausted', 'payment_carried', 'refund_exceeds_paid', 'refund_not_approved', 'refund_decided',
        'refund_target_invalid', 'refund_execution_incomplete', 'credit_exceeded', 'receipt_not_validated',
        'period_closed', 'period_not_ended', 'period_has_pending', 'payment_attachment_invalid',
        'expense_category_system', 'expense_category_archived', 'expense_label_required', 'expense_future_date', 'expense_immutable', 'expense_not_author',
        'expense_not_payable', 'expense_payment_immutable', 'expense_not_correctable', 'expense_correction_pending', 'correction_exceeds_amount',
        'correction_below_paid', 'expense_correction_immutable', 'pay_rule_kind_refused', 'pay_rule_incomplete', 'pay_rule_rate_refused', 'pay_rule_default_rate_required',
        'pay_rule_rate_duplicate', 'pay_rule_node_unknown', 'pay_rule_month', 'pay_rule_retroactive', 'pay_rule_overlap',
        'pay_rule_started', 'pay_rule_immutable', 'pay_rule_end_later', 'pay_rule_end_before_start',
        'payroll_period_future', 'payroll_no_rule', 'payroll_run_validated', 'payroll_run_stale', 'payroll_rate_missing', 'payroll_net_negative',
        'payroll_units_refused', 'payroll_unit_date', 'payroll_unit_sessions', 'payroll_correction_invalid',
        'payroll_run_not_payable', 'payroll_payout_date', 'payroll_payout_immutable', 'expense_payroll', 'expense_category_missing',
        'calendar_exception_past', 'calendar_exception_end_before_start', 'calendar_exception_overlap', 'calendar_exception_ended',
        'time_slot_archived', 'time_slot_immutable',
        'session_spans_days', 'session_group_not_active', 'session_outside_group_dates', 'session_day_closed', 'session_subject_outside_program', 'session_not_teacher', 'session_teacher_left', 'session_teacher_not_qualified', 'session_teacher_unavailable',
        'session_teacher_busy', 'session_group_busy', 'session_room_other_site', 'session_room_unavailable', 'session_room_too_small', 'session_room_busy', 'session_pupil_busy', 'session_not_deletable',
        'session_series_refused', 'session_immutable', 'session_locked', 'session_series_empty', 'session_hours_invalid', 'session_series_past', 'session_series_end_later', 'calendar_sessions_planned',
        'session_replaced', 'session_ended', 'session_replacement_same',
        'timetable_version_refused', 'timetable_version_closed', 'timetable_version_open', 'timetable_period_invalid', 'timetable_version_stale',
        'timetable_conflicts', 'timetable_version_not_validated',
        'attendance_session_cancelled', 'attendance_future', 'attendance_too_late', 'attendance_exists', 'attendance_validated', 'attendance_not_submitted',
        'attendance_not_author', 'attendance_late_minutes', 'attendance_replacement_required', 'attendance_hours_invalid',
        'attendance_not_validated', 'attendance_correction_same', 'attendance_month_paid', 'roll_not_member', 'roll_late_minutes', 'dashboard_range_invalid',
        'assignment_begun', 'assignment_past', 'assignment_ended', 'assignment_end_past', 'assignment_end_before_start', 'assignment_group_closed',
        'assignment_subject_outside_program', 'assignment_not_teacher', 'assignment_teacher_left', 'assignment_teacher_not_qualified',
        'pupil_account_staff', 'pupil_account_linked',
        'lms_link_invalid', 'lms_link_domain_blocked', 'lms_content_invalid', 'lms_subject_outside_program', 'lms_chapter_archived', 'lms_lesson_archived',
        'lms_review_required', 'lms_review_self', 'lms_review_off', 'lms_publication_empty', 'lms_publish_past', 'lms_lesson_not_visible',
        'lms_resource_withdrawn', 'lms_activity_published', 'lms_activity_archived', 'lms_activity_draft', 'lms_questions_quiz_only',
        'lms_question_choices_invalid', 'lms_question_correct_count', 'lms_points_mismatch', 'lms_deadline_past', 'lms_activity_dates_invalid',
        'lms_quiz_empty', 'lms_audience_not_member', 'lms_audience_exists', 'lms_extension_earlier', 'lms_activity_not_open', 'lms_deadline_passed',
        'lms_attempts_exhausted', 'lms_attempt_expired', 'lms_attempt_submitted', 'lms_answer_invalid', 'idempotency_key_required',
        'lms_submission_not_current',
    ];

    /** The exclusion constraints whose refusal says which conflict (SQL 062, 077). */
    private const EXCLUSIONS = ['class_session_teacher_free' => 'session_teacher_busy', 'class_session_room_free' => 'session_room_busy',
        'class_session_group_free' => 'session_group_busy', 'teaching_assignment_once' => 'assignment_overlap'];

    /** The rules refusing what was sent rather than the state it meets: a link or a document (lot 5 §7, `422`). */
    private const STATUS = ['lms_link_invalid' => 422, 'lms_link_domain_blocked' => 422, 'lms_content_invalid' => 422,
        'lms_question_choices_invalid' => 422, 'lms_question_correct_count' => 422, 'lms_answer_invalid' => 422];

    public static function message(string $rule): string
    {
        return __('errors.'.$rule);
    }

    public static function response(QueryException $e): ?JsonResponse
    {
        $known = self::classify($e);
        return $known ? response()->json(['message' => $known[2], 'code' => $known[1]], $known[0]) : null;
    }

    /** [HTTP status, code, message] for a refusal the schema enforces; null for anything else, which is a fault. */
    public static function classify(QueryException $e): ?array
    {
        $state = (string) $e->getCode();
        $detail = $e->errorInfo[2] ?? '';
        // A support session writes nothing: the database's guard, or the read-only transaction itself.
        if ($state === '25006' && (str_contains($detail, 'support_read_only') || str_contains($detail, 'read-only transaction'))) {
            return [403, 'support_read_only', __('errors.support_read_only')];
        }
        if ($state === '25006') return [403, 'tenant_read_only', __('errors.tenant_read_only')];
        if ($state === '23514') {
            foreach (self::RULES as $rule) {
                if (preg_match('/ERROR:\s+'.preg_quote($rule, '/').'\b/', $detail)) return [self::STATUS[$rule] ?? 409, $rule, __('errors.'.$rule)];
            }
            return [409, 'domain_conflict', __('errors.domain_conflict')];
        }
        if ($state === '23505' && preg_match('/ERROR:\s+account_exists\b/', $detail)) {
            return [409, 'account_exists', __('errors.account_exists')];
        }
        if ($state === '23505') return [409, 'duplicate', __('errors.duplicate')];
        // TST-15: a session's exclusion constraints, met by two requests at once.
        foreach (self::EXCLUSIONS as $constraint => $rule) {
            if ($state === '23P01' && str_contains($detail, $constraint)) return [409, $rule, __('errors.'.$rule)];
        }
        if ($state === '23P01') return [409, 'overlap', __('errors.overlap')];
        if (in_array($state, ['23503', '55P03', '40001', '40P01'], true)) {
            return [409, 'domain_conflict', __('errors.domain_conflict_retry')];
        }
        // A trigger found the permission missing; or the restricted role lacks the privilege, or
        // RLS refused the row: a refusal, not a fault.
        if ($state === '42501' && preg_match('/ERROR:\s+permission_denied\b/', $detail)) {
            return [403, 'permission_denied', __('errors.permission_denied')];
        }
        // Lot 5 §7: a group or a subject the teacher does not teach, said without telling more of it.
        foreach (['lms_group_forbidden', 'lms_access_denied'] as $rule) {
            if ($state === '42501' && preg_match('/ERROR:\s+'.$rule.'\b/', $detail)) return [403, $rule, __('errors.'.$rule)];
        }
        if ($state === '42501') return [403, 'forbidden', __('errors.forbidden')];
        return null;
    }
}
