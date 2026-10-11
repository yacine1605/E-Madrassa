<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\CustomFieldController;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * FR-REF-04: the fields a school adds to students, guardians, staff and leads. Key and type are
 * permanent; a list's values are added, never removed; twenty active per record at most.
 */
final class CustomFields extends ReferencePage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 90;

    public static function getNavigationLabel(): string { return __('admin.custom_fields'); }
    public function getTitle(): string { return __('admin.custom_fields'); }

    public function table(Table $table): Table
    {
        return $table->records(function (?string $search, array $filters, int $page, int $recordsPerPage) {
            $data = ['include_archived' => true];
            if ($entity = $filters['entity']['value'] ?? null) $data['entity'] = $entity;
            $rows = array_map(fn (array $row) => [...$row, 'code' => $row['key']],
                SchoolScreen::call('referential.node.read', CustomFieldController::class, 'index', $data)['data']);
            return $this->catalogPage($this->filterArchived($rows, $filters), $search, $page, $recordsPerPage);
        })->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->columns([
            TextColumn::make('entity')->label(__('admin.entity'))->badge()->formatStateUsing(fn (string $state) => __('admin.entities.'.$state)),
            TextColumn::make('key')->label(__('admin.key'))->searchable(),
            TextColumn::make('labels')->label(__('school.name'))->state(fn (array $record) => Locales::label($record['labels'])),
            TextColumn::make('field_type')->label(__('admin.field_type'))->formatStateUsing(fn (string $state) => __('admin.field_types.'.$state)),
            IconColumn::make('required')->label(__('admin.required'))->boolean(),
            IconColumn::make('is_sensitive')->label(__('admin.sensitive'))->boolean(),
            TextColumn::make('sort_order')->label(__('reference.sort_order')),
            TextColumn::make('archived_at')->label(__('school.status'))
                ->state(fn (array $record) => __('reference.archive_states.'.($record['archived_at'] ? 'archived' : 'active'))),
        ])->filters([SelectFilter::make('entity')->label(__('admin.entity'))->options(__('admin.entities')), $this->archiveFilter()])->headerActions([
            Action::make('create')->label(__('admin.create_field'))->visible(fn () => $this->writable('custom_field.definition.manage'))
                ->schema([
                    Select::make('entity')->label(__('admin.entity'))->options(__('admin.entities'))->required(),
                    TextInput::make('key')->label(__('admin.key'))->required()->regex('/^[a-z][a-z0-9_]{1,39}$/')->helperText(__('admin.key_help')),
                    Select::make('field_type')->label(__('admin.field_type'))->options(__('admin.field_types'))->required()->live()->helperText(__('admin.type_help')),
                    ...$this->fields(fn (Get $get) => $get('field_type') === 'select'),
                ])
                ->action(fn (array $data) => SchoolScreen::change('custom_field.definition.manage',
                    fn () => SchoolScreen::call('custom_field.definition.manage', CustomFieldController::class, 'store', $data))),
        ])->recordActions([
            Action::make('edit')->label(__('school.edit'))->visible(fn (array $record) => !$record['archived_at'] && $this->writable('custom_field.definition.manage'))
                ->fillForm(fn (array $record) => $record)
                ->schema(fn (array $record) => [...$this->fields(fn () => $record['field_type'] === 'select'), Hidden::make('version')->required()])
                ->action(fn (array $record, array $data) => SchoolScreen::change('custom_field.definition.manage',
                    fn () => SchoolScreen::call('custom_field.definition.manage', CustomFieldController::class, 'update', $data, ['field' => $record['id']]))),
            Action::make('archive')->label(__('school.archive'))->color('danger')->requiresConfirmation()
                ->visible(fn (array $record) => !$record['archived_at'] && $this->writable('custom_field.definition.manage'))->schema([$this->reason()])
                ->action(fn (array $record, array $data) => SchoolScreen::change('custom_field.definition.manage',
                    fn () => SchoolScreen::call('custom_field.definition.manage', CustomFieldController::class, 'archive', $data, ['field' => $record['id']]))),
        ]);
    }

    /** What may change after creation: labels, the list's values (added only), obligation, sensitivity, order. */
    private function fields(\Closure $isList): array
    {
        return [
            ...$this->labelFields(),
            Repeater::make('options')->label(__('admin.options'))->helperText(__('admin.options_help'))->addActionLabel(__('admin.add_option'))
                ->visible($isList)->required($isList)->minItems(1)->maxItems(100)->reorderable(false)->columns(3)
                ->schema([TextInput::make('code')->label(__('school.code'))->required()->regex('/^[A-Z0-9_]{1,20}$/'), ...$this->labelFields()]),
            Toggle::make('required')->label(__('admin.required')),
            Toggle::make('is_sensitive')->label(__('admin.sensitive'))->helperText(__('admin.sensitive_help')),
            $this->orderField(1000),
        ];
    }
}
