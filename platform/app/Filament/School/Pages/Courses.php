<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\CourseController;
use App\Support\Locales;
use App\Support\Permissions;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * FR-LMS-01, « Cours » (« Mes cours » for whoever only reads them): the courses the reader reaches,
 * every one for the pedagogy manager and the direction, a teacher's subjects', a pupil's programmes'.
 * The manager creates a programme's subject's course and opens its outline; the others read it.
 */
final class Courses extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 31;
    protected static ?string $section = 'pedagogy';
    protected static array $permissions = ['lms.course.read', 'lms.course.manage'];
    protected static ?string $module = 'lms';
    protected static string|array $routeMiddleware = ['module:lms', 'permission:lms.course.read,lms.course.manage'];

    public static function getNavigationLabel(): string { return __(self::manages() ? 'pedagogy.courses' : 'pedagogy.my_courses'); }
    public function getTitle(): string { return self::getNavigationLabel(); }

    private static function manages(): bool { return app(Permissions::class)->can('lms.course.manage'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (int $page, int $recordsPerPage) => $this->apiPage(static::$permissions, CourseController::class, 'index', [], $page, $recordsPerPage))
            ->paginationPageOptions([25, 50])->defaultPaginationPageOption(25)->description(__('pedagogy.courses_help'))
            ->emptyStateHeading(__('pedagogy.no_course'))
            ->recordUrl(fn (array $record) => self::manages() ? CourseOutline::getUrl(['course' => $record['id']]) : CourseReader::getUrl(['course' => $record['id']]))
            ->columns([
                TextColumn::make('subject_code')->label(__('planning.subject'))->description(fn (array $record) => Locales::label($record['subject_labels'] ?? [])),
                TextColumn::make('program_code')->label(__('schooling.program'))->description(fn (array $record) => Locales::label($record['program_labels'] ?? []))
                    ->visible(fn () => self::manages()),
                TextColumn::make('description')->label(__('pedagogy.description'))->limit(80)->wrap(),
            ])
            ->headerActions([
                Action::make('create')->label(__('pedagogy.create_course'))->visible(fn () => self::manages() && SchoolScreen::writable('lms.course.manage'))
                    ->schema([
                        Select::make('program_id')->label(__('schooling.program'))->required()->searchable()->options(fn () => $this->programOptions()),
                        Select::make('subject_id')->label(__('planning.subject'))->required()->searchable()->options(fn () => $this->subjectOptions())
                            ->helperText(__('pedagogy.course_subject_help')),
                        Textarea::make('description')->label(__('pedagogy.description'))->maxLength(2000),
                    ])
                    ->action(fn (array $data) => SchoolScreen::change('lms.course.manage', fn () => SchoolScreen::call('lms.course.manage',
                        CourseController::class, 'store', $data))),
            ])->recordActions([
                Action::make('read')->label(__('pedagogy.read_course'))->color('gray')->visible(fn () => self::manages())
                    ->url(fn (array $record) => CourseReader::getUrl(['course' => $record['id']])),
            ]);
    }

    /** @return array<string, string> the programmes in use, newest year first */
    private function programOptions(): array
    {
        return collect(DB::select("SELECT p.id, p.code, p.labels, y.code AS year FROM school.program p JOIN school.academic_year y ON y.tenant_id = p.tenant_id AND y.id = p.year_id
            WHERE p.tenant_id = ? AND p.archived_at IS NULL AND p.status <> 'inactive' ORDER BY y.start_date DESC, p.code", [$this->schoolId]))
            ->mapWithKeys(fn ($row) => [$row->id => $row->code.' · '.Locales::label(json_decode($row->labels, true)).' · '.$row->year])->all();
    }
}
