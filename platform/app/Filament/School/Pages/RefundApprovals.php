<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\RefundController;
use App\Support\Formats;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * FR-FIN-04: the refunds and carry-overs the finance asked for, waiting for the direction, never
 * decided by the one who asked (BR-08); each opens its enrolment's finance.
 */
final class RefundApprovals extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 10;
    protected static ?string $section = 'finance';
    protected static array $permissions = ['finance.refund.approve', 'finance.refund.request', 'finance.payment.read'];
    protected static ?string $module = 'student_finance';
    protected static string|array $routeMiddleware = ['module:student_finance', 'permission:finance.refund.approve,finance.refund.request,finance.payment.read'];

    public static function getNavigationLabel(): string { return __('finance.refund_approvals'); }
    public function getTitle(): string { return __('finance.refund_approvals'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (array $filters, int $page, int $recordsPerPage) => $this->apiPage(static::$permissions, RefundController::class, 'index',
            ['status' => $filters['status']['value'] ?? 'requested'], $page, $recordsPerPage))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->description(__('finance.refund_approvals_help'))
            ->emptyStateHeading(__('finance.no_refund_approval'))
            ->recordUrl(fn (array $record) => EnrollmentFinance::getUrl(['enrollment' => $record['enrollment_id']]))
            ->columns([
                TextColumn::make('pupil')->label(__('schooling.student'))
                    ->state(fn (array $record) => $record['enrollment'] && $record['enrollment']['matricule'] ? $record['enrollment']['matricule'].' · '.$this->personName($record['enrollment']) : ''),
                TextColumn::make('program')->label(__('schooling.program'))->state(fn (array $record) => $record['enrollment']['program'] ?? ''),
                TextColumn::make('kind')->label(__('finance.kind'))->formatStateUsing(fn (string $state) => __('finance.refund_kinds.'.$state))
                    ->description(fn (array $record) => $record['target_program']),
                TextColumn::make('amount')->label(__('finance.amount'))->formatStateUsing(fn ($state) => $this->money($state)),
                TextColumn::make('reason')->label(__('schooling.history_reason'))->wrap()->formatStateUsing(fn ($state) => Formats::isolated($state)),
                TextColumn::make('author')->label(__('finance.by'))->description(fn (array $record) => Formats::dateTime($record['created_at'])),
                TextColumn::make('status')->label(__('school.status'))->badge()->formatStateUsing(fn (string $state) => __('finance.refund_states.'.$state))
                    ->color(fn (string $state) => match ($state) { 'approved', 'executed' => 'success', 'rejected' => 'danger', default => 'warning' }),
            ])->filters([
                SelectFilter::make('status')->label(__('school.status'))->options(__('finance.refund_states'))->default('requested'),
            ])->recordActions([ActionGroup::make([
                Action::make('approve')->label(__('finance.decisions.approve'))->requiresConfirmation()
                    ->visible(fn (array $record) => $this->decidable($record))
                    ->action(fn (array $record) => SchoolScreen::change('finance.refund.approve', fn () => SchoolScreen::call('finance.refund.approve',
                        RefundController::class, 'approve', ['version' => $record['version']], ['refund' => $record['id']]))),
                Action::make('reject')->label(__('finance.decisions.reject'))->color('danger')->visible(fn (array $record) => $this->decidable($record))
                    ->schema([Textarea::make('reason')->label(__('schooling.history_reason'))->required()->minLength(3)->maxLength(500)])
                    ->action(fn (array $record, array $data) => SchoolScreen::change('finance.refund.approve', fn () => SchoolScreen::call('finance.refund.approve',
                        RefundController::class, 'reject', ['version' => $record['version'], 'reason' => $data['reason']], ['refund' => $record['id']]))),
                Action::make('open')->label(__('finance.enrollment_finance'))->url(fn (array $record) => EnrollmentFinance::getUrl(['enrollment' => $record['enrollment_id']])),
            ])]);
    }

    private function decidable(array $record): bool
    {
        return $record['status'] === 'requested' && $this->writable('finance.refund.approve') && (int) $record['created_by'] !== (int) auth()->id();
    }
}
