<?php

namespace App\Filament\School\Pages;

use App\Filament\School\Pages\Concerns\ReadsOwnActivities;
use App\Http\Controllers\MyActivityController;
use App\Support\Formats;
use App\Support\SchoolScreen;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * FR-LMS-02 to FR-LMS-04 for the pupil, « Mes activités »: their averages over the school year,
 * subject by subject, from their published marks only; then the activities of the groups they learn
 * in today, open ones first, with their own deadline and what is published of their result. Nothing
 * of another pupil, nothing not published (SQL 080, 083, 084).
 */
final class MyActivities extends SchoolingPage
{
    use ReadsOwnActivities;

    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 35;
    protected static ?string $section = 'pedagogy';
    protected static array $permissions = ['lms.activity.take'];
    protected static ?string $module = 'lms';
    protected static string|array $routeMiddleware = ['module:lms', 'permission:lms.activity.take'];
    protected string $view = 'filament.school.my-activities';

    public static function getNavigationLabel(): string { return __('pedagogy.my_activities'); }
    public function getTitle(): string { return __('pedagogy.my_activities'); }

    /** The school year's averages, subject by subject. */
    public function averagesInfolist(Schema $schema): Schema
    {
        $averages = array_map(fn (array $row) => ['subject' => $this->subjectName($row['subject_id']), 'average' => $this->onScale($row['average'], $row['scale_max']),
            'marks' => __('pedagogy.marks_counted', ['count' => Formats::number((int) $row['marks'])]),
            'passed' => __($row['passed'] ? 'pedagogy.passed' : 'pedagogy.not_passed')], $this->averages());
        return $schema->state(['averages' => $averages])->components([
            Section::make(__('pedagogy.my_averages'))->description(__('pedagogy.my_averages_help'))->schema([
                RepeatableEntry::make('averages')->hiddenLabel()->placeholder(__('pedagogy.no_average'))->columns(['default' => 2, 'sm' => 4])->schema([
                    TextEntry::make('subject')->hiddenLabel()->weight('bold'),
                    TextEntry::make('average')->hiddenLabel(),
                    TextEntry::make('marks')->hiddenLabel()->color('gray'),
                    TextEntry::make('passed')->hiddenLabel()->badge(),
                ]),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->paginated(false)->heading(__('pedagogy.activities'))
            ->records(fn () => collect(SchoolScreen::call(static::$permissions, MyActivityController::class, 'index')['data'])
                ->mapWithKeys(fn (array $row) => [$row['activity_id'] => [...$row, 'id' => $row['activity_id']]])->all())
            ->description(__('pedagogy.my_activities_help'))->emptyStateHeading(__('pedagogy.no_activity'))->emptyStateDescription(__('pedagogy.no_my_activity_help'))
            ->recordUrl(fn (array $record) => MyActivity::getUrl(['activity' => $record['id']]))
            ->columns([
                TextColumn::make('title')->label(__('pedagogy.title'))->description(fn (array $record) => __('pedagogy.kinds.'.$record['kind'])),
                TextColumn::make('subject')->label(__('planning.subject'))->state(fn (array $record) => $this->subjectName($record['subject_id'])),
                TextColumn::make('deadline_at')->label(__('pedagogy.deadline_at'))->formatStateUsing(fn ($state) => Formats::dateTime($state))
                    ->description(fn (array $record) => $record['extended'] ? __('pedagogy.extended') : null),
                TextColumn::make('state')->label(__('school.status'))->badge()->formatStateUsing(fn ($state) => __('pedagogy.activity_states.'.$state))
                    ->color(fn ($state) => match ($state) { 'open' => 'success', 'scheduled' => 'info', default => 'gray' }),
                TextColumn::make('result')->label(__('pedagogy.my_result'))->placeholder(__('pedagogy.not_published'))
                    ->state(fn (array $record) => $this->resultLine($record['id'])),
            ]);
    }
}
