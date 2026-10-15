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
    ];

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
                if (preg_match('/ERROR:\s+'.preg_quote($rule, '/').'\b/', $detail)) return [409, $rule, __('errors.'.$rule)];
            }
            return [409, 'domain_conflict', __('errors.domain_conflict')];
        }
        if ($state === '23505' && preg_match('/ERROR:\s+account_exists\b/', $detail)) {
            return [409, 'account_exists', __('errors.account_exists')];
        }
        if ($state === '23505') return [409, 'duplicate', __('errors.duplicate')];
        if ($state === '23P01') return [409, 'overlap', __('errors.overlap')];
        if (in_array($state, ['23503', '55P03', '40001', '40P01'], true)) {
            return [409, 'domain_conflict', __('errors.domain_conflict_retry')];
        }
        // A trigger found the permission missing; or the restricted role lacks the privilege, or
        // RLS refused the row: a refusal, not a fault.
        if ($state === '42501' && preg_match('/ERROR:\s+permission_denied\b/', $detail)) {
            return [403, 'permission_denied', __('errors.permission_denied')];
        }
        if ($state === '42501') return [403, 'forbidden', __('errors.forbidden')];
        return null;
    }
}
