<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\CalendarExceptionController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\Permissions;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * FR-PLN-01: the school's calendar. The week's open days come from the Settings; the dated
 * closures (public holidays, vacations, others) and exceptional openings prevail over them, a
 * site's over the whole school's. Kept by `schedule.calendar.manage`, for the whole school or on
 * the grant's sites; read by whoever reads the planning.
 */
final class SchoolCalendar extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 20;
    protected static ?string $section = 'planning';
    protected static array $permissions = ['schedule.session.read', 'schedule.calendar.manage'];
    protected static ?string $module = 'scheduling_manual';
    protected static string|array $routeMiddleware = ['module:scheduling_manual', 'permission:schedule.session.read,schedule.calendar.manage'];

    public static function getNavigationLabel(): string { return __('planning.calendar'); }
    public function getTitle(): string { return __('planning.calendar'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (array $filters, int $page, int $recordsPerPage) => $this->apiPage(static::$permissions, CalendarExceptionController::class, 'index',
            array_filter(['site_id' => $filters['site']['value'] ?? null, 'include_past' => ($filters['past']['isActive'] ?? false) ?: null,
                'include_cancelled' => ($filters['cancelled']['isActive'] ?? false) ?: null]), $page, $recordsPerPage))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->description($this->openDays())
            ->emptyStateHeading(__('planning.no_exception'))->emptyStateDescription(__('planning.no_exception_help'))
            ->columns([
                TextColumn::make('kind')->label(__('planning.kind'))->badge()->formatStateUsing(fn (string $state) => __('planning.kinds.'.$state))
                    ->color(fn (string $state) => $state === 'opening' ? 'success' : 'warning'),
                TextColumn::make('site_id')->label(__('planning.scope'))->state(fn (array $record) => $record['site_id'] ? $this->siteName($record['site_id']) : __('planning.whole_school')),
                TextColumn::make('date_from')->label(__('planning.from'))->formatStateUsing(fn ($state) => Formats::date($state))
                    ->description(fn (array $record) => __('admin.days')[(int) date('w', strtotime($record['date_from']))]),
                TextColumn::make('date_to')->label(__('planning.until'))->formatStateUsing(fn ($state) => Formats::date($state))
                    ->description(fn (array $record) => __('admin.days')[(int) date('w', strtotime($record['date_to']))]),
                TextColumn::make('days')->label(__('reference.days'))->formatStateUsing(fn ($state) => Formats::number((int) $state)),
                TextColumn::make('reason')->label(__('school.reason'))->wrap()->formatStateUsing(fn ($state) => Formats::isolated((string) $state))
                    ->description(fn (array $record) => __('reference.recorded_by', ['name' => Formats::isolated($record['recorded_by_name'] ?? '')])),
                TextColumn::make('status')->label(__('school.status'))->badge()
                    ->formatStateUsing(fn (string $state) => __('planning.states.'.$state))
                    ->color(fn (string $state) => $state === 'planned' ? 'info' : 'gray')
                    ->description(fn (array $record) => $record['cancelled_at'] ? __('reference.cancelled_on', ['date' => Formats::date($record['cancelled_at'])]) : null),
            ])->filters([
                SelectFilter::make('site')->label(__('planning.scope'))->options(fn () => $this->siteOptions()),
                Filter::make('past')->label(__('planning.include_past'))->toggle(),
                Filter::make('cancelled')->label(__('planning.include_cancelled'))->toggle(),
            ])->headerActions([
                Action::make('slots')->label(__('planning.time_slots'))->color('gray')->url(fn () => TimeSlots::getUrl()),
                Action::make('add')->label(__('planning.add_exception'))->visible(fn () => $this->creatable('schedule.calendar.manage'))
                    ->schema([
                        Select::make('site_id')->label(__('planning.scope'))->options(fn () => $this->scopeOptions())->required()
                            ->default(fn () => array_key_first($this->scopeOptions()))->helperText(__('planning.scope_help')),
                        Select::make('kind')->label(__('planning.kind'))->options(__('planning.kinds'))->required()->default('holiday'),
                        DatePicker::make('date_from')->label(__('planning.from'))->required()->minDate(now()->startOfDay())->live(),
                        DatePicker::make('date_to')->label(__('planning.until'))->required()->minDate(fn (Get $get) => $get('date_from') ?: now()->startOfDay()),
                        Textarea::make('reason')->label(__('school.reason'))->required()->minLength(3)->maxLength(500)->helperText(__('planning.reason_help')),
                    ])
                    ->action(function (array $data) {
                        $site = $data['site_id'] === 'school' ? null : $data['site_id'];
                        return SchoolScreen::changeAt('schedule.calendar.manage', $site, fn () => SchoolScreen::call('schedule.calendar.manage',
                            CalendarExceptionController::class, 'store', ['site_id' => $site, 'kind' => $data['kind'],
                                'date_from' => substr((string) $data['date_from'], 0, 10), 'date_to' => substr((string) $data['date_to'], 0, 10), 'reason' => $data['reason']]));
                    }),
            ])->recordActions([ActionGroup::make([
                Action::make('cancel')->label(__('planning.cancel_exception'))->color('warning')->requiresConfirmation()->modalDescription(__('planning.cancel_exception_help'))
                    ->visible(fn (array $record) => $record['status'] === 'planned' && $record['date_to'] >= now()->toDateString() && $this->keeps($record['site_id']))
                    ->action(fn (array $record) => SchoolScreen::changeAt('schedule.calendar.manage', $record['site_id'],
                        fn () => SchoolScreen::call('schedule.calendar.manage', CalendarExceptionController::class, 'cancel', ['version' => $record['version']],
                            ['exception' => $record['id']]))),
            ])]);
    }

    /** The week's open days, as the Settings say, and the rule of the exceptions. */
    private function openDays(): string
    {
        $days = json_decode(DB::selectOne("SELECT school.setting_value('calendar.open_days') AS v")->v, true) ?? [];
        sort($days);
        return __('planning.calendar_help', ['days' => Formats::list(array_map(fn (int $day) => __('admin.days')[$day], $days))]);
    }

    /** The sites in use, for the filter. */
    private function siteOptions(): array
    {
        return collect($this->sites())->whereNull('archived_at')->mapWithKeys(fn (array $site) => [$site['id'] => Locales::label($site['labels']) ?? $site['code']])->all();
    }

    /** Where the reader may add one: the whole school with a school-wide grant, else the grant's sites. */
    private function scopeOptions(): array
    {
        $sites = app(Permissions::class)->sites('schedule.calendar.manage');
        $options = collect($this->siteOptions())->filter(fn ($name, $id) => $sites === null || in_array($id, $sites, true))->all();
        return $sites === null ? ['school' => __('planning.whole_school')] + $options : $options;
    }

    /** Whether the reader may cancel an exception of this scope. */
    private function keeps(?string $site): bool
    {
        if (!SchoolScreen::writable(null)) return false;
        $sites = app(Permissions::class)->sites('schedule.calendar.manage');
        return $sites === null || ($site !== null && in_array($site, $sites, true));
    }
}
