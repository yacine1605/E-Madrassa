<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\RefundController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\Permissions;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * FR-FIN-01: what one enrolment owes and when. Its agreed price, adjustments and what is owed;
 * its schedule in force, whose sum is what is owed; a new adjustment or a new version of the
 * schedule; the direction's decision on an adjustment beyond the threshold. FR-FIN-02/03: what
 * it has paid, its payments and reversals; a payment recorded, validated by someone else,
 * withdrawn by its author while it waits, or reversed once validated. FR-FIN-04: its refunds and
 * carry-overs, asked, decided by the direction, executed; its credit refunded, carried or kept.
 */
final class EnrollmentFinance extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static bool $shouldRegisterNavigation = false;
    protected static array $permissions = ['finance.payment.read', 'finance.payment.create', 'finance.payment.create.delegated', 'finance.payment.validate',
        'finance.payment.reverse', 'finance.discount.grant', 'finance.schedule.manage', 'finance.discount.approve', 'finance.refund.request', 'finance.refund.approve'];
    protected static ?string $module = 'student_finance';
    protected static string|array $routeMiddleware = ['module:student_finance', 'permission:finance.payment.read,finance.payment.create,'
        .'finance.payment.create.delegated,finance.payment.validate,finance.payment.reverse,finance.discount.grant,finance.schedule.manage,finance.discount.approve,'
        .'finance.refund.request,finance.refund.approve'];
    protected string $view = 'filament.school.enrollment-finance';
    #[Locked] public string $enrollment = '';
    private ?array $financeRow = null;
    private ?array $enrollmentRow = null;

    /** @return list<string> the permissions that open an enrolment's finance */
    public static function readers(): array
    {
        return static::$permissions;
    }

    public function mount(): void
    {
        parent::mount();
        $this->enrollment = (string) request()->query('enrollment', '');
        $this->finance();
    }

    public function finance(): array
    {
        abort_unless(Str::isUuid($this->enrollment), 404);
        return $this->financeRow ??= SchoolScreen::call(static::$permissions, FinanceController::class, 'show', parameters: ['enrollment' => $this->enrollment])['data'];
    }

    /** The enrolment's pupil and programme, for whoever reads the enrolments. */
    private function enrollmentRecord(): ?array
    {
        if (!app(Permissions::class)->can('enrollment.enrollment.read')) return null;
        return $this->enrollmentRow ??= SchoolScreen::call('enrollment.enrollment.read', EnrollmentController::class, 'show', parameters: ['enrollment' => $this->enrollment])['data'];
    }

    public function getTitle(): string
    {
        $record = $this->enrollmentRecord();
        return $record && $record['student'] ? $this->personName($record['student']) : __('finance.enrollment_finance');
    }

    public function getSubheading(): ?string
    {
        $record = $this->enrollmentRecord();
        return Formats::list(array_filter([__('finance.enrollment_finance'), $record ? $record['terms_snapshot']['code'].' · '.Locales::label($record['terms_snapshot']['labels']) : null,
            __('schooling.enrollment_states.'.$this->finance()['status'])]));
    }

    /** What is owed, and whether the schedule in force still adds up to it. */
    public function summaryInfolist(Schema $schema): Schema
    {
        $finance = $this->finance();
        return $schema->components([
            Callout::make(__('finance.schedule_mismatch'))->warning()->description(__('finance.schedule_mismatch_help'))->visible($finance['schedule_matches'] === false),
            Section::make(__('finance.owed'))->columns(['default' => 2, 'sm' => 4])->schema([
                TextEntry::make('agreed')->label(__('finance.agreed_price'))->state($this->money($finance['agreed_price'])),
                TextEntry::make('reductions')->label(__('finance.reductions'))->state($this->money($finance['reductions'])),
                TextEntry::make('penalties')->label(__('finance.penalties'))->state($this->money($finance['penalties'])),
                TextEntry::make('due')->label(__('finance.due'))->state($this->money($finance['due']))->weight('bold'),
                TextEntry::make('paid')->label(__('finance.paid'))->state($this->money($finance['paid'])),
                TextEntry::make('balance')->label(__('finance.balance'))->state($this->money($finance['balance']))->weight('bold'),
                TextEntry::make('overdue')->label(__('finance.overdue'))->visible((float) $finance['overdue'] > 0)->color('danger')
                    ->state(fn () => $this->money($finance['overdue']).' · '.__('finance.overdue_since', ['date' => Formats::date($finance['overdue_since'])])),
                TextEntry::make('refunded')->label(__('finance.refunded'))->state($this->money($finance['refunded'])),
                // FR-FIN-04: paid beyond what is owed, waiting for its decision (refund, carry-over, kept).
                TextEntry::make('credit')->label(__('finance.credit'))->state($this->money($finance['credit']))
                    ->color((float) $finance['credit'] > 0 ? 'warning' : null)->helperText((float) $finance['credit'] > 0 ? __('finance.credit_help') : null),
                // FR-INS-02: computed from the validated payments, beside the pedagogical state.
                TextEntry::make('state')->label(__('finance.state'))->badge()->state(__('finance.states.'.$finance['state']))
                    ->color(match ($finance['state']) { 'paid' => 'success', 'partially_paid' => 'warning', default => 'gray' }),
                TextEntry::make('waiting')->label(__('finance.waiting'))->state(Formats::list(array_filter([
                    (float) $finance['payments_pending'] > 0 ? __('finance.waiting_payments', ['amount' => $this->money($finance['payments_pending'])]) : null,
                    (float) $finance['pending'] > 0 ? __('finance.waiting_adjustments', ['amount' => $this->money($finance['pending'])]) : null,
                    (float) $finance['refunds_pending'] > 0 ? __('finance.waiting_refunds', ['amount' => $this->money($finance['refunds_pending'])]) : null])) ?: __('finance.nothing_waiting')),
            ]),
        ]);
    }

    /** The adjustments, newest first, with their decision. */
    public function adjustmentsInfolist(Schema $schema): Schema
    {
        return $schema->components([Section::make(__('finance.adjustments'))->description(__('finance.adjustments_help'))->schema([RepeatableEntry::make('adjustments')->hiddenLabel()
            ->columns(['default' => 2, 'sm' => 5])->placeholder(__('finance.no_adjustment'))
            ->state(array_map(fn (array $row) => ['kind' => __('finance.kinds.'.$row['kind']), 'amount' => $this->money($row['amount']),
                'reason' => Formats::isolated($row['reason']), 'status' => __('finance.adjustment_states.'.$row['status']).($row['decision_reason'] ? ' · '.$row['decision_reason'] : ''),
                'by' => array_values(array_filter([Formats::isolated($row['author']), Formats::isolated($row['decider'])]))], $this->finance()['adjustments']))
            ->schema([TextEntry::make('kind')->label(__('finance.kind')), TextEntry::make('amount')->label(__('finance.amount')),
                TextEntry::make('reason')->label(__('schooling.history_reason')), TextEntry::make('status')->label(__('school.status')),
                $this->byEntry()])])]);
    }

    /** The payments and reversals, newest first, with their state and who recorded and validated them. */
    public function paymentsInfolist(Schema $schema): Schema
    {
        return $schema->components([Section::make(__('finance.payments'))->description(__('finance.payments_help'))->schema([RepeatableEntry::make('payments')->hiddenLabel()
            ->columns(['default' => 2, 'sm' => 7])->placeholder(__('finance.no_payment'))
            ->state(array_map(fn (array $row) => [
                'reference' => Formats::isolated($row['reference']).($row['kind'] === 'reversal' ? ' · '.__('finance.reversal_of', ['reference' => Formats::isolated($row['reverses_reference'])]) : ''),
                'amount' => ($row['kind'] === 'reversal' ? '− ' : '').$this->money($row['amount']),
                'paid' => Formats::list(array_filter([Formats::date($row['paid_on']), $row['method_code'] ? __('finance.methods.'.$row['method_code']) : null,
                    $row['external_ref'] ? Formats::isolated($row['external_ref']) : null])),
                'status' => Formats::list(array_filter([__('finance.payment_states.'.$row['status']), $row['overpayment'] ? __('finance.overpayment') : null,
                    $row['self_validated'] ? __('finance.self_validated') : null, $row['reason'] ? Formats::isolated($row['reason']) : null,
                    $row['cancel_reason'] ? Formats::isolated($row['cancel_reason']) : null])),
                'by' => array_values(array_filter([Formats::isolated($row['author']).($row['delegated'] ? ' ('.__('finance.delegated').')' : ''), Formats::isolated($row['validator'])])),
                // FR-FIN-05: a validated payment's receipt, issued by its first download.
                'receipt' => $row['kind'] === 'payment' && $row['validated_at'] ? url('/api/payments/'.$row['id'].'/receipt') : null,
                // FR-FIN-02: its supporting document, read with the payment.
                'document' => $row['attachment_file_id'] ? url('/api/payments/'.$row['id'].'/attachment') : null], $this->finance()['payments']))
            ->schema([TextEntry::make('reference')->label(__('finance.reference')), TextEntry::make('amount')->label(__('finance.amount')),
                TextEntry::make('paid')->label(__('finance.paid_on')), TextEntry::make('status')->label(__('school.status')),
                $this->byEntry(), $this->receiptEntry(),
                TextEntry::make('document')->label(__('finance.attachment'))->formatStateUsing(fn () => __('finance.open_attachment'))
                    ->url(fn (?string $state) => $state, shouldOpenInNewTab: true)->color('primary')])])]);
    }

    /** The refunds and carry-overs, newest first, with their decision and execution. */
    public function refundsInfolist(Schema $schema): Schema
    {
        return $schema->components([Section::make(__('finance.refunds'))->description(__('finance.refunds_help'))->schema([RepeatableEntry::make('refunds')->hiddenLabel()
            ->columns(['default' => 2, 'sm' => 6])->placeholder(__('finance.no_refund'))
            ->state(array_map(fn (array $row) => [
                'kind' => __('finance.refund_kinds.'.$row['kind']).($row['target_program'] ? ' · '.$row['target_program'] : ''),
                'amount' => $this->money($row['amount']),
                'reason' => Formats::isolated($row['reason']),
                'status' => array_values(array_filter([__('finance.refund_states.'.$row['status']), Formats::isolated($row['reference']),
                    $row['method_code'] ? __('finance.methods.'.$row['method_code']) : null, $row['paid_on'] ? Formats::date($row['paid_on']) : null,
                    Formats::isolated($row['decision_reason'])])),
                'by' => array_values(array_filter([Formats::isolated($row['author']), Formats::isolated($row['decider']), Formats::isolated($row['executor'])])),
                'receipt' => $row['kind'] === 'refund' && $row['status'] === 'executed' ? url('/api/refunds/'.$row['id'].'/receipt') : null], $this->finance()['refunds']))
            ->schema([TextEntry::make('kind')->label(__('finance.kind')), TextEntry::make('amount')->label(__('finance.amount')),
                TextEntry::make('reason')->label(__('schooling.history_reason')), TextEntry::make('status')->label(__('school.status'))->listWithLineBreaks(),
                $this->byEntry(), $this->receiptEntry()])])]);
    }

    /**
     * Who recorded, decided and executed it, one per line: names typed in Latin letters stay whole
     * in an Arabic page instead of wrapping into one another around a comma.
     */
    private function byEntry(): TextEntry
    {
        return TextEntry::make('by')->label(__('finance.by'))->listWithLineBreaks();
    }

    /** The receipt's link of a payment or a refund: the first opening issues it, the next ones print a duplicate. */
    private function receiptEntry(): TextEntry
    {
        return TextEntry::make('receipt')->label(__('finance.receipt'))->formatStateUsing(fn () => __('finance.open_receipt'))
            ->url(fn (?string $state) => $state, shouldOpenInNewTab: true)->color('primary');
    }

    /** The schedule in force, line by line. */
    public function table(Table $table): Table
    {
        $schedule = $this->finance()['schedule'];
        return $table->heading(__('finance.schedule'))->paginated(false)
            ->description($schedule ? __('finance.schedule_version', ['version' => Formats::number($schedule['version']), 'total' => $this->money($schedule['total'])]) : null)
            ->records(fn () => collect($this->finance()['schedule']['installments'] ?? [])->keyBy('seq')->all())
            ->emptyStateHeading(__('finance.no_schedule'))->emptyStateDescription(__('finance.no_schedule_help'))
            ->columns([
                TextColumn::make('seq')->label(__('finance.seq'))->formatStateUsing(fn ($state) => Formats::number((int) $state)),
                TextColumn::make('due_date')->label(__('finance.due_date'))->formatStateUsing(fn ($state) => Formats::date($state)),
                TextColumn::make('amount')->label(__('finance.amount'))->formatStateUsing(fn ($state) => $this->money($state)),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('enrollments')->label(__('schooling.enrollments'))->color('gray')->visible(fn () => app(Permissions::class)->can('enrollment.enrollment.read'))
                ->url(fn () => Enrollments::getUrl()),
            // FR-FIN-02: a payment, waiting for its validation by the finance; the form's key makes it once.
            Action::make('pay')->label(__('finance.record_payment'))->visible(fn () => $this->finance()['status'] !== 'cancelled'
                    && ($this->writable('finance.payment.create') || $this->writable('finance.payment.create.delegated')))
                ->modalDescription(fn () => __('finance.record_payment_help', ['balance' => $this->money($this->finance()['balance'])]))
                ->fillForm(fn () => ['paid_on' => now()->toDateString(), 'overpayment' => false, 'idempotency_key' => (string) Str::uuid()])
                ->schema(fn () => [$this->amountField('amount'), DatePicker::make('paid_on')->label(__('finance.paid_on'))->required()->maxDate(now()),
                    Select::make('method_code')->label(__('finance.method'))->required()->options($this->methods()),
                    TextInput::make('external_ref')->label(__('finance.external_ref'))->maxLength(100)->helperText(__('finance.external_ref_help')),
                    Toggle::make('overpayment')->label(__('finance.overpayment'))->helperText(__('finance.overpayment_help')),
                    $this->attachmentField()->helperText(__('finance.attachment_help')), Hidden::make('idempotency_key')])
                ->action(fn (array $data) => $this->changed(SchoolScreen::change(null, fn () => SchoolScreen::call(['finance.payment.create', 'finance.payment.create.delegated'],
                    PaymentController::class, 'store', array_filter(['amount' => $this->sentAmount($data['amount']), 'paid_on' => substr((string) $data['paid_on'], 0, 10),
                        'method_code' => $data['method_code'], 'external_ref' => $data['external_ref'] ?: null, 'overpayment' => (bool) $data['overpayment'],
                        'idempotency_key' => $data['idempotency_key'], 'attachment' => $data['attachment'] ?? null], fn ($value) => $value !== null),
                    ['enrollment' => $this->enrollment])))),
            // FR-FIN-02: its author adds or replaces the supporting document of a waiting payment.
            Action::make('attach_document')->label(__('finance.attach_document'))->color('gray')->visible(fn () => $this->ownWaiting() !== [])
                ->schema(fn () => [Select::make('payment')->label(__('finance.payment'))->required()->options($this->ownWaiting()), $this->attachmentField()->required()])
                ->action(fn (array $data) => $this->onPayment($data['payment'], ['finance.payment.create', 'finance.payment.create.delegated'], 'attachment',
                    ['attachment' => $data['attachment']])),
            // FR-FIN-03 AC3: validated by someone else than its author, unless the school allows it.
            Action::make('validate_payment')->label(__('finance.validate_payment'))->visible(fn () => $this->writable('finance.payment.validate') && $this->validatable() !== [])
                ->schema(fn () => [Select::make('payment')->label(__('finance.payment'))->required()->options($this->validatable())])
                ->action(fn (array $data) => $this->onPayment($data['payment'], 'finance.payment.validate', 'validatePayment', [])),
            // Its author withdraws a waiting payment, saying why.
            Action::make('withdraw_payment')->label(__('finance.withdraw_payment'))->color('gray')->visible(fn () => $this->ownWaiting() !== [])
                ->schema(fn () => [Select::make('payment')->label(__('finance.payment'))->required()->options($this->ownWaiting()),
                    Textarea::make('reason')->label(__('schooling.history_reason'))->required()->minLength(3)->maxLength(500)])
                ->action(fn (array $data) => $this->onPayment($data['payment'], ['finance.payment.create', 'finance.payment.create.delegated'], 'cancel', ['reason' => $data['reason']])),
            // AC2: a linked reversal, with its reason, validated in its turn; the payment stays.
            Action::make('reverse_payment')->label(__('finance.reverse_payment'))->color('danger')->visible(fn () => $this->writable('finance.payment.reverse') && $this->reversible() !== [])
                ->modalDescription(__('finance.reverse_payment_help'))
                ->fillForm(fn () => ['idempotency_key' => (string) Str::uuid()])
                ->schema(fn () => [Select::make('payment')->label(__('finance.payment'))->required()->options($this->reversible()),
                    Textarea::make('reason')->label(__('schooling.history_reason'))->required()->minLength(3)->maxLength(500), Hidden::make('idempotency_key')])
                ->action(fn (array $data) => $this->changed(SchoolScreen::change('finance.payment.reverse', fn () => SchoolScreen::call('finance.payment.reverse',
                    PaymentController::class, 'reverse', ['reason' => $data['reason'], 'idempotency_key' => $data['idempotency_key']], ['payment' => $data['payment']])))),
            // FR-FIN-04: a refund or a carry-over to the pupil's other enrolment, asked with its reason.
            Action::make('refund')->label(__('finance.ask_refund'))->color('gray')->visible(fn () => $this->writable('finance.refund.request') && (float) $this->finance()['paid'] > 0)
                ->modalDescription(fn () => __('finance.ask_refund_help', ['paid' => $this->money($this->finance()['paid']), 'credit' => $this->money($this->finance()['credit'])]))
                ->fillForm(fn () => ['kind' => 'refund', 'amount' => (float) $this->finance()['credit'] > 0 ? number_format((float) $this->finance()['credit'], 2, ',', ' ') : null,
                    'idempotency_key' => (string) Str::uuid()])
                ->schema(fn () => [Select::make('kind')->label(__('finance.kind'))->required()->live()
                        ->options(collect(__('finance.refund_kinds'))->only($this->finance()['siblings'] === [] ? ['refund'] : ['refund', 'carry_over'])->all()),
                    Select::make('target_enrollment_id')->label(__('finance.target'))->visible(fn (Get $get) => $get('kind') === 'carry_over')->required()
                        ->options(collect($this->finance()['siblings'])->mapWithKeys(fn (array $other) => [$other['id'] => $other['program'].' · '.__('schooling.enrollment_states.'.$other['status'])])->all()),
                    $this->amountField('amount'), Textarea::make('reason')->label(__('schooling.history_reason'))->required()->minLength(3)->maxLength(500),
                    Hidden::make('idempotency_key')])
                ->action(fn (array $data) => $this->changed(SchoolScreen::change('finance.refund.request', fn () => SchoolScreen::call('finance.refund.request',
                    RefundController::class, 'store', ['kind' => $data['kind'], 'target_enrollment_id' => $data['target_enrollment_id'] ?? null,
                        'amount' => $this->sentAmount($data['amount']), 'reason' => $data['reason'], 'idempotency_key' => $data['idempotency_key']], ['enrollment' => $this->enrollment])))),
            // The direction approves or rejects it, never the one who asked (BR-08).
            Action::make('decide_refund')->label(__('finance.decide_refund'))->visible(fn () => $this->writable('finance.refund.approve') && $this->refundOptions('requested', true) !== [])
                ->schema(fn () => [Select::make('refund')->label(__('finance.refund'))->required()->options($this->refundOptions('requested', true)),
                    Select::make('decision')->label(__('finance.decision'))->required()->live()->options(__('finance.decisions')),
                    Textarea::make('reason')->label(__('schooling.history_reason'))->minLength(3)->maxLength(500)->required(fn (Get $get) => $get('decision') === 'reject')])
                ->action(fn (array $data) => $this->onRefund($data['refund'], 'finance.refund.approve', $data['decision'], ['reason' => $data['reason'] ?: null])),
            // AC2: executed once approved: a refund with its means and date; a carry-over pays the other enrolment.
            Action::make('execute_refund')->label(__('finance.execute_refund'))->visible(fn () => $this->writable('finance.refund.request') && $this->refundOptions('approved') !== [])
                ->modalDescription(__('finance.execute_refund_help'))
                ->fillForm(fn () => ['paid_on' => now()->toDateString()])
                ->schema(fn () => [Select::make('refund')->label(__('finance.refund'))->required()->live()->options($this->refundOptions('approved')),
                    Select::make('method_code')->label(__('finance.method'))->options($this->methods())->visible(fn (Get $get) => $this->refundKind($get('refund')) !== 'carry_over')
                        ->required(),
                    DatePicker::make('paid_on')->label(__('finance.paid_on'))->maxDate(now())->visible(fn (Get $get) => $this->refundKind($get('refund')) !== 'carry_over')->required(),
                    TextInput::make('external_ref')->label(__('finance.external_ref'))->maxLength(100)->visible(fn (Get $get) => $this->refundKind($get('refund')) !== 'carry_over')])
                ->action(fn (array $data) => $this->onRefund($data['refund'], 'finance.refund.request', 'execute', ['method_code' => $data['method_code'] ?? null,
                    'paid_on' => isset($data['paid_on']) ? substr((string) $data['paid_on'], 0, 10) : null, 'external_ref' => ($data['external_ref'] ?? null) ?: null])),
            // AC2: an adjustment with its reason; beyond the threshold, the direction decides.
            Action::make('adjust')->label(__('finance.add_adjustment'))->visible(fn () => $this->writable('finance.discount.grant'))
                ->modalDescription(__('finance.add_adjustment_help'))
                ->schema([Select::make('kind')->label(__('finance.kind'))->required()->options(__('finance.kinds')),
                    $this->amountField('amount'), Textarea::make('reason')->label(__('schooling.history_reason'))->required()->minLength(3)->maxLength(500),
                    Hidden::make('idempotency_key')->default(fn () => (string) Str::uuid())])
                ->action(fn (array $data) => $this->changed(SchoolScreen::change('finance.discount.grant',
                    fn () => SchoolScreen::call('finance.discount.grant', FinanceController::class, 'adjust', ['amount' => $this->sentAmount($data['amount'])] + $data,
                        ['enrollment' => $this->enrollment])))),
            // AC1: a new version, whose lines add up to what is owed; the first from the programme's terms.
            Action::make('schedule')->label(__('finance.set_schedule'))->visible(fn () => $this->writable('finance.schedule.manage') && $this->finance()['status'] !== 'cancelled')
                ->modalDescription(fn () => __('finance.set_schedule_help', ['due' => $this->money($this->finance()['due'])]))
                ->fillForm(fn () => ['installments' => $this->finance()['schedule']['installments']
                    ?? SchoolScreen::call('finance.schedule.manage', FinanceController::class, 'proposal', parameters: ['enrollment' => $this->enrollment])['data']['installments'],
                    'idempotency_key' => (string) Str::uuid()])
                ->schema(fn () => [Repeater::make('installments')->label(__('finance.installments'))->required()->minItems(1)->maxItems(120)->columns(2)
                        ->schema([DatePicker::make('due_date')->label(__('finance.due_date'))->required(), $this->amountField('amount')]),
                    Textarea::make('reason')->label(__('schooling.history_reason'))->minLength(3)->maxLength(500)
                        ->required(fn () => $this->finance()['schedule'] !== null)->helperText(__('finance.schedule_reason_help')),
                    Hidden::make('idempotency_key')])
                ->action(fn (array $data) => $this->changed(SchoolScreen::change('finance.schedule.manage', fn () => SchoolScreen::call('finance.schedule.manage',
                    FinanceController::class, 'schedule', ['installments' => array_values(array_map(fn (array $line) => ['due_date' => substr((string) $line['due_date'], 0, 10),
                        'amount' => $this->sentAmount($line['amount'])], $data['installments'])), 'reason' => $data['reason'] ?: null, 'idempotency_key' => $data['idempotency_key']],
                    ['enrollment' => $this->enrollment])))),
            // The direction's decision on an adjustment beyond the threshold (BR-08: not its author's).
            Action::make('decide')->label(__('finance.decide'))->visible(fn () => $this->writable('finance.discount.approve') && $this->pending() !== [])
                ->schema(fn () => [Select::make('adjustment')->label(__('finance.adjustment'))->required()->options($this->pending()),
                    Select::make('decision')->label(__('finance.decision'))->required()->live()->options(__('finance.decisions')),
                    Textarea::make('reason')->label(__('schooling.history_reason'))->minLength(3)->maxLength(500)
                        ->required(fn (Get $get) => $get('decision') === 'reject')])
                ->action(function (array $data) {
                    $row = collect($this->finance()['adjustments'])->firstWhere('id', $data['adjustment']);
                    abort_unless($row, 404);
                    $this->changed(SchoolScreen::change('finance.discount.approve', fn () => SchoolScreen::call('finance.discount.approve', FinanceController::class,
                        $data['decision'], ['version' => $row['version'], 'reason' => $data['reason'] ?: null], ['adjustment' => $row['id']])));
                }),
        ];
    }

    /** @return array<string, string> the adjustments waiting for a decision */
    private function pending(): array
    {
        return collect($this->finance()['adjustments'])->where('status', 'pending')
            ->mapWithKeys(fn (array $row) => [$row['id'] => __('finance.kinds.'.$row['kind']).' · '.$this->money($row['amount']).' · '.Str::limit($row['reason'], 40)])->all();
    }

    /** @return array<string, string> the waiting payments the reader may validate: someone else's, or their own payment if the school allows it */
    private function validatable(): array
    {
        $self = (bool) json_decode((string) DB::selectOne("SELECT school.setting_value('finance.allow_self_validation')::text AS v")->v);
        return $this->paymentOptions(fn (array $row) => $row['status'] === 'pending_validation'
            && ((int) $row['created_by'] !== (int) auth()->id() || ($self && $row['kind'] === 'payment')));
    }

    /** @return array<string, string> the reader's own waiting payments */
    private function ownWaiting(): array
    {
        return $this->paymentOptions(fn (array $row) => $row['status'] === 'pending_validation' && (int) $row['created_by'] === (int) auth()->id());
    }

    /** @return array<string, string> the validated payments without a reversal under way */
    private function reversible(): array
    {
        return $this->paymentOptions(fn (array $row) => $row['kind'] === 'payment' && $row['status'] === 'validated' && $row['reversal_id'] === null);
    }

    private function paymentOptions(callable $filter): array
    {
        return collect($this->finance()['payments'])->filter($filter)->mapWithKeys(fn (array $row) => [$row['id'] => Formats::list([Formats::isolated($row['reference']),
            $row['kind'] === 'reversal' ? __('finance.reversal_of', ['reference' => Formats::isolated($row['reverses_reference'])]) : $this->money($row['amount']),
            Formats::date($row['paid_on'])])])->all();
    }

    /** @return array<string, string> the refunds in this state, those of others only when deciding (BR-08) */
    private function refundOptions(string $status, bool $others = false): array
    {
        return collect($this->finance()['refunds'])->where('status', $status)->filter(fn (array $row) => !$others || (int) $row['created_by'] !== (int) auth()->id())
            ->mapWithKeys(fn (array $row) => [$row['id'] => Formats::list([__('finance.refund_kinds.'.$row['kind']), $this->money($row['amount']), Str::limit($row['reason'], 40)])])->all();
    }

    private function refundKind(?string $id): ?string
    {
        return $id ? collect($this->finance()['refunds'])->firstWhere('id', $id)['kind'] ?? null : null;
    }

    private function onRefund(string $id, string $permission, string $method, array $data): array
    {
        $row = collect($this->finance()['refunds'])->firstWhere('id', $id);
        abort_unless($row, 404);
        return $this->changed(SchoolScreen::change($permission, fn () => SchoolScreen::call($permission, RefundController::class, $method,
            ['version' => $row['version'], ...$data], ['refund' => $row['id']])));
    }

    private function onPayment(string $id, string|array $permission, string $method, array $data): array
    {
        $row = collect($this->finance()['payments'])->firstWhere('id', $id);
        abort_unless($row, 404);
        return $this->changed(SchoolScreen::change(null, fn () => SchoolScreen::call($permission, PaymentController::class, $method,
            ['version' => $row['version'], ...$data], ['payment' => $row['id']])));
    }

    /** A payment's document: a PDF or an image of it, as the school accepts its files (FR-FIC-01). No preview, which the CSP refuses. */
    private function attachmentField(): FileUpload
    {
        return FileUpload::make('attachment')->label(__('finance.attachment'))->storeFiles(false)->previewable(false)
            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])->maxSize(10240);
    }

    private function changed(array $result): array
    {
        $this->financeRow = null;
        $this->cacheSchema('summaryInfolist', null);
        $this->cacheSchema('adjustmentsInfolist', null);
        $this->cacheSchema('paymentsInfolist', null);
        $this->cacheSchema('refundsInfolist', null);
        return $result;
    }
}
