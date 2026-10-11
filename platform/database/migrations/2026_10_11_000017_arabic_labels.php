<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/017_arabic_labels.sql')));
    }

    public function down(): void
    {
        // The schools' roles first, while their templates still hold the Arabic label they received.
        DB::unprepared(<<<'SQL'
ALTER TABLE school.role DISABLE TRIGGER USER;
UPDATE school.role r SET labels = r.labels - 'ar' FROM platform.role_template t
WHERE r.template_code = t.code AND r.labels ->> 'ar' = t.labels ->> 'ar';
ALTER TABLE school.role ENABLE TRIGGER USER;
SQL);
        foreach ([
            'module' => ['key', [
                'ai_assistant', 'attendance', 'core', 'dashboards', 'enrollment_groups', 'expenses', 'lms', 'parent_portal', 'payroll',
                'scheduling_manual', 'scheduling_optimization', 'sites_rooms', 'staff', 'stock', 'student_finance', 'students_crm']],
            'plan' => ['code', [
                'complete', 'custom', 'essential', 'pro']],
            'role_template' => ['code', [
                'academic_hr', 'accountant', 'direction', 'front_office', 'parent', 'stock_manager', 'student', 'supervisor', 'teacher',
                'tenant_admin']],
            'setting_definition' => ['key', [
                'finance.allow_self_validation', 'session.idle_minutes']],
            'permission' => ['code', [
                'attendance.student.entry.create', 'attendance.student.entry.update', 'attendance.teacher.entry.create',
                'attendance.teacher.entry.update', 'attendance.teacher.validation.approve', 'audit.log.read', 'crm.lead.convert',
                'crm.lead.create', 'crm.lead.read', 'crm.lead.update', 'custom_field.definition.manage', 'dashboard.finance.read',
                'dashboard.global.read', 'dashboard.operations.read', 'dashboard.pedagogy.read', 'enrollment.enrollment.cancel',
                'enrollment.enrollment.create', 'enrollment.enrollment.read', 'enrollment.enrollment.suspend',
                'enrollment.enrollment.update', 'enrollment.enrollment.validate', 'enrollment.progression.decide',
                'expense.attachment.read', 'expense.expense.create', 'expense.expense.pay', 'expense.expense.read',
                'expense.expense.reverse', 'expense.expense.update', 'expense.expense.validate', 'export.job.create',
                'finance.discount.grant', 'finance.payment.create', 'finance.payment.create.delegated', 'finance.payment.read',
                'finance.payment.reverse', 'finance.payment.validate', 'finance.period.close', 'finance.period.reopen',
                'finance.refund.approve', 'finance.refund.request', 'finance.schedule.manage', 'group.group.manage', 'group.group.read',
                'group.membership.manage', 'guardian.record.create', 'guardian.record.read', 'guardian.record.update',
                'hours.validated.read', 'import.job.create', 'import.job.read', 'payroll.amount.read', 'payroll.payout.record',
                'payroll.rule.manage', 'payroll.run.generate', 'payroll.run.validate', 'payroll.self_view', 'program.program.manage',
                'program.program.read', 'program.subject.manage', 'referential.node.manage', 'referential.node.read',
                'role.assignment.manage', 'role.definition.manage', 'room.room.manage', 'schedule.session.cancel',
                'schedule.session.create', 'schedule.session.read', 'schedule.session.update', 'schedule.timetable.publish',
                'site.site.manage', 'staff.availability.approve', 'staff.availability.manage', 'staff.contract.manage',
                'staff.contract.read', 'staff.leave.manage', 'staff.leave.read', 'staff.profile.create', 'staff.profile.read',
                'staff.profile.update', 'staff.qualification.manage', 'stock.inventory.manage', 'stock.item.manage',
                'stock.movement.create', 'student.record.archive', 'student.record.create', 'student.record.read', 'student.record.update',
                'student.sensitive.read', 'subject.subject.manage', 'tenant.settings.manage', 'user.account.create', 'user.account.read',
                'user.account.suspend', 'user.account.update']],
        ] as $table => [$key, $codes]) {
            DB::update("UPDATE platform.$table SET labels = labels - 'ar' WHERE $key = ANY (?::text[])", ['{'.implode(',', $codes).'}']);
        }
    }
};
