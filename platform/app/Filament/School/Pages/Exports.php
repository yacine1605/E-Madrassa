<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\ExportController;
use App\Http\Controllers\ReferentialController;
use App\Support\Exporter;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\Permissions;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * FR-IMP-02: the data a person may read on screen, as CSV, Excel or PDF, in the language
 * chosen; ready at once up to 2 000 rows, made in the background beyond. Each file is kept 7 days.
 */
final class Exports extends SchoolPage implements HasTable
{
    use InteractsWithTable;

    protected static bool $isDiscovered = true;
    protected static array $permissions = ['export.job.create'];
    protected static ?string $section = 'data';
    protected static ?int $navigationSort = 140;
    protected static string|array $routeMiddleware = 'permission:export.job.create';
    protected string $view = 'filament.school.table';

    public static function getNavigationLabel(): string { return __('services.exports'); }
    public function getTitle(): string { return __('services.exports'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (int $page, int $recordsPerPage) => $this->apiPage('export.job.create', ExportController::class, 'index', [],
            $page, $recordsPerPage))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->columns([
                TextColumn::make('kind')->label(__('services.export_kind'))->formatStateUsing(fn (string $state) => __('services.export_kinds.'.$state)),
                TextColumn::make('format')->label(__('services.format'))->formatStateUsing(fn (string $state) => __('services.formats.'.$state)),
                TextColumn::make('row_count')->label(__('services.rows'))->formatStateUsing(fn ($state) => Formats::number((int) $state)),
                TextColumn::make('author')->label(__('services.author')),
                TextColumn::make('created_at')->label(__('services.created_at'))->formatStateUsing(fn ($state) => Formats::dateTime($state)),
                TextColumn::make('purged')->label(__('school.status'))->state(fn (array $record) => $record['purged'] ? __('services.purged') : null),
            ])->headerActions([
                Action::make('export')->label(__('services.new_export'))->visible(fn () => $this->kinds() !== [] && SchoolScreen::writable('export.job.create'))
                    ->fillForm(['format' => 'xlsx', 'locale' => app()->getLocale()])
                    ->schema([
                        Select::make('kind')->label(__('services.export_kind'))->options(fn () => $this->kinds())->required()->live(),
                        Select::make('format')->label(__('services.format'))->options(__('services.formats'))->required(),
                        Select::make('locale')->label(__('services.language'))->options(array_intersect_key(Locales::NAMES, array_flip(Locales::AVAILABLE)))->required(),
                        Select::make('status')->label(__('services.account_status'))->options(__('school.account_status'))
                            ->visible(fn (Get $get) => $get('kind') === 'accounts'),
                        Select::make('type')->label(__('services.node_type'))->options(fn () => $this->nodeTypes())
                            ->visible(fn (Get $get) => $get('kind') === 'referential'),
                        Toggle::make('include_archived')->label(__('services.include_archived'))->visible(fn (Get $get) => $get('kind') === 'referential'),
                    ])
                    ->action(fn (array $data) => SchoolScreen::change('export.job.create',
                        fn () => SchoolScreen::call('export.job.create', ExportController::class, 'store', ['kind' => $data['kind'], 'format' => $data['format'],
                            'locale' => $data['locale'], 'filters' => $this->filters($data)]),
                        fn (array $result) => isset($result['data']['job'])
                            ? Notification::make()->title(__('services.export_queued'))->info()
                            : Notification::make()->title(__('services.export_ready', ['rows' => Formats::number($result['data']['rows'])]))->success()->persistent()
                                ->actions([Action::make('download')->label(__('services.download'))->url('/api/files/'.$result['data']['file']['id'].'/content')->openUrlInNewTab()]))),
            ])->recordActions([
                Action::make('download')->label(__('services.download'))->openUrlInNewTab()
                    ->visible(fn (array $record) => $record['file_id'] && !$record['purged'] && $record['user_id'] === auth()->id())
                    ->url(fn (array $record) => '/api/files/'.$record['file_id'].'/content'),
            ]);
    }

    /** @return array<string, string> what this person may export: what they may read on screen (P5) */
    private function kinds(): array
    {
        return collect(array_keys(config('platform.exports')))
            ->filter(fn (string $kind) => app(Permissions::class)->can(app(Exporter::class)->dataset($kind)->permission()))
            ->mapWithKeys(fn (string $kind) => [$kind => __('services.export_kinds.'.$kind)])->all();
    }

    private function filters(array $data): array
    {
        return match ($data['kind']) {
            'accounts' => array_filter(['status' => $data['status'] ?? null]),
            'referential' => array_filter(['type' => $data['type'] ?? null]) + ['include_archived' => (bool) ($data['include_archived'] ?? false)],
            default => [],
        };
    }

    private function nodeTypes(): array
    {
        return collect(SchoolScreen::call('referential.node.read', ReferentialController::class, 'types')['data'])
            ->mapWithKeys(fn (array $type) => [$type['code'] => Locales::label($type['labels'])])->all();
    }
}
