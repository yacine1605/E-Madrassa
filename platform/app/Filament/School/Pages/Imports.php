<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\ImportController;
use App\Support\Formats;
use App\Support\Imports as ImportService;
use App\Support\Permissions;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * FR-IMP-01: a file previewed without writing anything, then confirmed; each rejected row with
 * its cause, a downloadable report, and the whole import undone while nothing depends on it.
 * Each kind of data needs the permission that creates it on screen (P5).
 */
final class Imports extends SchoolPage implements HasTable
{
    use InteractsWithTable;

    protected static bool $isDiscovered = true;
    protected static array $permissions = ['import.job.create', 'import.job.read'];
    protected static ?string $section = 'data';
    protected static ?int $navigationSort = 130;
    protected static string|array $routeMiddleware = 'permission:import.job.create,import.job.read';
    protected string $view = 'filament.school.table';

    public static function getNavigationLabel(): string { return __('services.imports'); }
    public function getTitle(): string { return __('services.imports'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (int $page, int $recordsPerPage) => $this->apiPage(self::$permissions, ImportController::class, 'index', [],
            $page, $recordsPerPage))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->poll('5s')->columns([
                TextColumn::make('kind')->label(__('services.import_kind'))->formatStateUsing(fn (string $state) => __('services.import_kinds.'.$state)),
                TextColumn::make('status')->label(__('school.status'))->badge()->formatStateUsing(fn (string $state) => __('services.import_states.'.$state))
                    ->color(fn (string $state) => ['previewed' => 'warning', 'imported' => 'success', 'failed' => 'danger'][$state] ?? 'gray'),
                TextColumn::make('rows_total')->label(__('services.rows_total'))->formatStateUsing(fn ($state) => Formats::number((int) $state)),
                TextColumn::make('rows_valid')->label(__('services.rows_valid'))->formatStateUsing(fn ($state) => Formats::number((int) $state)),
                TextColumn::make('rows_rejected')->label(__('services.rows_rejected'))->formatStateUsing(fn ($state) => Formats::number((int) $state)),
                TextColumn::make('error_message')->label(__('services.message'))->wrap(),
                TextColumn::make('created_at')->label(__('services.created_at'))->formatStateUsing(fn ($state) => Formats::dateTime($state)),
            ])->headerActions([
                Action::make('import')->label(__('services.new_import'))->visible(fn () => $this->kinds() !== [] && $this->writable('import.job.create'))
                    ->schema([
                        Select::make('kind')->label(__('services.import_kind'))->options(fn () => $this->kinds())->required()->live(),
                        FileUpload::make('file')->label(__('services.file'))->storeFiles(false)->required()->maxSize(20480)
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']),
                        Toggle::make('invite')->label(__('services.invite'))->visible(fn (Get $get) => $get('kind') === 'accounts'),
                    ])
                    ->action(fn (array $data) => SchoolScreen::change('import.job.create', fn () => SchoolScreen::call('import.job.create', ImportController::class, 'store',
                        ['kind' => $data['kind'], 'file' => $data['file'], 'options' => ['invite' => (bool) ($data['invite'] ?? false)]]),
                        fn () => Notification::make()->title(__('services.started'))->success())),
                Action::make('template')->label(__('services.template'))->visible(fn () => $this->kinds() !== [])
                    ->schema([Select::make('kind')->label(__('services.import_kind'))->options(fn () => $this->kinds())->required(),
                        Select::make('format')->label(__('services.format'))->options(array_intersect_key(__('services.formats'), ['xlsx' => 1, 'csv' => 1]))
                            ->default('xlsx')->required()])
                    ->action(fn (array $data) => $this->redirect('/api/imports/templates/'.$data['kind'].'?format='.$data['format'])),
            ])->recordActions([
                Action::make('rejected')->label(__('services.rejected'))->modalSubmitAction(false)->visible(fn (array $record) => $record['rows_rejected'] > 0)
                    ->modalContent(fn (array $record) => view('filament.school.import-rejections', ['rows' => SchoolScreen::call(self::$permissions,
                        ImportController::class, 'rows', ['outcome' => 'rejected'], ['batch' => $record['id']])['data']])),
                Action::make('report')->label(__('services.report'))->visible(fn (array $record) => $record['rows_rejected'] > 0)
                    ->url(fn (array $record) => '/api/imports/'.$record['id'].'/report'),
                Action::make('confirm')->label(__('services.confirm'))->requiresConfirmation()->modalDescription(__('services.confirm_help'))
                    ->visible(fn (array $record) => $record['status'] === 'previewed' && $this->mayRun($record))
                    ->action(fn (array $record) => SchoolScreen::change('import.job.create',
                        fn () => SchoolScreen::call('import.job.create', ImportController::class, 'confirm', parameters: ['batch' => $record['id']]))),
                Action::make('rollback')->label(__('services.rollback'))->color('danger')->requiresConfirmation()->modalDescription(__('services.rollback_help'))
                    ->visible(fn (array $record) => $record['status'] === 'imported' && $this->mayRun($record))
                    ->action(fn (array $record) => SchoolScreen::change('import.job.create',
                        fn () => SchoolScreen::call('import.job.create', ImportController::class, 'rollback', parameters: ['batch' => $record['id']]))),
            ]);
    }

    /** @return array<string, string> the kinds this person may import: those whose records they may create */
    private function kinds(): array
    {
        if (!app(Permissions::class)->can('import.job.create')) return [];
        return collect(array_keys(config('platform.imports')))
            ->filter(fn (string $kind) => app(Permissions::class)->can(app(ImportService::class)->importer($kind)->permission()))
            ->mapWithKeys(fn (string $kind) => [$kind => __('services.import_kinds.'.$kind)])->all();
    }

    private function mayRun(array $record): bool
    {
        return $this->writable('import.job.create') && array_key_exists($record['kind'], $this->kinds());
    }
}
