<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\ReferentialController;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class ReferenceNodes extends ReferencePage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 50;
    // Private properties are request-local; Livewire never carries these catalogs across requests.
    private ?array $typeRows = null;
    private ?array $nodeNames = null;

    public static function getNavigationLabel(): string { return __('reference.nodes'); }
    public function getTitle(): string { return __('reference.nodes'); }

    public function table(Table $table): Table
    {
        return $table->records(function (?string $search, array $filters, int $page, int $recordsPerPage) {
            $data = ['include_archived' => true];
            if ($type = $filters['type']['value'] ?? null) $data['type'] = $type;
            $rows = SchoolScreen::call('referential.node.read', ReferentialController::class, 'nodes', $data)['data'];
            return $this->catalogPage($this->filterArchived($rows, $filters), $search, $page, $recordsPerPage);
        })->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->columns([
            TextColumn::make('code')->label(__('school.code'))->searchable(),
            TextColumn::make('labels')->label(__('school.name'))->state(fn (array $record) => Locales::label($record['labels'])),
            TextColumn::make('type_code')->label(__('reference.type'))->formatStateUsing(fn (string $state) => Locales::label($this->types()[$state]['labels'] ?? null) ?? $state),
            TextColumn::make('parent_id')->label(__('reference.parent'))->formatStateUsing(fn (string $state) => $this->nodeLabels()[$state] ?? $state),
            TextColumn::make('sort_order')->label(__('reference.sort_order')),
            TextColumn::make('archived_at')->label(__('school.status'))->state(fn (array $record) => __('reference.archive_states.'.($record['archived_at'] ? 'archived' : 'active'))),
        ])->filters([
            SelectFilter::make('type')->label(__('reference.type'))->options(fn () => collect($this->types())->mapWithKeys(fn ($row) => [$row['code'] => Locales::label($row['labels'])])->all()),
            $this->archiveFilter(),
        ])->headerActions([
            Action::make('create')->label(__('reference.create_node'))->visible(fn () => $this->writable('referential.node.manage') && collect($this->types())->contains('active', true))
                ->schema([
                    Select::make('type')->label(__('reference.type'))->required()->live()->options(fn () => collect($this->types())->where('active', true)
                        ->mapWithKeys(fn ($row) => [$row['code'] => Locales::label($row['labels'])])->all())
                        ->afterStateUpdated(fn (Set $set) => $set('parent_id', null)),
                    $this->codeField(), ...$this->labelFields(),
                    Select::make('parent_id')->label(__('reference.parent'))->searchable()
                        ->visible(fn (Get $get) => $this->parentType($get('type')) !== null)
                        ->required(fn (Get $get) => $this->parentType($get('type')) !== null)
                        ->options(fn (Get $get) => $this->parentOptions($get('type'))), $this->orderField(),
                ])->action(function (array $data) {
                    if ($this->parentType($data['type']) === null) unset($data['parent_id']);
                    SchoolScreen::change('referential.node.manage', fn () => SchoolScreen::call('referential.node.manage', ReferentialController::class, 'store', $data));
                }),
        ])->recordActions([
            Action::make('edit')->label(__('school.edit'))->visible(fn (array $record) => !$record['archived_at'] && $this->writable('referential.node.manage'))
                ->fillForm(fn (array $record) => $record)->schema([...$this->labelFields(), $this->orderField(), Hidden::make('version')->required()])
                ->action(fn (array $record, array $data) => SchoolScreen::change('referential.node.manage',
                    fn () => SchoolScreen::call('referential.node.manage', ReferentialController::class, 'update', $data, ['node' => $record['id']]))),
            Action::make('archive')->label(__('school.archive'))->color('danger')->requiresConfirmation()
                ->visible(fn (array $record) => !$record['archived_at'] && $this->writable('referential.node.manage'))->schema([$this->reason()])
                ->action(fn (array $record, array $data) => SchoolScreen::change('referential.node.manage',
                    fn () => SchoolScreen::call('referential.node.manage', ReferentialController::class, 'archive', $data, ['node' => $record['id']]))),
        ]);
    }

    private function types(): array
    {
        return $this->typeRows ??= collect(SchoolScreen::call('referential.node.read', ReferentialController::class, 'types')['data'])->keyBy('code')->all();
    }

    private function parentType(?string $type): ?string { return $this->types()[$type ?? '']['parent_code'] ?? null; }

    private function parentOptions(?string $type): array
    {
        $parent = $this->parentType($type);
        if (!$parent) return [];
        return collect(SchoolScreen::call('referential.node.read', ReferentialController::class, 'nodes', ['type' => $parent])['data'])
            ->mapWithKeys(fn ($row) => [$row['id'] => $row['code'].' · '.Locales::label($row['labels'])])->all();
    }

    private function nodeLabels(): array
    {
        return $this->nodeNames ??= collect(SchoolScreen::call('referential.node.read', ReferentialController::class, 'nodes', ['include_archived' => true])['data'])
            ->mapWithKeys(fn ($row) => [$row['id'] => $row['code'].' · '.Locales::label($row['labels'])])->all();
    }
}
