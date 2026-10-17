<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\PayrollPayoutController;
use App\Http\Controllers\PayrollRunController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * FR-REM-02, FR-REM-03: one member's pay for a month, opened from the month's list (`?run=`): its
 * detail line by line with the copy of the level, quantity and rate applied; while a draft, the
 * units done entered by hand (lot 3), the one-off bonuses and adjustments with their reason, a new
 * computation; its validation by someone else than whoever computed it (BR-08), which freezes it.
 * Validated, it is paid at once or in parts, each payout its `salaries` expense (FR-REM-04); a payout
 * is taken back by its reversal, decided by someone else than whoever asked for it.
 */
final class PayrollRun extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $section = 'finance';
    protected static array $permissions = ['payroll.amount.read', 'payroll.run.generate', 'payroll.run.validate', 'payroll.payout.record'];
    protected static ?string $module = 'payroll';
    protected static string|array $routeMiddleware = ['module:payroll', 'permission:payroll.amount.read,payroll.run.generate,payroll.run.validate,payroll.payout.record'];
    protected string $view = 'filament.school.payroll-run';
    #[Locked] public string $run = '';
    private ?array $runRow = null;

    public function mount(): void
    {
        parent::mount();
        $this->run = (string) request()->query('run', '');
        $this->pay();
    }

    /** The pay with its lines, units and adjustments. */
    public function pay(): array
    {
        abort_unless(Str::isUuid($this->run), 404);
        return $this->runRow ??= SchoolScreen::call(static::$permissions, PayrollRunController::class, 'show', parameters: ['run' => $this->run])['data'];
    }

    public function getTitle(): string
    {
        $pay = $this->pay();
        return Formats::list(array_filter([$this->personName($pay) ?: null, $pay['staff_number']]));
    }

    public function getSubheading(): ?string
    {
        $pay = $this->pay();
        return Formats::list([__('payroll.runs'), $this->month($pay['period']), __('payroll.kinds.'.$pay['kind']), __('payroll.run_states.'.$pay['state'])]);
    }

    /** The parts and the net, the days paid, who computed and validated it. */
    public function summaryInfolist(Schema $schema): Schema
    {
        $pay = $this->pay();
        return $schema->components([
            Callout::make(__('payroll.rate_missing'))->danger()->description(__('payroll.rate_missing_help'))->visible($pay['missing_rates'] > 0),
            Section::make(__('payroll.summary'))->columns(['default' => 2, 'sm' => 4])->schema([
                TextEntry::make('fixed')->label(__('payroll.fixed_part'))->state($this->money($pay['fixed_amount']))
                    ->helperText((int) $pay['days_paid'] !== (int) $pay['days_in_month']
                        ? __('payroll.days_paid', ['days' => Formats::number((int) $pay['days_paid']), 'total' => Formats::number((int) $pay['days_in_month'])]) : null),
                TextEntry::make('variable')->label(__('payroll.variable_part'))->state($this->money($pay['variable_amount'])),
                TextEntry::make('bonus')->label(__('payroll.bonus_total'))->state($this->money($pay['bonus_amount'])),
                TextEntry::make('adjustment')->label(__('payroll.adjustment_total'))->state($this->money($pay['adjustment_amount'])),
                TextEntry::make('net')->label(__('payroll.net'))->state($this->money($pay['net_amount']))->weight('bold'),
                TextEntry::make('status')->label(__('school.status'))->badge()->state(__('payroll.run_states.'.$pay['state']))
                    ->color(Payroll::color($pay['state'])),
                TextEntry::make('paid')->label(__('payroll.paid'))->state($this->money($pay['paid']))->visible($pay['status'] !== 'draft'),
                TextEntry::make('left_to_pay')->label(__('payroll.left_to_pay'))->state($this->money($pay['left_to_pay']))->visible($pay['status'] !== 'draft'),
                TextEntry::make('computed')->label(__('payroll.by'))->columnSpan(2)->state(Formats::list(array_filter([
                    __('payroll.computed_by', ['name' => Formats::isolated((string) $pay['computer']), 'date' => Formats::dateTime($pay['computed_at'])]),
                    $pay['validated_at'] ? __('payroll.validated_by', ['name' => Formats::isolated((string) $pay['validator']), 'date' => Formats::dateTime($pay['validated_at'])]) : null]))),
            ]),
        ]);
    }

    /** FR-REM-04: the payouts and their reversals, the latest first, with who recorded and decided them. */
    public function payoutsInfolist(Schema $schema): Schema
    {
        return $schema->components([Section::make(__('payroll.payouts'))->visible($this->pay()['status'] !== 'draft')->schema([RepeatableEntry::make('payouts')->hiddenLabel()
            ->columns(['default' => 2, 'sm' => 4])->placeholder(__('payroll.no_payout'))
            ->state(array_map(fn (array $row) => ['what' => Formats::list([__('payroll.payout_kinds.'.$row['kind']), __('payroll.payout_statuses.'.$row['status'])]),
                'amount' => ($row['kind'] === 'reversal' ? '− ' : '').$this->money($row['amount']),
                'when' => Formats::list(array_filter([Formats::date($row['paid_on']), __('finance.methods.'.$row['method_code']),
                    $row['external_ref'] ? Formats::isolated($row['external_ref']) : null])),
                'by' => Formats::list(array_filter([Formats::isolated((string) $row['author']), $row['reason'] ? Formats::isolated($row['reason']) : null,
                    $row['decider'] ? Formats::isolated($row['decider']) : null, $row['decision_reason'] ? Formats::isolated($row['decision_reason']) : null]))],
                $this->pay()['payouts']))
            ->schema([TextEntry::make('what')->label(__('payroll.kind')), TextEntry::make('amount')->label(__('payroll.amount')),
                TextEntry::make('when')->label(__('payroll.paid_on')), TextEntry::make('by')->label(__('payroll.by'))])])]);
    }

    /** FR-REM-03: the one-off bonuses and adjustments, each with its reason and author, and the pay it corrects. */
    public function adjustmentsInfolist(Schema $schema): Schema
    {
        return $schema->components([Section::make(__('payroll.adjustments'))->schema([RepeatableEntry::make('adjustments')->hiddenLabel()
            ->columns(['default' => 2, 'sm' => 4])->placeholder(__('payroll.no_adjustment'))
            ->state(array_map(fn (array $row) => ['kind' => __('payroll.adjustment_kinds.'.$row['kind']), 'amount' => $this->money($row['amount']),
                'reason' => Formats::list(array_filter([Formats::isolated($row['reason']), $row['corrects_period'] ? __('payroll.correcting', ['month' => $this->month($row['corrects_period'])]) : null])),
                'by' => Formats::list([Formats::isolated((string) $row['author']), Formats::dateTime($row['created_at'])])], $this->pay()['adjustments']))
            ->schema([TextEntry::make('kind')->label(__('payroll.kind')), TextEntry::make('amount')->label(__('payroll.amount')),
                TextEntry::make('reason')->label(__('payroll.reason')), TextEntry::make('by')->label(__('payroll.by'))])])]);
    }

    /** The detail line by line (FR-REM-03), as computed and, once validated, frozen. */
    public function table(Table $table): Table
    {
        return $table->heading(__('payroll.detail'))->description(__('payroll.detail_help'))->paginated(false)
            ->records(fn () => collect($this->pay()['lines'])->keyBy('id')->all())
            ->emptyStateHeading(__('payroll.no_line'))
            ->columns([
                TextColumn::make('kind')->label(__('payroll.line'))->formatStateUsing(fn (string $state) => __('payroll.line_kinds.'.$state))
                    ->description(fn (array $record) => match (true) {
                        $record['label'] !== null => Formats::isolated($record['label']),
                        $record['included'] => __('payroll.included'),
                        default => null,
                    }),
                TextColumn::make('node_code')->label(__('payroll.level'))
                    ->state(fn (array $record) => $record['kind'] !== 'unit' ? null : ($record['node_code'] !== null
                        ? $record['node_code'].' · '.Locales::label($record['node_labels']) : __('payroll.default_rate'))),
                TextColumn::make('worked_on')->label(__('payroll.worked_on'))->formatStateUsing(fn ($state) => Formats::date($state)),
                TextColumn::make('quantity')->label(__('payroll.quantity'))->formatStateUsing(fn ($state, array $record) => $record['kind'] === 'fixed'
                    ? __('payroll.days_paid', ['days' => Formats::number((int) $state), 'total' => Formats::number((int) $this->pay()['days_in_month'])])
                    : $this->quantity($state)),
                TextColumn::make('unit_amount')->label(__('payroll.rate'))->formatStateUsing(fn ($state) => $this->money($state))
                    ->placeholder(fn (array $record) => $record['kind'] === 'unit' ? __('payroll.rate_missing') : null),
                TextColumn::make('amount')->label(__('payroll.amount'))->formatStateUsing(fn ($state) => $this->money($state))
                    ->placeholder(__('payroll.rate_missing'))->color(fn (array $record) => $record['amount'] === null ? 'danger' : null),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runs')->label(__('payroll.runs'))->color('gray')->url(fn () => Payroll::getUrl(['period' => $this->pay()['period']])),
            Action::make('rules')->label(__('payroll.rules'))->color('gray')->visible(fn () => StaffPayRules::canAccess())
                ->url(fn () => StaffPayRules::getUrl(['staff' => $this->pay()['staff_id']])),
            // Lot 3: the units done, entered by hand, all at once; the draft computed again with them.
            Action::make('units')->label(__('payroll.enter_units'))->modalDescription(__('payroll.enter_units_help'))
                ->visible(fn () => $this->draft() && $this->pay()['kind'] !== 'fixed_monthly')
                ->fillForm(fn () => ['units' => array_map(fn (array $unit) => ['node_id' => $unit['node_id'], 'worked_on' => $unit['worked_on'],
                    'quantity' => $unit['quantity']], $this->pay()['units']), 'version' => $this->pay()['version']])
                ->schema(fn () => [Repeater::make('units')->label(__('payroll.unit_rows'))->addActionLabel(__('payroll.add_unit'))->columns(3)->maxItems(500)->defaultItems(0)
                    ->schema([Select::make('node_id')->label(__('payroll.level'))->placeholder(__('payroll.no_level'))->options(fn () => $this->nodeOptions())->searchable(),
                        DatePicker::make('worked_on')->label(__('payroll.worked_on'))->required()->minDate($this->pay()['period'])->maxDate($this->lastDay()),
                        TextInput::make('quantity')->label(Str::ucfirst(__('payroll.units.'.$this->basis())))->required()
                            ->regex($this->basis() === 'per_session' ? '/^\s*[1-9][0-9]{0,2}\s*$/' : '/^\s*[0-9]{1,3}([.,][0-9]{1,2})?\s*$/')]),
                    Hidden::make('version')])
                ->action(fn (array $data) => $this->change('units', ['version' => (int) $data['version'], 'units' => array_values(array_map(fn (array $unit) => [
                    'node_id' => ($unit['node_id'] ?? null) ?: null, 'worked_on' => substr((string) $unit['worked_on'], 0, 10),
                    'quantity' => str_replace(',', '.', trim((string) $unit['quantity']))], $data['units'] ?? []))])),
            // FR-REM-03 AC1: a one-off bonus or an adjustment, with its reason; it may correct a pay validated before.
            Action::make('adjust')->label(__('payroll.add_adjustment'))->color('gray')->modalDescription(__('payroll.add_adjustment_help'))->visible(fn () => $this->draft())
                ->fillForm(fn () => ['kind' => 'bonus', 'idempotency_key' => (string) Str::uuid()])
                ->schema(fn () => [Select::make('kind')->label(__('payroll.kind'))->required()->options(__('payroll.adjustment_kinds')),
                    TextInput::make('amount')->label(__('payroll.amount'))->required()->suffix(__('formats.currency'))->helperText(__('payroll.signed_amount_help'))
                        ->regex('/^\s*-?\s*[0-9][0-9\s]{0,15}([.,][0-9]{1,2})?\s*$/'),
                    Textarea::make('reason')->label(__('payroll.reason'))->required()->minLength(3)->maxLength(500),
                    Select::make('corrects_run_id')->label(__('payroll.corrects'))->helperText(__('payroll.corrects_help'))->options(fn () => $this->correctable()),
                    Hidden::make('idempotency_key')])
                ->action(fn (array $data) => $this->change('adjust', ['kind' => $data['kind'], 'amount' => $this->sentAmount($data['amount']), 'reason' => $data['reason'],
                    'corrects_run_id' => ($data['corrects_run_id'] ?? null) ?: null, 'idempotency_key' => $data['idempotency_key']])),
            // AC2: a draft computed again from what holds now.
            Action::make('recompute')->label(__('payroll.recompute'))->color('gray')->requiresConfirmation()->modalDescription(__('payroll.recompute_help'))
                ->visible(fn () => $this->draft())
                ->action(fn () => $this->refreshed(SchoolScreen::change('payroll.run.generate', fn () => SchoolScreen::call('payroll.run.generate', PayrollRunController::class,
                    'generate', ['period' => $this->pay()['period'], 'staff_id' => $this->pay()['staff_id']])))),
            // BR-08: by someone else than whoever computed it; frozen then.
            Action::make('validate')->label(__('payroll.validate'))->requiresConfirmation()->modalDescription(__('payroll.validate_help'))
                ->visible(fn () => Payroll::validatable($this->pay()))
                ->action(fn () => $this->refreshed(SchoolScreen::change('payroll.run.validate', fn () => SchoolScreen::call('payroll.run.validate', PayrollRunController::class,
                    'validateRun', ['version' => $this->pay()['version']], ['run' => $this->run])))),
            // FR-REM-04 AC1: all or part of what is left of the net; its `salaries` expense written with it; once per key.
            Action::make('pay')->label(__('payroll.pay'))->modalDescription(__('payroll.pay_help'))
                ->visible(fn () => in_array($this->pay()['state'], ['validated', 'partially_paid'], true) && (float) $this->pay()['left_to_pay'] > 0
                    && $this->writable('payroll.payout.record'))
                ->fillForm(fn () => ['amount' => number_format((float) $this->pay()['left_to_pay'], 2, ',', ' '), 'paid_on' => now()->toDateString(),
                    'idempotency_key' => (string) Str::uuid()])
                ->schema(fn () => [$this->amountField('amount'), DatePicker::make('paid_on')->label(__('payroll.paid_on'))->required()
                    ->minDate($this->pay()['period'])->maxDate(now()),
                    Select::make('method_code')->label(__('payroll.method'))->required()->options($this->methods()),
                    TextInput::make('external_ref')->label(__('payroll.external_ref'))->maxLength(100), Hidden::make('idempotency_key')])
                ->action(fn (array $data) => $this->payouts('payroll.payout.record', 'pay', ['amount' => $this->sentAmount((string) $data['amount']),
                    'paid_on' => substr((string) $data['paid_on'], 0, 10), 'method_code' => $data['method_code'], 'external_ref' => $data['external_ref'] ?: null,
                    'idempotency_key' => $data['idempotency_key']], ['run' => $this->run])),
            // A payout taken back by its reversal, its whole amount, with its reason; decided by someone else.
            Action::make('reverse')->label(__('payroll.reverse'))->color('danger')->modalDescription(__('payroll.reverse_help'))
                ->visible(fn () => $this->reversible() !== [] && $this->writable('payroll.payout.record'))
                ->fillForm(fn () => ['idempotency_key' => (string) Str::uuid()])
                ->schema(fn () => [Select::make('payout')->label(__('payroll.payouts'))->required()->options($this->reversible()),
                    Textarea::make('reason')->label(__('payroll.reason'))->required()->minLength(3)->maxLength(500), Hidden::make('idempotency_key')])
                ->action(fn (array $data) => $this->payouts('payroll.payout.record', 'reverse', ['reason' => $data['reason'],
                    'idempotency_key' => $data['idempotency_key']], ['payout' => $data['payout']])),
            // BR-08: by someone else than whoever asked for it; the payout's expense cancelled with it.
            Action::make('validate_reversal')->label(__('payroll.validate_reversal'))->modalDescription(__('payroll.validate_reversal_help'))
                ->visible(fn () => $this->decidable() !== [])
                ->schema(fn () => [Select::make('payout')->label(__('payroll.reverse'))->required()->options($this->decidable())])
                ->action(fn (array $data) => $this->payouts('payroll.run.validate', 'validateReversal', ['version' => $this->payout($data['payout'])['version']],
                    ['payout' => $data['payout']])),
            Action::make('reject_reversal')->label(__('payroll.reject_reversal'))->color('danger')->visible(fn () => $this->decidable() !== [])
                ->schema(fn () => [Select::make('payout')->label(__('payroll.reverse'))->required()->options($this->decidable()),
                    Textarea::make('reason')->label(__('payroll.reason'))->required()->minLength(3)->maxLength(500)])
                ->action(fn (array $data) => $this->payouts('payroll.run.validate', 'reject', ['version' => $this->payout($data['payout'])['version'],
                    'reason' => $data['reason']], ['payout' => $data['payout']])),
        ];
    }

    /** @return array<string, string> the payouts not taken back nor waiting to be, by date, amount and means */
    private function reversible(): array
    {
        $payouts = $this->pay()['payouts'];
        $taken = array_column(array_filter($payouts, fn (array $row) => $row['kind'] === 'reversal' && $row['status'] !== 'rejected'), 'reverses_payout_id');
        return collect($payouts)->filter(fn (array $row) => $row['kind'] === 'payout' && $row['status'] === 'validated' && !in_array($row['id'], $taken, true))
            ->mapWithKeys(fn (array $row) => [$row['id'] => $this->described($row)])->all();
    }

    /** @return array<string, string> the reversals waiting, for a validator who did not ask for them */
    private function decidable(): array
    {
        if (!$this->writable('payroll.run.validate')) return [];
        return collect($this->pay()['payouts'])->filter(fn (array $row) => $row['status'] === 'pending_validation' && (int) $row['created_by'] !== (int) auth()->id())
            ->mapWithKeys(fn (array $row) => [$row['id'] => Formats::list([$this->described($row), Formats::isolated((string) $row['reason'])])])->all();
    }

    /** « 12/10/2026 · 30 000,00 DA · virement · VIR 77 » */
    private function described(array $row): string
    {
        return Formats::list(array_filter([Formats::date($row['paid_on']), $this->money($row['amount']), __('finance.methods.'.$row['method_code']),
            $row['external_ref'] ? Formats::isolated($row['external_ref']) : null]));
    }

    private function payout(string $id): array
    {
        return collect($this->pay()['payouts'])->firstWhere('id', $id) ?? abort(404);
    }

    private function payouts(string $permission, string $method, array $data, array $parameters): void
    {
        $this->refreshed(SchoolScreen::change($permission, fn () => SchoolScreen::call($permission, PayrollPayoutController::class, $method, $data, $parameters)));
    }

    /** A draft, for whoever computes the pays for the whole school. */
    private function draft(): bool
    {
        return $this->pay()['status'] === 'draft' && $this->writable('payroll.run.generate');
    }

    /** What the variable part counts: hours, or sessions. */
    private function basis(): string
    {
        $pay = $this->pay();
        return $pay['kind'] === 'mixed' ? (string) $pay['variable_basis'] : ($pay['kind'] === 'per_session' ? 'per_session' : 'hourly');
    }

    /** The last day a unit may be on: the month's, today in the current month. */
    private function lastDay(): string
    {
        return min(Carbon::parse($this->pay()['period'])->endOfMonth()->toDateString(), now()->toDateString());
    }

    /** « 4,5 », « 12 »: without decimals when whole. */
    private function quantity(int|float|string|null $quantity): string
    {
        if ($quantity === null) return '';
        $value = (float) $quantity;
        return Formats::number($value, floor($value) == $value ? 0 : 2);
    }

    /** @return array<string, string> the school's referential in use, by code and name */
    private function nodeOptions(): array
    {
        return collect($this->nodes())->filter(fn (array $row) => empty($row['archived_at']))
            ->mapWithKeys(fn (array $row) => [$row['id'] => $this->nodeName($row['id'])])->all();
    }

    /** @return array<string, string> the member's validated pays of the months before, which an adjustment may correct */
    private function correctable(): array
    {
        return collect(SchoolScreen::call(static::$permissions, PayrollRunController::class, 'corrected', parameters: ['run' => $this->run])['data'])
            ->mapWithKeys(fn (array $row) => [$row['id'] => $this->month($row['period']).' · '.$this->money($row['net_amount'])])->all();
    }

    private function change(string $method, array $data): void
    {
        $this->refreshed(SchoolScreen::change('payroll.run.generate', fn () => SchoolScreen::call('payroll.run.generate', PayrollRunController::class, $method, $data,
            ['run' => $this->run])));
    }

    /** What the screen read of the pay is read again after a change. */
    private function refreshed(array $result): array
    {
        $this->runRow = null;
        $this->cacheSchema('summaryInfolist', null);
        $this->cacheSchema('adjustmentsInfolist', null);
        $this->cacheSchema('payoutsInfolist', null);
        return $result;
    }
}
