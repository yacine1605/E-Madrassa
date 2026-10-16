<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\PayRuleController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * FR-REM-01: how a member of staff is paid, in versions from the first day of a month: by the hour,
 * the session, a fixed monthly salary or a mix, the administrative staff by a fixed salary only
 * (AC3); rates by default and by level, bonuses. A new version ends the one before, never in a month
 * gone by; begun, a version keeps what it says; one not begun is deleted. With `payroll.rule.manage`,
 * read with `payroll.amount.read` (AC2).
 */
final class StaffPayRules extends StaffMemberPage
{
    protected static bool $isDiscovered = true;
    protected static array $permissions = ['payroll.rule.manage', 'payroll.amount.read'];
    protected static ?string $module = 'payroll';
    protected static string|array $routeMiddleware = ['module:payroll', 'permission:payroll.rule.manage,payroll.amount.read'];

    protected function subject(): string { return __('payroll.rules'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (int $page, int $recordsPerPage) => $this->apiPage(static::$permissions, PayRuleController::class, 'index', [],
            $page, $recordsPerPage, ['staff' => $this->staff]))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->description(__('payroll.rules_help'))
            ->emptyStateHeading(__('payroll.no_rule'))
            ->columns([
                TextColumn::make('kind')->label(__('payroll.kind'))->formatStateUsing(fn (string $state) => __('payroll.kinds.'.$state))
                    ->description(fn (array $record) => $this->terms($record)),
                TextColumn::make('valid_from')->label(__('payroll.from'))->formatStateUsing(fn ($state) => $this->month($state)),
                TextColumn::make('valid_to')->label(__('payroll.until'))->placeholder(__('payroll.open'))->formatStateUsing(fn ($state) => $this->month($state)),
                TextColumn::make('rates')->label(__('payroll.rates'))->wrap()
                    ->state(fn (array $record) => Formats::list(array_map(fn (array $rate) => __('payroll.rate_of', ['node' => $rate['node_id']
                        ? Locales::label($rate['node_labels']) : __('payroll.default_rate'), 'amount' => $this->money($rate['unit_amount'])]), $record['rates'])) ?: null),
                TextColumn::make('bonuses')->label(__('payroll.bonuses'))->wrap()
                    ->state(fn (array $record) => Formats::list(array_map(fn (array $bonus) => Formats::isolated($bonus['label']).' '.$this->money($bonus['amount']),
                        $record['bonuses'])) ?: null),
                TextColumn::make('state')->label(__('school.status'))->badge()->formatStateUsing(fn (string $state) => __('payroll.states.'.$state))
                    ->color(fn (string $state) => match ($state) { 'in_force' => 'success', 'upcoming' => 'info', default => 'gray' })
                    ->description(fn (array $record) => $record['end_reason'] ? Formats::isolated($record['end_reason']) : null),
            ])->headerActions([
                Action::make('contracts')->label(__('staff.contracts'))->color('gray')->visible(fn () => $this->reads('staff.contract.read'))
                    ->url(fn () => StaffContracts::getUrl(['staff' => $this->staff])),
                // A version from the first day of a month, all or nothing; the one in force then ends the day before.
                Action::make('add')->label(__('payroll.add_rule'))->modalDescription(__('payroll.add_rule_help'))
                    ->visible(fn () => $this->changeable() && $this->member()['status'] !== 'left')
                    ->fillForm(fn () => ['kind' => $this->teaching() ? 'hourly' : 'fixed_monthly', 'valid_from' => now()->startOfMonth()->addMonth()->toDateString(),
                        'rates' => [['node_id' => null, 'unit_amount' => null]], 'bonuses' => [], 'idempotency_key' => (string) Str::uuid()])
                    ->schema(fn () => [
                        // AC3: the administrative staff by a fixed salary only.
                        Select::make('kind')->label(__('payroll.kind'))->required()->live()
                            ->options($this->teaching() ? __('payroll.kinds') : ['fixed_monthly' => __('payroll.kinds.fixed_monthly')]),
                        Select::make('valid_from')->label(__('payroll.from'))->required()->options($this->months())->helperText(__('payroll.from_help')),
                        $this->amountField('monthly_amount')->label(__('payroll.monthly_amount'))
                            ->visible(fn (Get $get) => in_array($get('kind'), ['fixed_monthly', 'mixed'], true)),
                        Select::make('variable_basis')->label(__('payroll.variable_basis'))->options(__('payroll.bases'))
                            ->visible(fn (Get $get) => $get('kind') === 'mixed')->required(fn (Get $get) => $get('kind') === 'mixed'),
                        TextInput::make('included_quantity')->label(__('payroll.included_quantity'))->numeric()->minValue(0)->step(0.5)->default(0)
                            ->helperText(__('payroll.included_quantity_help'))->visible(fn (Get $get) => $get('kind') === 'mixed'),
                        Repeater::make('rates')->label(__('payroll.rates'))->helperText(__('payroll.rates_help'))->addActionLabel(__('payroll.add_rate'))
                            ->visible(fn (Get $get) => $get('kind') !== 'fixed_monthly')->columns(2)->maxItems(200)
                            ->schema([Select::make('node_id')->label(__('payroll.node'))->placeholder(__('payroll.default_rate'))->options(fn () => $this->nodeOptions())->searchable(),
                                $this->amountField('unit_amount')->label(__('payroll.unit_amount'))]),
                        Repeater::make('bonuses')->label(__('payroll.bonuses'))->addActionLabel(__('payroll.add_bonus'))->columns(2)->maxItems(20)->defaultItems(0)
                            ->schema([TextInput::make('label')->label(__('payroll.bonus_label'))->required()->minLength(2)->maxLength(100),
                                $this->amountField('amount')]),
                        Hidden::make('idempotency_key'),
                    ])
                    ->action(fn (array $data) => $this->send('store', $this->sent($data), ['staff' => $this->staff])),
            ])->recordActions([ActionGroup::make([
                Action::make('end')->label(__('payroll.end_rule'))->color('warning')->modalDescription(__('payroll.end_rule_help'))
                    ->visible(fn (array $record) => $record['state'] !== 'ended' && $this->changeable())
                    ->schema(fn (array $record) => [Select::make('valid_to')->label(__('payroll.until'))->required()->options($this->monthEnds($record)),
                        Textarea::make('end_reason')->label(__('payroll.end_reason'))->minLength(3)->maxLength(500)])
                    ->action(fn (array $record, array $data) => $this->send('end', ['valid_to' => $data['valid_to'], 'end_reason' => ($data['end_reason'] ?? null) ?: null,
                        'version' => $record['version']], ['rule' => $record['id']])),
                Action::make('delete')->label(__('payroll.delete_rule'))->color('danger')->requiresConfirmation()->modalDescription(__('payroll.delete_rule_help'))
                    ->visible(fn (array $record) => $record['state'] === 'upcoming' && $this->changeable())
                    ->action(fn (array $record) => $this->send('destroy', ['version' => $record['version']], ['rule' => $record['id']])),
            ])]);
    }

    /** Written for the whole school only. */
    private function changeable(): bool { return !$this->member()['archived_at'] && $this->writable('payroll.rule.manage'); }

    /** « 45 000,00 DA par mois », « 40 000,00 DA + au-delà de 20 h ». */
    private function terms(array $record): ?string
    {
        return match ($record['kind']) {
            'fixed_monthly' => __('payroll.per_month', ['amount' => $this->money($record['monthly_amount'])]),
            'mixed' => __('payroll.mixed_terms', ['amount' => $this->money($record['monthly_amount']),
                'included' => Formats::number((float) $record['included_quantity'], floor((float) $record['included_quantity']) == (float) $record['included_quantity'] ? 0 : 2),
                'unit' => __('payroll.units.'.$record['variable_basis'])]),
            default => null,
        };
    }

    /** The first days of the months a version may start on: twelve before (a first version), the current one, twelve to come. */
    private function months(): array
    {
        $start = now()->startOfMonth();
        return collect(range(-12, 12))->mapWithKeys(fn (int $k) => [$start->copy()->addMonths($k)->toDateString() => $this->month($start->copy()->addMonths($k)->toDateString())])->all();
    }

    /** The last days of the months a version may end on: from the end of the month before this one, never before its start nor after its end. */
    private function monthEnds(array $record): array
    {
        $first = Carbon::parse(max($record['valid_from'], now()->startOfMonth()->subMonth()->toDateString()))->startOfMonth();
        $ends = [];
        for ($month = $first->copy(), $k = 0; $k < 24; $month->addMonth(), $k++) {
            $end = $month->copy()->endOfMonth()->toDateString();
            if ($end < now()->startOfMonth()->subDay()->toDateString() || $end < $record['valid_from']) continue;
            if ($record['valid_to'] !== null && $end >= $record['valid_to']) break;
            $ends[$end] = $this->month($end);
        }
        return $ends;
    }

    /** @return array<string, string> the school's referential in use, by code and name */
    private function nodeOptions(): array
    {
        return collect($this->nodes())->filter(fn (array $row) => empty($row['archived_at']))
            ->mapWithKeys(fn (array $row) => [$row['id'] => $this->nodeName($row['id'])])->all();
    }

    /** As the API takes it: amounts as the base writes them, what the kind does not ask left out. */
    private function sent(array $data): array
    {
        $kind = $data['kind'];
        return ['kind' => $kind, 'valid_from' => $data['valid_from'], 'idempotency_key' => $data['idempotency_key'],
            'monthly_amount' => in_array($kind, ['fixed_monthly', 'mixed'], true) ? $this->sentAmount((string) $data['monthly_amount']) : null,
            'variable_basis' => $kind === 'mixed' ? $data['variable_basis'] : null,
            'included_quantity' => $kind === 'mixed' ? (string) ($data['included_quantity'] ?? 0) : null,
            'rates' => $kind === 'fixed_monthly' ? [] : array_values(array_map(fn (array $rate) => ['node_id' => $rate['node_id'] ?: null,
                'unit_amount' => $this->sentAmount((string) $rate['unit_amount'])], $data['rates'] ?? [])),
            'bonuses' => array_values(array_map(fn (array $bonus) => ['label' => $bonus['label'], 'amount' => $this->sentAmount((string) $bonus['amount'])], $data['bonuses'] ?? []))];
    }

    private function send(string $method, array $data, array $parameters): void
    {
        SchoolScreen::change('payroll.rule.manage', fn () => SchoolScreen::call('payroll.rule.manage', PayRuleController::class, $method, $data, $parameters));
    }
}
