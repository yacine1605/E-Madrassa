<?php
namespace App\School\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TeacherEligibility
{
    public static function teachers(string $tenant, ?string $level, string $subject)
    {
        $qualifiedSubjects = [$subject];
        // Generalist rule from the school profile: a qualification in one subject
        // covers the level's other subjects, except the profile's specialist subjects.
        $generalist = config('algerian_curriculum.generalist');
        $generalistSubject = $generalist && DB::table('school.curriculum as c')
            ->join('school.levels as l', fn ($join) => $join->on('l.id', '=', 'c.level_id')->on('l.tenant_id', '=', 'c.tenant_id'))
            ->join('school.programs as p', fn ($join) => $join->on('p.id', '=', 'l.program_id')->on('p.tenant_id', '=', 'l.tenant_id'))
            ->join('school.subjects as s', fn ($join) => $join->on('s.id', '=', 'c.subject_id')->on('s.tenant_id', '=', 'c.tenant_id'))
            ->where('c.tenant_id', $tenant)->where('c.level_id', $level)->where('c.subject_id', $subject)
            ->whereRaw('upper(p.code) = ?', [strtoupper($generalist['program'])])
            ->whereNotIn('s.code', $generalist['specialist_subjects'])->exists();
        if ($generalistSubject) {
            $qualifying = DB::table('school.subjects')->where('tenant_id', $tenant)->where('code', $generalist['qualifying_subject'])->value('id');
            if ($qualifying) $qualifiedSubjects[] = $qualifying;
        }

        return DB::table('school.staff')->where('tenant_id', $tenant)
            ->where('is_teacher', true)->where('active', true)
            ->whereIn('id', DB::table('school.teacher_qualifications')->where('tenant_id', $tenant)
                ->where('level_id', $level)->whereIn('subject_id', $qualifiedSubjects)->select('teacher_id'));
    }

    public static function validate(string $tenant, array $data): void
    {
        $level = Curriculum::levelForCohort($tenant, $data['cohort_id']);
        if (!self::teachers($tenant, $level, $data['subject_id'])->where('id', $data['teacher_id'])->exists()) {
            throw ValidationException::withMessages(['teacher_id'=>'Cet enseignant ne peut pas enseigner cette matière à ce niveau. Configurez ses matières et niveaux dans Enseignants.']);
        }
    }
}
