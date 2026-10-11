<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\JobController;
use App\Support\Formats;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/** FR-JOB-01, « Mes tâches »: the person's own background jobs here, as row-level security shows them; refreshed while open. */
final class Tasks extends SchoolPage implements HasTable
{
    use InteractsWithTable;

    protected static bool $isDiscovered = true;
    protected static ?string $section = 'personal';
    protected static ?int $navigationSort = 1;
    protected string $view = 'filament.school.table';

    public static function getNavigationLabel(): string { return __('services.tasks'); }
    public function getTitle(): string { return __('services.tasks'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (int $page, int $recordsPerPage) => $this->apiPage([], JobController::class, 'index', [], $page, $recordsPerPage))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->poll('5s')->columns([
                TextColumn::make('kind')->label(__('services.task'))->formatStateUsing(fn (string $state) => __('services.task_kinds.'.$state)),
                TextColumn::make('status')->label(__('school.status'))->badge()->formatStateUsing(fn (string $state) => __('services.task_states.'.$state))
                    ->color(fn (string $state) => ['queued' => 'gray', 'running' => 'info', 'succeeded' => 'success', 'failed' => 'danger', 'cancelled' => 'gray'][$state]),
                TextColumn::make('progress')->label(__('services.progress'))->formatStateUsing(fn ($state) => Formats::number((int) $state).' %'),
                TextColumn::make('error_message')->label(__('services.message'))->wrap()
                    ->state(fn (array $record) => $record['cancel_requested_at'] && $record['status'] === 'running' ? __('services.cancel_asked') : $record['error_message']),
                TextColumn::make('created_at')->label(__('services.created_at'))->formatStateUsing(fn ($state) => Formats::dateTime($state)),
                TextColumn::make('finished_at')->label(__('services.finished_at'))->formatStateUsing(fn ($state) => Formats::dateTime($state)),
            ])->recordActions([
                Action::make('download')->label(__('services.download'))->openUrlInNewTab()
                    ->visible(fn (array $record) => $record['status'] === 'succeeded' && isset($record['result']['file_id']))
                    ->url(fn (array $record) => '/api/files/'.$record['result']['file_id'].'/content'),
                Action::make('cancel')->label(__('services.cancel'))->color('danger')->requiresConfirmation()
                    ->visible(fn (array $record) => in_array($record['status'], ['queued', 'running'], true) && !$record['cancel_requested_at'] && $this->changeable())
                    ->action(fn (array $record) => SchoolScreen::change(null, fn () => SchoolScreen::call([], JobController::class, 'cancel', parameters: ['job' => $record['id']]))),
                Action::make('retry')->label(__('services.retry'))->requiresConfirmation()
                    ->visible(fn (array $record) => $record['status'] === 'failed' && $this->changeable())
                    ->action(fn (array $record) => SchoolScreen::change(null, fn () => SchoolScreen::call([], JobController::class, 'retry', parameters: ['job' => $record['id']]))),
            ]);
    }

    /** One's own jobs need no permission; a read-only school or a support session still changes nothing. */
    private function changeable(): bool
    {
        return SchoolScreen::writable(null);
    }
}
