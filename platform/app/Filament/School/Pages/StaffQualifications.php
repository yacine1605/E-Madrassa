<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\QualificationController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;

/**
 * FR-STF-02: the subjects a teacher may teach, each at a level, a cycle or a specialty of the
 * referential, whose descendants it covers; from a day, until another. A qualification ends, or is
 * removed while nothing uses it.
 */
final class StaffQualifications extends StaffMemberPage
{
    protected static bool $isDiscovered = true;

    protected function subject(): string { return __('staff.qualifications'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (array $filters, int $page, int $recordsPerPage) => $this->apiPage('staff.profile.read', QualificationController::class, 'index',
            array_filter(['include_ended' => ($filters['ended']['isActive'] ?? false) ?: null]), $page, $recordsPerPage, ['staff' => $this->staff]))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->description(__('staff.qualifications_help'))
            ->emptyStateHeading(__('staff.no_qualification'))->emptyStateDescription(__('staff.no_qualification_help'))
            ->columns([
                TextColumn::make('subject')->label(__('staff.subject'))
                    ->state(fn (array $record) => $record['subject']['code'].' · '.Locales::label($record['subject']['labels'])),
                TextColumn::make('node')->label(__('staff.node'))->state(fn (array $record) => $this->nodeName($record['node_id'])),
                TextColumn::make('valid_from')->label(__('staff.qualified_from'))->formatStateUsing(fn ($state) => Formats::date($state)),
                TextColumn::make('valid_to')->label(__('staff.qualified_until'))->formatStateUsing(fn ($state) => Formats::date($state)),
            ])->filters([Filter::make('ended')->label(__('staff.include_ended'))->toggle()])
            ->headerActions([
                Action::make('add')->label(__('staff.add_qualification'))->visible(fn () => $this->qualifiable())
                    ->schema([
                        Select::make('subject_id')->label(__('staff.subject'))->required()->searchable()->options(fn () => $this->subjectOptions()),
                        Select::make('node_id')->label(__('staff.node'))->required()->searchable()->helperText(__('staff.node_help'))
                            ->options(fn () => $this->nodeOptions()),
                        DatePicker::make('valid_from')->label(__('staff.qualified_from'))->helperText(__('staff.qualified_from_help')),
                    ])
                    ->action(fn (array $data) => SchoolScreen::changeFor('staff.qualification.manage', 'staff', $this->staff,
                        fn () => SchoolScreen::call('staff.qualification.manage', QualificationController::class, 'store', $this->sent($data), ['staff' => $this->staff]))),
            ])->recordActions([ActionGroup::make([
                Action::make('end')->label(__('staff.end_qualification'))->modalDescription(__('staff.end_qualification_help'))->color('warning')
                    ->visible(fn (array $record) => $record['valid_to'] === null && $this->changeable())
                    ->schema(fn (array $record) => [DatePicker::make('valid_to')->label(__('staff.end_on'))->required()->default(now()->toDateString())
                        ->minDate($record['valid_from'])])
                    ->action(fn (array $record, array $data) => SchoolScreen::changeFor('staff.qualification.manage', 'staff', $this->staff,
                        fn () => SchoolScreen::call('staff.qualification.manage', QualificationController::class, 'end',
                            ['valid_to' => substr((string) $data['valid_to'], 0, 10), 'version' => $record['version']], ['line' => $record['id']]))),
                Action::make('remove')->label(__('staff.remove_qualification'))->modalDescription(__('staff.remove_qualification_help'))->color('danger')
                    ->requiresConfirmation()->visible(fn () => $this->changeable())
                    ->action(fn (array $record) => SchoolScreen::changeFor('staff.qualification.manage', 'staff', $this->staff,
                        fn () => SchoolScreen::call('staff.qualification.manage', QualificationController::class, 'destroy',
                            ['version' => $record['version']], ['line' => $record['id']]))),
            ])]);
    }

    /** Whoever may change this teacher's qualifications, while the record is in use. */
    private function changeable(): bool { return $this->changeableWith('staff.qualification.manage'); }

    /** A teacher still with the school receives new qualifications (FR-STF-01, FR-STF-02). */
    private function qualifiable(): bool { return $this->changeable() && $this->teaching(); }

    /** @return array<string, string> the referential's nodes still in use: levels, cycles, tracks, specialties */
    private function nodeOptions(): array
    {
        return collect($this->nodes())->whereNull('archived_at')->mapWithKeys(fn (array $node) => [$node['id'] => $this->nodeName($node['id'])])->all();
    }

    /** As the API takes it: a day without its time, none for today. */
    private function sent(array $data): array
    {
        if (empty($data['valid_from'])) unset($data['valid_from']);
        else $data['valid_from'] = substr((string) $data['valid_from'], 0, 10);
        return $data;
    }
}
