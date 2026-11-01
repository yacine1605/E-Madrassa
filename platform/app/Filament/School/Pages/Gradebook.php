<?php

namespace App\Filament\School\Pages;

use App\Filament\School\Pages\Concerns\WritesActivities;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\GradingController;
use App\Support\Formats;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;

/**
 * FR-LMS-04, « Carnet de notes »: a group's subject the teacher teaches (any for the pedagogy manager)
 * over a period, the school year by default. Each pupil, each activity's mark on its own rule's
 * scale, whether it is published, and the weighted average of the published marks only, rounded
 * once on the rule in force at the period's end (SQL 084). Exported as it shows, published marks only.
 */
final class Gradebook extends SchoolingPage
{
    use WritesActivities;

    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 33;
    protected static ?string $section = 'pedagogy';
    protected static array $permissions = ['lms.activity.manage'];
    protected static ?string $module = 'lms';
    protected static string|array $routeMiddleware = ['module:lms', 'permission:lms.activity.manage'];
    private ?array $bookRows = null;

    public static function getNavigationLabel(): string { return __('pedagogy.gradebook'); }
    public function getTitle(): string { return __('pedagogy.gradebook'); }

    public function table(Table $table): Table
    {
        return $table->paginated(false)->records(fn (array $filters) => $this->book($filters))
            ->description(__('pedagogy.gradebook_help'))
            ->emptyStateHeading(fn () => __($this->targetOptions() === [] ? 'pedagogy.no_target' : 'pedagogy.no_mark'))
            ->columns([
                TextColumn::make('pupil')->label(__('schooling.student'))->state(fn (array $record) => $this->personName($record['student'] ?? []))
                    ->description(fn (array $record) => $record['student']['matricule'] ?? null),
                TextColumn::make('marks')->label(__('pedagogy.marks'))->listWithLineBreaks()->state(fn (array $record) => array_map(fn (array $mark) => $this->markLine($mark), $record['marks'])),
                TextColumn::make('average')->label(__('pedagogy.average'))->placeholder(__('pedagogy.no_average'))->weight('bold')
                    ->state(fn (array $record) => $record['average'] === null ? null
                        : Formats::number((float) $record['average']['average'], 2).' / '.Formats::number((float) $record['average']['scale_max'], 2))
                    ->description(fn (array $record) => $record['average'] === null ? null
                        : __('pedagogy.marks_counted', ['count' => Formats::number((int) $record['average']['marks'])]))
                    ->color(fn (array $record) => $record['average'] === null ? null : ($record['average']['passed'] ? 'success' : 'danger')),
            ])->filters([
                Filter::make('book')->columnSpanFull()->columns(['default' => 1, 'sm' => 3])->schema([
                    Select::make('target')->label(__('pedagogy.target'))->searchable()->options(fn () => $this->targetOptions())
                        ->default(fn () => array_key_first($this->targetOptions())),
                    DatePicker::make('from')->label(__('dashboard.period_from'))->default($this->yearStart()),
                    DatePicker::make('to')->label(__('dashboard.period_to'))->default($this->yearEnd()),
                ]),
            ])->filtersFormColumns(1)
            ->headerActions([
                Action::make('export')->label(__('services.new_export'))->color('gray')
                    ->visible(fn () => $this->writable('export.job.create') && $this->scope($this->tableFilters ?? []) !== null)
                    ->schema([Radio::make('format')->label(__('services.format'))->required()->default('xlsx')->options(__('services.formats'))])
                    ->action(fn (array $data) => SchoolScreen::change('export.job.create', fn () => SchoolScreen::call('export.job.create', ExportController::class, 'store',
                        ['kind' => 'gradebook', 'format' => $data['format'], 'filters' => $this->scope($this->tableFilters ?? [])]),
                        fn (array $result) => isset($result['data']['job'])
                            ? Notification::make()->title(__('services.export_queued'))->info()
                            : Notification::make()->title(__('services.export_ready', ['rows' => Formats::number($result['data']['rows'])]))->success()->persistent()
                                ->actions([Action::make('download')->label(__('services.download'))->url('/api/files/'.$result['data']['file']['id'].'/content')->openUrlInNewTab()]))),
            ]);
    }

    /** The group, subject and period chosen, as the API and the export take them; none until a group's subject is. */
    private function scope(array $filters): ?array
    {
        $target = $filters['book']['target'] ?? array_key_first($this->targetOptions());
        if ($target === null || !str_contains($target, ':')) return null;
        [$group, $subject] = explode(':', $target);
        $from = substr((string) ($filters['book']['from'] ?? $this->yearStart()), 0, 10);
        $to = substr((string) ($filters['book']['to'] ?? $this->yearEnd()), 0, 10);
        return ['group_id' => $group, 'subject_id' => $subject, 'from' => $from, 'to' => max($from, $to)];
    }

    private function book(array $filters): array
    {
        if ($this->bookRows !== null) return $this->bookRows;
        $scope = $this->scope($filters);
        if ($scope === null) return $this->bookRows = [];
        $rows = SchoolScreen::call(static::$permissions, GradingController::class, 'gradebook',
            ['subject_id' => $scope['subject_id'], 'from' => $scope['from'], 'to' => $scope['to']], ['group' => $scope['group_id']])['data'];
        return $this->bookRows = collect($rows)->keyBy('student_id')->map(fn (array $row) => [...$row, 'id' => $row['student_id']])->all();
    }

    /** « Fractions : 7,50 / 10 », « (non publiée) » while it is not, or the state when there is no mark. */
    private function markLine(array $mark): string
    {
        $value = $mark['published_mark'] ?? $mark['mark'];
        $shown = $value === null ? __('pedagogy.mark_states.'.$mark['status'])
            : Formats::number((float) $value, 2).' / '.Formats::number((float) $mark['scale_max'], 2).($mark['published_mark'] === null ? ' '.__('pedagogy.unpublished_mark') : '');
        return $mark['title'].' : '.$shown;
    }

    /** The school year in Algeria: from the 1st of September to the 31st of August. */
    private function yearStart(): string
    {
        $today = now('Africa/Algiers');
        return ($today->month >= 9 ? $today->year : $today->year - 1).'-09-01';
    }

    private function yearEnd(): string { return ((int) substr($this->yearStart(), 0, 4) + 1).'-08-31'; }
}
