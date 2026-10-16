<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\FinanceController;
use App\Support\Formats;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * FR-FIN-01: the adjustments beyond the school's threshold, waiting for the direction (BR-08:
 * never decided by their author); each opens its enrolment's finance.
 */
final class AdjustmentApprovals extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 9;
    protected static ?string $section = 'finance';
    protected static array $permissions = ['finance.discount.approve', 'finance.payment.read'];
    protected static ?string $module = 'student_finance';
    protected static string|array $routeMiddleware = ['module:student_finance', 'permission:finance.discount.approve,finance.payment.read'];

    public static function getNavigationLabel(): string { return __('finance.approvals'); }
    public function getTitle(): string { return __('finance.approvals'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (array $filters, int $page, int $recordsPerPage) => $this->apiPage(static::$permissions, FinanceController::class, 'adjustments',
            ['status' => $filters['status']['value'] ?? 'pending'], $page, $recordsPerPage))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->description(__('finance.approvals_help'))
            ->emptyStateHeading(__('finance.no_approval'))
            ->recordUrl(fn (array $record) => EnrollmentFinance::getUrl(['enrollment' => $record['enrollment_id']]))
            ->columns([
                TextColumn::make('pupil')->label(__('schooling.student'))
                    ->state(fn (array $record) => $record['enrollment'] && $record['enrollment']['matricule'] ? $record['enrollment']['matricule'].' · '.$this->personName($record['enrollment']) : ''),
                TextColumn::make('program')->label(__('schooling.program'))->state(fn (array $record) => $record['enrollment']['program'] ?? ''),
                TextColumn::make('kind')->label(__('finance.kind'))->formatStateUsing(fn (string $state) => __('finance.kinds.'.$state)),
                TextColumn::make('amount')->label(__('finance.amount'))->formatStateUsing(fn ($state) => $this->money($state)),
                TextColumn::make('reason')->label(__('schooling.history_reason'))->wrap()->formatStateUsing(fn ($state) => Formats::isolated($state)),
                TextColumn::make('author')->label(__('finance.by'))->description(fn (array $record) => Formats::dateTime($record['created_at'])),
                TextColumn::make('status')->label(__('school.status'))->badge()->formatStateUsing(fn (string $state) => __('finance.adjustment_states.'.$state))
                    ->color(fn (string $state) => match ($state) { 'approved' => 'success', 'rejected' => 'danger', default => 'warning' }),
            ])->filters([
                SelectFilter::make('status')->label(__('school.status'))->options(__('finance.adjustment_states'))->default('pending'),
            ])->recordActions([ActionGroup::make([
                Action::make('approve')->label(__('finance.decisions.approve'))->requiresConfirmation()
                    ->visible(fn (array $record) => $record['status'] === 'pending' && $this->writable('finance.discount.approve'))
                    ->action(fn (array $record) => SchoolScreen::change('finance.discount.approve', fn () => SchoolScreen::call('finance.discount.approve',
                        FinanceController::class, 'approve', ['version' => $record['version']], ['adjustment' => $record['id']]))),
                Action::make('reject')->label(__('finance.decisions.reject'))->color('danger')
                    ->visible(fn (array $record) => $record['status'] === 'pending' && $this->writable('finance.discount.approve'))
                    ->schema([Textarea::make('reason')->label(__('schooling.history_reason'))->required()->minLength(3)->maxLength(500)])
                    ->action(fn (array $record, array $data) => SchoolScreen::change('finance.discount.approve', fn () => SchoolScreen::call('finance.discount.approve',
                        FinanceController::class, 'reject', ['version' => $record['version'], 'reason' => $data['reason']], ['adjustment' => $record['id']]))),
                Action::make('open')->label(__('finance.enrollment_finance'))->url(fn (array $record) => EnrollmentFinance::getUrl(['enrollment' => $record['enrollment_id']])),
            ])]);
    }
}
