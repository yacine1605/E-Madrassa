<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\PayrollRunController;
use App\Support\Formats;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * FR-REM-02: the pays of a month, one per member of staff, the teaching and the administrative
 * staff told apart. The accountant generates them as drafts from the rule in force that month, as
 * often as needed and never twice (AC1); someone else reads and validates each one (BR-08), which
 * freezes it (AC2). A pay with a unit no rate resolves is not validated (AC8).
 */
final class Payroll extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 15;
    protected static ?string $section = 'finance';
    protected static array $permissions = ['payroll.amount.read', 'payroll.run.generate', 'payroll.run.validate'];
    protected static ?string $module = 'payroll';
    protected static string|array $routeMiddleware = ['module:payroll', 'permission:payroll.amount.read,payroll.run.generate,payroll.run.validate'];

    public static function getNavigationLabel(): string { return __('payroll.runs'); }
    public function getTitle(): string { return __('payroll.runs'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (?string $search, array $filters, int $page, int $recordsPerPage) => $this->apiPage(static::$permissions, PayrollRunController::class,
            'index', $this->query($search, $filters), $page, $recordsPerPage))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->description(__('payroll.runs_help'))
            ->emptyStateHeading(__('payroll.no_run'))
            ->columns([
                TextColumn::make('member')->label(__('payroll.member'))->searchable()->state(fn (array $record) => $this->personName($record))
                    ->description(fn (array $record) => Formats::list(array_filter([$record['staff_number'], $record['staff_type'] ? __('staff.types.'.$record['staff_type']) : null]))),
                TextColumn::make('kind')->label(__('payroll.kind'))->formatStateUsing(fn (string $state) => __('payroll.kinds.'.$state))
                    ->description(fn (array $record) => (int) $record['days_paid'] !== (int) $record['days_in_month']
                        ? __('payroll.days_paid', ['days' => Formats::number((int) $record['days_paid']), 'total' => Formats::number((int) $record['days_in_month'])]) : null),
                TextColumn::make('fixed_amount')->label(__('payroll.fixed_part'))->formatStateUsing(fn ($state) => $this->money($state)),
                TextColumn::make('variable_amount')->label(__('payroll.variable_part'))->formatStateUsing(fn ($state) => $this->money($state))
                    ->description(fn (array $record) => $record['missing_rates'] > 0 ? trans_choice('payroll.missing_rates', $record['missing_rates'],
                        ['count' => Formats::number((int) $record['missing_rates'])]) : null)
                    ->color(fn (array $record) => $record['missing_rates'] > 0 ? 'danger' : null),
                TextColumn::make('extras')->label(__('payroll.bonuses_adjustments'))
                    ->state(fn (array $record) => $this->money((string) round((float) $record['bonus_amount'] + (float) $record['adjustment_amount'], 2))),
                TextColumn::make('net_amount')->label(__('payroll.net'))->weight('bold')->formatStateUsing(fn ($state) => $this->money($state)),
                TextColumn::make('status')->label(__('school.status'))->badge()->formatStateUsing(fn (string $state) => __('payroll.run_states.'.$state))
                    ->color(fn (string $state) => $state === 'validated' ? 'success' : 'warning')
                    ->description(fn (array $record) => $record['status'] === 'validated'
                        ? __('payroll.validated_by', ['name' => Formats::isolated((string) $record['validator']), 'date' => Formats::dateTime($record['validated_at'])])
                        : __('payroll.computed_by', ['name' => Formats::isolated((string) $record['computer']), 'date' => Formats::dateTime($record['computed_at'])])),
            ])->filters([
                SelectFilter::make('period')->label(__('payroll.month'))->options(fn () => $this->months())->default($this->opened())
                    ->selectablePlaceholder(false),
                SelectFilter::make('group')->label(__('payroll.group'))->options(__('payroll.groups')),
                SelectFilter::make('status')->label(__('school.status'))->options(__('payroll.run_states')),
            ])->headerActions([
                // AC1: the drafts of a month begun, for every member with a rule then; generated again, never twice.
                Action::make('generate')->label(__('payroll.generate'))->modalDescription(__('payroll.generate_help'))->visible(fn () => $this->writable('payroll.run.generate'))
                    ->fillForm(fn () => ['period' => $this->getTableFilterState('period')['value'] ?? $this->opened()])
                    ->schema(fn () => [Select::make('period')->label(__('payroll.month'))->required()->options($this->months())])
                    ->action(fn (array $data) => SchoolScreen::change('payroll.run.generate', fn () => SchoolScreen::call('payroll.run.generate', PayrollRunController::class,
                        'generate', ['period' => $data['period']]), fn (array $result) => Notification::make()->success()
                        ->title(trans_choice('payroll.generated', $result['data']['generated'], ['count' => Formats::number($result['data']['generated'])])))),
                Action::make('staff')->label(__('staff.staff'))->color('gray')->visible(fn () => Staff::canAccess())->url(fn () => Staff::getUrl()),
            ])->recordActions([ActionGroup::make([
                Action::make('open')->label(__('payroll.open_run'))->url(fn (array $record) => PayrollRun::getUrl(['run' => $record['id']])),
                // BR-08: by someone else than whoever computed it, no line lacking its rate.
                Action::make('validate')->label(__('payroll.validate'))->requiresConfirmation()->modalDescription(__('payroll.validate_help'))
                    ->visible(fn (array $record) => self::validatable($record))
                    ->action(fn (array $record) => SchoolScreen::change('payroll.run.validate', fn () => SchoolScreen::call('payroll.run.validate',
                        PayrollRunController::class, 'validateRun', ['version' => $record['version']], ['run' => $record['id']]))),
            ])]);
    }

    /** A draft whose lines all have their rate, for a validator who did not compute it. */
    public static function validatable(array $record): bool
    {
        return $record['status'] === 'draft' && (int) $record['missing_rates'] === 0 && (int) $record['computed_by'] !== (int) auth()->id()
            && SchoolScreen::writable('payroll.run.validate');
    }

    /** @return array<string, string> the months begun a pay may be for: this one and the two years before, the latest first */
    private function months(): array
    {
        $start = now()->startOfMonth();
        return collect(range(0, 24))->mapWithKeys(fn (int $k) => [$start->copy()->subMonthsNoOverflow($k)->toDateString()
            => $this->month($start->copy()->subMonthsNoOverflow($k)->toDateString())])->all();
    }

    /** The month the screen opens on: the one asked for (`?period=`, from a pay's detail), else the month before this one. */
    private function opened(): string
    {
        $asked = (string) request()->query('period', '');
        return array_key_exists($asked, $this->months()) ? $asked : now()->startOfMonth()->subMonthNoOverflow()->toDateString();
    }

    private function query(?string $search, array $filters): array
    {
        return array_filter(['search' => $search ?: null, 'period' => $filters['period']['value'] ?? $this->opened(),
            'group' => $filters['group']['value'] ?? null, 'status' => $filters['status']['value'] ?? null], fn ($value) => $value !== null && $value !== '');
    }
}
