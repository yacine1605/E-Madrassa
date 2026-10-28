<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\TeachingAssignmentController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\Permissions;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * Lot 5 §1, « Affectations d'enseignement »: who teaches each group's subject, from a day until
 * another, one teacher at a time; the next one is assigned from the day after. A teacher reads
 * their own groups' assignments; the pedagogy manager assigns, ends, or removes one not begun.
 */
final class TeachingAssignments extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 30;
    protected static ?string $section = 'pedagogy';
    protected static array $permissions = ['group.group.read'];
    protected static ?string $module = 'lms';
    protected static string|array $routeMiddleware = ['module:lms', 'permission:group.group.read'];
    private ?array $options = null;

    public static function getNavigationLabel(): string { return __('pedagogy.assignments'); }
    public function getTitle(): string { return __('pedagogy.assignments'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (array $filters, int $page, int $recordsPerPage) => $this->apiPage(static::$permissions, TeachingAssignmentController::class, 'index',
            array_filter(['group_id' => $filters['group']['value'] ?? null, 'mine' => ($filters['mine']['isActive'] ?? false) ?: null,
                'include_ended' => ($filters['ended']['isActive'] ?? false) ?: null]), $page, $recordsPerPage))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(50)->description(__('pedagogy.assignments_help'))
            ->emptyStateHeading(__('pedagogy.no_assignment'))
            ->columns([
                TextColumn::make('group_code')->label(__('planning.group'))->description(fn (array $record) => Locales::label($record['group_labels'])),
                TextColumn::make('subject_code')->label(__('planning.subject'))->description(fn (array $record) => Locales::label($record['subject_labels'])),
                TextColumn::make('teacher')->label(__('planning.teacher'))->state(fn (array $record) => $this->teacher($record))
                    ->badge(fn (array $record) => $record['mine'])->color(fn (array $record) => $record['mine'] ? 'success' : null),
                TextColumn::make('valid_from')->label(__('pedagogy.assigned_from'))->formatStateUsing(fn ($state) => Formats::date($state)),
                TextColumn::make('valid_to')->label(__('pedagogy.assigned_until'))->formatStateUsing(fn ($state) => Formats::date($state)),
            ])->filters([
                SelectFilter::make('group')->label(__('planning.group'))->options(fn () => $this->options()['groups'])->searchable(),
                Filter::make('mine')->label(__('pedagogy.my_assignments'))->toggle(),
                Filter::make('ended')->label(__('pedagogy.include_ended'))->toggle(),
            ])
            ->headerActions([
                Action::make('assign')->label(__('pedagogy.assign'))->visible(fn () => $this->assigns())
                    ->schema([
                        Select::make('group_id')->label(__('planning.group'))->required()->searchable()->options(fn () => $this->options()['groups']),
                        Select::make('subject_id')->label(__('planning.subject'))->required()->searchable()->options(fn () => $this->subjectOptions())
                            ->helperText(__('pedagogy.subject_help')),
                        Select::make('staff_id')->label(__('planning.teacher'))->required()->searchable()->options(fn () => $this->options()['teachers'])
                            ->helperText(__('pedagogy.teacher_help')),
                        DatePicker::make('valid_from')->label(__('pedagogy.assigned_from'))->minDate(now()->toDateString())->helperText(__('pedagogy.assigned_from_help')),
                    ])
                    ->action(fn (array $data) => SchoolScreen::change(null, fn () => SchoolScreen::call('lms.assignment.manage',
                        TeachingAssignmentController::class, 'store', $this->sent($data)))),
            ])->recordActions([ActionGroup::make([
                Action::make('end')->label(__('pedagogy.end_assignment'))->modalDescription(__('pedagogy.end_assignment_help'))->color('warning')
                    ->visible(fn (array $record) => $record['valid_to'] === null && $this->assigns())
                    ->schema(fn (array $record) => [DatePicker::make('valid_to')->label(__('pedagogy.assigned_until'))->required()->default(now()->toDateString())
                        ->minDate(max($record['valid_from'], now()->subDay()->toDateString()))])
                    ->action(fn (array $record, array $data) => SchoolScreen::change(null, fn () => SchoolScreen::call('lms.assignment.manage',
                        TeachingAssignmentController::class, 'end', ['valid_to' => substr((string) $data['valid_to'], 0, 10), 'version' => $record['version']],
                        ['assignment' => $record['id']]))),
                Action::make('remove')->label(__('pedagogy.remove_assignment'))->modalDescription(__('pedagogy.remove_assignment_help'))->color('danger')
                    ->requiresConfirmation()->visible(fn (array $record) => $record['valid_from'] > now()->toDateString() && $this->assigns())
                    ->action(fn (array $record) => SchoolScreen::change(null, fn () => SchoolScreen::call('lms.assignment.manage',
                        TeachingAssignmentController::class, 'destroy', ['version' => $record['version']], ['assignment' => $record['id']]))),
            ])]);
    }

    /** Granted somewhere: the group's site is the database's to check. */
    private function assigns(): bool { return app(Permissions::class)->can('lms.assignment.manage') && SchoolScreen::writable(null); }

    /** The teacher's name, or « hidden » for a reader who does not read the staff's records. */
    private function teacher(array $record): string
    {
        $name = $this->personName(['last_name_lat' => $record['teacher_last_name_lat'], 'first_name_lat' => $record['teacher_first_name_lat'],
            'last_name_ar' => $record['teacher_last_name_ar'], 'first_name_ar' => $record['teacher_first_name_ar']]);
        return $name !== '' ? $name : __($record['mine'] ? 'pedagogy.me' : 'pedagogy.teacher_hidden');
    }

    /** The active groups and the teachers still with the school, as the reader may see them. */
    private function options(): array
    {
        return $this->options ??= [
            'groups' => collect(DB::select("SELECT id, code, labels FROM school.study_group WHERE tenant_id = ? AND archived_at IS NULL AND status <> 'closed' ORDER BY code",
                [$this->schoolId]))->mapWithKeys(fn ($row) => [$row->id => $row->code.' · '.Locales::label(json_decode($row->labels, true))])->all(),
            'teachers' => collect(DB::select("SELECT id, staff_number, last_name_lat, first_name_lat, last_name_ar, first_name_ar FROM school.staff WHERE tenant_id = ?
                AND archived_at IS NULL AND status <> 'left' AND staff_type IN ('teacher_internal', 'teacher_external') ORDER BY last_name_lat, first_name_lat", [$this->schoolId]))
                ->mapWithKeys(fn ($row) => [$row->id => $this->personName((array) $row).' ('.$row->staff_number.')'])->all(),
        ];
    }

    /** As the API takes it: a day without its time, none for today. */
    private function sent(array $data): array
    {
        if (empty($data['valid_from'])) unset($data['valid_from']);
        else $data['valid_from'] = substr((string) $data['valid_from'], 0, 10);
        return $data;
    }
}
