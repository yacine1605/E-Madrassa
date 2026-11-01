<?php

namespace App\Filament\School\Pages;

use App\Filament\School\Pages\Concerns\WritesActivities;
use App\Http\Controllers\ActivityController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * FR-LMS-02, FR-LMS-03, « Activités »: the quizzes and homework of the groups' subjects the teacher
 * teaches today, every one for the pedagogy manager; their deadline, state and audience. A new one
 * is written here, then followed on its sheet: questions, publication, correction and results.
 */
final class Activities extends SchoolingPage
{
    use WritesActivities;

    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 32;
    protected static ?string $section = 'pedagogy';
    protected static array $permissions = ['lms.activity.manage'];
    protected static ?string $module = 'lms';
    protected static string|array $routeMiddleware = ['module:lms', 'permission:lms.activity.manage'];

    public static function getNavigationLabel(): string { return __('pedagogy.activities'); }
    public function getTitle(): string { return __('pedagogy.activities'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (array $filters, int $page, int $recordsPerPage) => $this->apiPage(static::$permissions, ActivityController::class, 'index',
            array_filter(['group_id' => $filters['group']['value'] ?? null, 'include_archived' => ($filters['archived']['isActive'] ?? false) ?: null]), $page, $recordsPerPage))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->description(__('pedagogy.activities_help'))
            ->emptyStateHeading(__('pedagogy.no_activity'))
            ->recordUrl(fn (array $record) => ActivitySheet::getUrl(['activity' => $record['id']]))
            ->columns([
                TextColumn::make('title')->label(__('pedagogy.title'))->description(fn (array $record) => __('pedagogy.kinds.'.$record['kind'])),
                TextColumn::make('group_code')->label(__('planning.group'))
                    ->description(fn (array $record) => $record['subject_code'].' · '.Locales::label($record['subject_labels'] ?? [])),
                TextColumn::make('deadline_at')->label(__('pedagogy.deadline_at'))->formatStateUsing(fn ($state) => Formats::dateTime($state)),
                TextColumn::make('state')->label(__('school.status'))->badge()->formatStateUsing(fn ($state) => __('pedagogy.activity_states.'.$state))
                    ->color(fn ($state) => match ($state) { 'open' => 'success', 'scheduled' => 'info', 'draft' => 'warning', default => 'gray' }),
                TextColumn::make('audience')->label(__('pedagogy.audience'))->formatStateUsing(fn ($state) => Formats::number((int) $state)),
            ])->filters([
                SelectFilter::make('group')->label(__('planning.group'))->searchable()
                    ->options(fn () => collect($this->targetOptions())->mapWithKeys(fn (string $label, string $key) => [explode(':', $key)[0] => explode(' — ', $label)[0]])->all()),
                Filter::make('archived')->label(__('pedagogy.include_archived'))->toggle(),
            ])
            ->headerActions([
                Action::make('create')->label(__('pedagogy.create_activity'))->visible(fn () => $this->writesActivities() && $this->targetOptions() !== [])
                    ->modalWidth('3xl')->schema(fn () => $this->activityFields(true))
                    ->action(function (array $data) {
                        $created = SchoolScreen::change(null, fn () => SchoolScreen::call(static::$permissions, ActivityController::class, 'store', $this->sentActivity($data)));
                        $this->redirect(ActivitySheet::getUrl(['activity' => $created['data']['id']]));
                    }),
            ]);
    }
}
