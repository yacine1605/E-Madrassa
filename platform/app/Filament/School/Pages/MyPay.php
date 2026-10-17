<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\MyPayController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * FR-REM-05, « Ma rémunération »: a member's own validated pays, when the school lets its members
 * read theirs: the hours or sessions each counts, its detail and what was paid of it. Never anyone
 * else's; the database decides what is theirs.
 */
final class MyPay extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?string $section = 'personal';
    protected static ?int $navigationSort = 3;
    protected static array $permissions = ['payroll.self_view'];
    protected static ?string $module = 'payroll';
    protected static string|array $routeMiddleware = ['module:payroll', 'permission:payroll.self_view'];

    /** Only when the school lets its members read their own pays. */
    public static function canAccess(): bool
    {
        return parent::canAccess() && (bool) json_decode((string) DB::selectOne("SELECT school.setting_value('payroll.self_view')::text AS v")->v);
    }

    public static function getNavigationLabel(): string { return __('payroll.my_pay'); }
    public function getTitle(): string { return __('payroll.my_pay'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (int $page, int $recordsPerPage) => $this->apiPage(static::$permissions, MyPayController::class, 'index', [], $page, $recordsPerPage))
            ->paginationPageOptions([12, 24, 48])->defaultPaginationPageOption(12)->description(__('payroll.my_pay_help'))
            ->emptyStateHeading(__('payroll.no_my_pay'))
            ->columns([
                TextColumn::make('period')->label(__('payroll.month'))->formatStateUsing(fn ($state) => $this->month($state))
                    ->description(fn (array $record) => __('payroll.kinds.'.$record['kind'])),
                TextColumn::make('units')->label(__('payroll.units_done'))
                    ->state(fn (array $record) => $record['kind'] === 'fixed_monthly' ? null : $this->quantity($record['units']).' '.__('payroll.units.'.$this->basis($record))),
                TextColumn::make('net_amount')->label(__('payroll.net'))->weight('bold')->formatStateUsing(fn ($state) => $this->money($state)),
                TextColumn::make('paid')->label(__('payroll.paid'))->formatStateUsing(fn ($state) => $this->money($state)),
                TextColumn::make('state')->label(__('school.status'))->badge()->formatStateUsing(fn (string $state) => __('payroll.run_states.'.$state))
                    ->color(fn (string $state) => Payroll::color($state)),
            ])->recordActions([
                // Its detail line by line and its payouts.
                Action::make('detail')->label(__('payroll.open_run'))->modalSubmitAction(false)->modalHeading(fn (array $record) => $this->month($record['period']))
                    ->schema(fn (array $record) => $this->detail(SchoolScreen::call(static::$permissions, MyPayController::class, 'show', parameters: ['run' => $record['id']])['data'])),
            ]);
    }

    private function detail(array $pay): array
    {
        return [
            Section::make(__('payroll.detail'))->schema([RepeatableEntry::make('lines')->hiddenLabel()->placeholder(__('payroll.no_line'))->columns(3)
                ->state(array_map(fn (array $line) => [
                    'what' => Formats::list(array_filter([__('payroll.line_kinds.'.$line['kind']), $line['label'] !== null ? Formats::isolated($line['label']) : null,
                        $line['kind'] === 'unit' ? ($line['node_code'] !== null ? $line['node_code'].' · '.Locales::label($line['node_labels']) : __('payroll.default_rate')) : null,
                        $line['included'] ? __('payroll.included') : null])),
                    'quantity' => match ($line['kind']) {
                        'fixed' => __('payroll.days_paid', ['days' => Formats::number((int) $line['quantity']), 'total' => Formats::number((int) $pay['days_in_month'])]),
                        'unit' => Formats::list(array_filter([Formats::date($line['worked_on']), $this->quantity($line['quantity']).' '.__('payroll.units.'.$this->basis($pay)),
                            $line['unit_amount'] !== null ? $this->money($line['unit_amount']) : null])),
                        default => null,
                    },
                    'amount' => $line['amount'] !== null ? $this->money($line['amount']) : __('payroll.rate_missing')], $pay['lines']))
                ->schema([TextEntry::make('what')->hiddenLabel(), TextEntry::make('quantity')->hiddenLabel(), TextEntry::make('amount')->hiddenLabel()])]),
            Section::make(__('payroll.payouts'))->schema([RepeatableEntry::make('payouts')->hiddenLabel()->placeholder(__('payroll.no_payout'))->columns(3)
                ->state(array_map(fn (array $row) => ['what' => Formats::list([__('payroll.payout_kinds.'.$row['kind']), __('payroll.payout_statuses.'.$row['status'])]),
                    'amount' => ($row['kind'] === 'reversal' ? '− ' : '').$this->money($row['amount']),
                    'when' => Formats::list([Formats::date($row['paid_on']), __('finance.methods.'.$row['method_code'])])], $pay['payouts']))
                ->schema([TextEntry::make('what')->hiddenLabel(), TextEntry::make('amount')->hiddenLabel(), TextEntry::make('when')->hiddenLabel()])]),
        ];
    }

    /** What the variable part counts: hours, or sessions. */
    private function basis(array $pay): string
    {
        return $pay['kind'] === 'mixed' ? (string) $pay['variable_basis'] : ($pay['kind'] === 'per_session' ? 'per_session' : 'hourly');
    }

    /** « 4,5 », « 12 »: without decimals when whole. */
    private function quantity(int|float|string|null $quantity): string
    {
        $value = (float) $quantity;
        return Formats::number($value, floor($value) == $value ? 0 : 2);
    }
}
