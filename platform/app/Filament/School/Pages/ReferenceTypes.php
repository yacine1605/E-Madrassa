<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\ReferentialController;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class ReferenceTypes extends ReferencePage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 40;

    public static function getNavigationLabel(): string { return __('reference.types'); }
    public function getTitle(): string { return __('reference.types'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (?string $search, int $page, int $recordsPerPage) => $this->catalogPage(
            SchoolScreen::call('referential.node.read', ReferentialController::class, 'types')['data'], $search, $page, $recordsPerPage))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->columns([
                TextColumn::make('code')->label(__('school.code'))->searchable(),
                TextColumn::make('labels')->label(__('school.name'))->state(fn (array $record) => Locales::label($record['labels'])),
                TextColumn::make('parent_code')->label(__('reference.parent_type')),
                IconColumn::make('is_required')->label(__('reference.required_type'))->boolean(),
                IconColumn::make('active')->label(__('reference.active'))->boolean(),
            ])->recordActions([
                Action::make('edit')->label(__('school.edit'))->visible(fn () => $this->writable('referential.node.manage'))
                    ->fillForm(fn (array $record) => $record)->schema([...$this->labelFields(), Hidden::make('version')->required(),
                        Toggle::make('active')->label(__('reference.active'))->required()
                            ->disabled(fn (array $record) => $record['is_required'])->dehydrated()])
                    ->action(fn (array $record, array $data) => SchoolScreen::change('referential.node.manage',
                        fn () => SchoolScreen::call('referential.node.manage', ReferentialController::class, 'updateType', $data, ['type' => $record['code']]))),
            ]);
    }
}
