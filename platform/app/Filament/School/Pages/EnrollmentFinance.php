<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\FinanceController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\Permissions;
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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * FR-FIN-01: what one enrolment owes and when. Its agreed price, adjustments and what is owed;
 * its schedule in force, whose sum is what is owed; a new adjustment or a new version of the
 * schedule; the direction's decision on an adjustment beyond the threshold.
 */
final class EnrollmentFinance extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static bool $shouldRegisterNavigation = false;
    protected static array $permissions = ['finance.payment.read', 'finance.discount.grant', 'finance.schedule.manage', 'finance.discount.approve'];
    protected static ?string $module = 'student_finance';
    protected static string|array $routeMiddleware = ['module:student_finance',
        'permission:finance.payment.read,finance.discount.grant,finance.schedule.manage,finance.discount.approve'];
    protected string $view = 'filament.school.enrollment-finance';
    #[Locked] public string $enrollment = '';
    private ?array $financeRow = null;
    private ?array $enrollmentRow = null;

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
            Section::make(__('finance.owed'))->columns(['default' => 2, 'sm' => 5])->schema([
                TextEntry::make('agreed')->label(__('finance.agreed_price'))->state($this->money($finance['agreed_price'])),
                TextEntry::make('reductions')->label(__('finance.reductions'))->state($this->money($finance['reductions'])),
                TextEntry::make('penalties')->label(__('finance.penalties'))->state($this->money($finance['penalties'])),
                TextEntry::make('due')->label(__('finance.due'))->state($this->money($finance['due']))->weight('bold'),
                TextEntry::make('pending')->label(__('finance.pending'))->state($this->money($finance['pending'])),
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
                'by' => Formats::isolated($row['author']).($row['decider'] ? ' · '.Formats::isolated($row['decider']) : '')], $this->finance()['adjustments']))
            ->schema([TextEntry::make('kind')->label(__('finance.kind')), TextEntry::make('amount')->label(__('finance.amount')),
                TextEntry::make('reason')->label(__('schooling.history_reason')), TextEntry::make('status')->label(__('school.status')),
                TextEntry::make('by')->label(__('finance.by'))])])]);
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

    /** An amount in dinars, two decimals at most, as the school writes it (« 12 500,50 »). */
    private function amountField(string $name): TextInput
    {
        return TextInput::make($name)->label(__('finance.amount'))->required()->suffix(__('formats.currency'))
            ->regex('/^\s*[0-9][0-9\s]{0,15}([.,][0-9]{1,2})?\s*$/')->helperText(__('finance.amount_help'));
    }

    private function sentAmount(string $amount): string
    {
        // Spaces between thousands and a decimal comma, as written in Algeria; the API checks the rest.
        return str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], trim($amount));
    }

    private function changed(array $result): array
    {
        $this->financeRow = null;
        $this->cacheSchema('summaryInfolist', null);
        $this->cacheSchema('adjustmentsInfolist', null);
        return $result;
    }
}
