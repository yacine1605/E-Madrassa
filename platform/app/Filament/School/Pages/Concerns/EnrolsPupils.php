<?php

namespace App\Filament\School\Pages\Concerns;

use App\Filament\School\Pages\GroupMembers;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\GroupMembershipController;
use App\Http\Controllers\ProgramController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudyGroupController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;

/**
 * FR-INS-01: enrolling a pupil in an active programme, with their class at once if they wish.
 * The request's key is made when the form opens: the same form sent twice (a double click, a
 * retry) makes one enrolment (TST-08).
 */
trait EnrolsPupils
{
    /** @param ?string $student the pupil, when the form is the pupil's own (their sheet) */
    protected function enrolAction(?string $student = null): Action
    {
        return Action::make('enrol')->label(__('schooling.enrol'))->visible(fn () => $this->creatable('enrollment.enrollment.create', $this->enrolmentSite()) && $this->enrollable())
            ->schema(fn () => [
                ...($student === null ? [Select::make('student_id')->label(__('schooling.student'))->required()->searchable()
                    ->getSearchResultsUsing(fn (string $search) => $this->studentOptions($search))->getOptionLabelUsing(fn ($value) => $this->studentLabel($value))] : []),
                Select::make('program_id')->label(__('schooling.program'))->required()->searchable()->live()->helperText(__('schooling.active_programs_only'))
                    ->getSearchResultsUsing(fn (string $search) => $this->activePrograms($search))->getOptionLabelUsing(fn ($value) => $this->programName($value))
                    ->afterStateUpdated(fn (Set $set) => $set('group_id', null)),
                Select::make('group_id')->label(__('schooling.main_group'))->helperText(__('schooling.enrol_group_help'))
                    ->visible(fn (Get $get) => filled($get('program_id')) && $this->creatable('group.membership.manage'))
                    ->options(fn (Get $get) => $this->mainGroups($get('program_id'))),
                Hidden::make('idempotency_key')->default(fn () => (string) Str::uuid()),
            ])
            ->action(fn (array $data) => SchoolScreen::changeAt('enrollment.enrollment.create', $this->enrolmentSite(), function () use ($data, $student) {
                $enrollment = SchoolScreen::call('enrollment.enrollment.create', EnrollmentController::class, 'store',
                    ['student_id' => $student ?? $data['student_id'], 'program_id' => $data['program_id'], 'idempotency_key' => $data['idempotency_key']]);
                // The same form sent again finds its enrolment already in this group.
                if (!empty($data['group_id']) && ($enrollment['data']['main_group']['id'] ?? null) !== $data['group_id']) {
                    SchoolScreen::call('group.membership.manage', GroupMembershipController::class, 'store',
                        ['enrollment_id' => $enrollment['data']['id']], ['group' => $data['group_id']]);
                }
                return $enrollment;
            }, fn () => Notification::make()->title(__('schooling.enrolled'))->success()));
    }

    /** The site of the page's pupil, if any: a grant on that site enrols them (SQL 029); any site of the grant otherwise. */
    protected function enrolmentSite(): ?string { return null; }

    /** Whether the page's pupil, if any, may be enrolled: neither former nor blocked nor archived. */
    protected function enrollable(): bool { return true; }

    /** @return array<string, string> active pupils matching the search, at most 50 */
    protected function studentOptions(string $search): array
    {
        return collect(SchoolScreen::call('student.record.read', StudentController::class, 'index', ['q' => $search, 'status' => 'active', 'per_page' => 50])['data'])
            ->mapWithKeys(fn ($pupil) => [$pupil['id'] => $pupil['matricule'].' · '.$this->personName($pupil)])->all();
    }

    protected function studentLabel(?string $student): ?string
    {
        if (!$student) return null;
        $pupil = SchoolScreen::call('student.record.read', StudentController::class, 'show', parameters: ['student' => $student])['data'];
        return $pupil['matricule'].' · '.$this->personName($pupil);
    }

    /** @return array<string, string> FR-PRG-01 AC2: only active programmes are offered */
    protected function activePrograms(string $search): array
    {
        return collect(SchoolScreen::call('program.program.read', ProgramController::class, 'index', ['q' => $search, 'status' => 'active', 'per_page' => 50])['data'])
            ->mapWithKeys(fn ($program) => [$program['id'] => $program['code'].' · '.Locales::label($program['labels']).' · '.$this->money($program['base_price'])])->all();
    }

    protected function programName(?string $program): ?string
    {
        if (!$program) return null;
        $row = SchoolScreen::call('program.program.read', ProgramController::class, 'show', parameters: ['program' => $program])['data'];
        return $row['code'].' · '.Locales::label($row['labels']);
    }

    /** @return array<string, string> the programme's open classes and sessions, with their places */
    protected function mainGroups(?string $program, ?string $except = null): array
    {
        if (!$program) return [];
        return collect(SchoolScreen::call('group.group.read', StudyGroupController::class, 'index', ['program_id' => $program, 'status' => 'active', 'per_page' => 100])['data'])
            ->filter(fn ($group) => in_array($group['group_type'], ['class', 'session'], true) && $group['id'] !== $except)
            ->mapWithKeys(fn ($group) => [$group['id'] => $this->groupName($group)])->all();
    }

    protected function groupName(array $group): string
    {
        return $group['code'].' · '.Locales::label($group['labels']).' · '.__('schooling.places_taken',
            ['members' => Formats::number($group['members']), 'capacity' => Formats::number($group['capacity'])]);
    }
}
