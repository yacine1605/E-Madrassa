<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\SubjectController;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

final class Subjects extends ReferencePage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 60;

    public static function getNavigationLabel(): string
    {
        $kind = DB::selectOne('SELECT p.subject_kind FROM platform.tenant t JOIN platform.profile p ON p.code = t.profile_code AND p.version = t.profile_version WHERE t.id = school.tenant()')?->subject_kind;
        return $kind === 'module' ? __('reference.modules') : __('reference.subjects');
    }

    public function getTitle(): string { return static::getNavigationLabel(); }

    public function table(Table $table): Table
    {
        return $table->records(function (?string $search, array $filters, int $page, int $recordsPerPage) {
            $rows = SchoolScreen::call('referential.node.read', SubjectController::class, 'index', ['include_archived' => true])['data'];
            return $this->catalogPage($this->filterArchived($rows, $filters), $search, $page, $recordsPerPage);
        })->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->columns([
            TextColumn::make('code')->label(__('school.code'))->searchable(),
            TextColumn::make('labels')->label(__('school.name'))->state(fn (array $record) => Locales::label($record['labels'])),
            TextColumn::make('description')->label(__('reference.description'))->limit(80),
            TextColumn::make('sort_order')->label(__('reference.sort_order')),
            TextColumn::make('archived_at')->label(__('school.status'))->state(fn (array $record) => __('reference.archive_states.'.($record['archived_at'] ? 'archived' : 'active'))),
        ])->filters([$this->archiveFilter()])->headerActions([
            Action::make('create')->label(__('reference.create_subject'))->visible(fn () => $this->writable('subject.subject.manage'))
                ->schema([$this->codeField(), ...$this->fields()])->action(fn (array $data) => SchoolScreen::change('subject.subject.manage',
                    fn () => SchoolScreen::call('subject.subject.manage', SubjectController::class, 'store', $data))),
        ])->recordActions([
            Action::make('edit')->label(__('school.edit'))->visible(fn (array $record) => !$record['archived_at'] && $this->writable('subject.subject.manage'))
                ->fillForm(fn (array $record) => $record)->schema([...$this->fields(), Hidden::make('version')->required()])
                ->action(fn (array $record, array $data) => SchoolScreen::change('subject.subject.manage',
                    fn () => SchoolScreen::call('subject.subject.manage', SubjectController::class, 'update', $data, ['subject' => $record['id']]))),
            Action::make('archive')->label(__('school.archive'))->color('danger')->requiresConfirmation()
                ->visible(fn (array $record) => !$record['archived_at'] && $this->writable('subject.subject.manage'))->schema([$this->reason()])
                ->action(fn (array $record, array $data) => SchoolScreen::change('subject.subject.manage',
                    fn () => SchoolScreen::call('subject.subject.manage', SubjectController::class, 'archive', $data, ['subject' => $record['id']]))),
            Action::make('delete')->label(__('reference.delete_unused'))->color('danger')->requiresConfirmation()->modalDescription(__('reference.delete_help'))
                ->visible(fn (array $record) => !$record['archived_at'] && $this->writable('subject.subject.manage'))
                ->action(fn (array $record) => SchoolScreen::change('subject.subject.manage',
                    fn () => SchoolScreen::call('subject.subject.manage', SubjectController::class, 'destroy', parameters: ['subject' => $record['id']]))),
        ]);
    }

    private function fields(): array
    {
        return [...$this->labelFields(), Textarea::make('description')->label(__('reference.description'))->maxLength(2000), $this->orderField()];
    }
}
