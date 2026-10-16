<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\PaymentController;
use App\Support\Formats;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * FR-FIN-03: the payments and reversals waiting for the finance's validation, the front office's
 * cash desk's among them; never validated by their author (BR-08), unless the school allows it
 * for a payment. Each opens its enrolment's finance.
 */
final class PaymentValidations extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 8;
    protected static ?string $section = 'finance';
    protected static array $permissions = ['finance.payment.validate', 'finance.payment.read'];
    protected static ?string $module = 'student_finance';
    protected static string|array $routeMiddleware = ['module:student_finance', 'permission:finance.payment.validate,finance.payment.read'];
    private ?bool $self = null;

    public static function getNavigationLabel(): string { return __('finance.validations'); }
    public function getTitle(): string { return __('finance.validations'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (array $filters, int $page, int $recordsPerPage) => $this->apiPage(static::$permissions, PaymentController::class, 'index',
            ['status' => $filters['status']['value'] ?? 'pending_validation'], $page, $recordsPerPage))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->description(__('finance.validations_help'))
            ->emptyStateHeading(__('finance.no_validation'))
            ->recordUrl(fn (array $record) => EnrollmentFinance::getUrl(['enrollment' => $record['enrollment_id']]))
            ->columns([
                TextColumn::make('reference')->label(__('finance.reference'))
                    ->description(fn (array $record) => $record['kind'] === 'reversal' ? __('finance.reversal_of', ['reference' => $record['reverses_reference']]) : null),
                TextColumn::make('pupil')->label(__('schooling.student'))
                    ->state(fn (array $record) => $record['enrollment'] && $record['enrollment']['matricule'] ? $record['enrollment']['matricule'].' · '.$this->personName($record['enrollment']) : ''),
                TextColumn::make('program')->label(__('schooling.program'))->state(fn (array $record) => $record['enrollment']['program'] ?? ''),
                TextColumn::make('amount')->label(__('finance.amount'))
                    ->formatStateUsing(fn ($state, array $record) => ($record['kind'] === 'reversal' ? '− ' : '').$this->money($state)),
                TextColumn::make('paid_on')->label(__('finance.paid_on'))->formatStateUsing(fn ($state) => Formats::date($state))
                    // What the validator checks against: the means and its reference (a cheque's number), or a reversal's reason.
                    ->description(fn (array $record) => $record['method_code'] ? Formats::list(array_filter([__('finance.methods.'.$record['method_code']),
                        $record['external_ref'] ? Formats::isolated($record['external_ref']) : null])) : Formats::isolated((string) $record['reason'])),
                TextColumn::make('author')->label(__('finance.by'))
                    ->description(fn (array $record) => Formats::list(array_filter([Formats::dateTime($record['created_at']), $record['attachment_file_id'] ? __('finance.attachment') : null])))
                    ->formatStateUsing(fn ($state, array $record) => Formats::isolated((string) $state).($record['delegated'] ? ' ('.__('finance.delegated').')' : '')),
                TextColumn::make('status')->label(__('school.status'))->badge()->formatStateUsing(fn (string $state) => __('finance.payment_states.'.$state))
                    ->color(fn (string $state) => match ($state) { 'validated' => 'success', 'reversed', 'cancelled' => 'gray', default => 'warning' }),
            ])->filters([
                SelectFilter::make('status')->label(__('school.status'))->options(__('finance.payment_states'))->default('pending_validation'),
            ])->recordActions([ActionGroup::make([
                Action::make('validate')->label(__('finance.validate_payment'))->requiresConfirmation()
                    ->visible(fn (array $record) => $record['status'] === 'pending_validation' && $this->writable('finance.payment.validate')
                        && ((int) $record['created_by'] !== (int) auth()->id() || ($record['kind'] === 'payment' && $this->selfAllowed())))
                    ->action(fn (array $record) => SchoolScreen::change('finance.payment.validate', fn () => SchoolScreen::call('finance.payment.validate',
                        PaymentController::class, 'validatePayment', ['version' => $record['version']], ['payment' => $record['id']]))),
                // FR-FIN-02: its supporting document, read before deciding.
                Action::make('document')->label(__('finance.open_attachment'))->visible(fn (array $record) => $record['attachment_file_id'] !== null)
                    ->url(fn (array $record) => url('/api/payments/'.$record['id'].'/attachment'), shouldOpenInNewTab: true),
                Action::make('open')->label(__('finance.enrollment_finance'))->url(fn (array $record) => EnrollmentFinance::getUrl(['enrollment' => $record['enrollment_id']])),
            ])]);
    }

    private function selfAllowed(): bool
    {
        return $this->self ??= (bool) json_decode((string) DB::selectOne("SELECT school.setting_value('finance.allow_self_validation')::text AS v")->v);
    }
}
