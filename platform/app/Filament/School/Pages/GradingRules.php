<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\GradingController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * FR-LMS-04, « Règles de notation »: the scale, step, rounding and pass mark of a programme or of a
 * level, never of the whole school, from a day until another. Versions never change and never
 * overlap; a new one starts today or later, the current one is ended, one not begun is removed
 * (SQL 084). Kept by the pedagogy manager, read by the teachers.
 */
final class GradingRules extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 34;
    protected static ?string $section = 'pedagogy';
    protected static array $permissions = ['lms.grading.manage', 'lms.activity.manage'];
    protected static ?string $module = 'lms';
    protected static string|array $routeMiddleware = ['module:lms', 'permission:lms.grading.manage,lms.activity.manage'];

    public static function getNavigationLabel(): string { return __('pedagogy.grading_rules'); }
    public function getTitle(): string { return __('pedagogy.grading_rules'); }

    private function manages(): bool { return $this->writable('lms.grading.manage'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (array $filters, int $page, int $recordsPerPage) => $this->apiPage(static::$permissions, GradingController::class, 'index',
            array_filter(['include_ended' => ($filters['ended']['isActive'] ?? false) ?: null]), $page, $recordsPerPage))
            ->paginationPageOptions([25, 50])->defaultPaginationPageOption(25)->description(__('pedagogy.grading_rules_help'))
            ->emptyStateHeading(__('pedagogy.no_grading_rule'))->emptyStateDescription(__('pedagogy.default_rule'))
            ->columns([
                TextColumn::make('applies')->label(__('pedagogy.applies_to'))->state(fn (array $record) => $record['program_code'] ?? $record['node_code'])
                    ->description(fn (array $record) => __('pedagogy.applies_to_options.'.($record['program_id'] ? 'program' : 'node'))),
                TextColumn::make('scale_max')->label(__('pedagogy.scale_max'))->formatStateUsing(fn ($state) => '/ '.Formats::number((float) $state, 2)),
                TextColumn::make('step')->label(__('pedagogy.step'))->formatStateUsing(fn ($state) => Formats::number((float) $state, 2)),
                TextColumn::make('rounding')->label(__('pedagogy.rounding'))->formatStateUsing(fn ($state) => __('pedagogy.roundings.'.$state)),
                TextColumn::make('pass_ratio')->label(__('pedagogy.pass_mark'))->formatStateUsing(fn ($state) => Formats::number((float) $state * 100, 1).' %'),
                TextColumn::make('valid_from')->label(__('pedagogy.assigned_from'))->formatStateUsing(fn ($state) => Formats::date($state)),
                TextColumn::make('valid_to')->label(__('pedagogy.assigned_until'))->placeholder(__('dashboard.none'))->formatStateUsing(fn ($state) => Formats::date($state)),
            ])->filters([
                Filter::make('ended')->label(__('pedagogy.include_ended_rules'))->toggle(),
            ])
            ->headerActions([
                Action::make('create')->label(__('pedagogy.create_grading_rule'))->visible(fn () => $this->manages())
                    ->schema([
                        Radio::make('applies_to')->label(__('pedagogy.applies_to'))->required()->inline()->default('program')->options(__('pedagogy.applies_to_options'))->live(),
                        Select::make('program_id')->label(__('schooling.program'))->searchable()->options(fn () => $this->programOptions())
                            ->visible(fn (Get $get) => $get('applies_to') === 'program')->required(fn (Get $get) => $get('applies_to') === 'program'),
                        Select::make('node_id')->label(__('schooling.level'))->searchable()->helperText(__('pedagogy.rule_node_help'))
                            ->options(fn () => collect($this->nodes())->whereNull('archived_at')->mapWithKeys(fn (array $node) => [$node['id'] => $this->nodeName($node['id'])])->all())
                            ->visible(fn (Get $get) => $get('applies_to') === 'node')->required(fn (Get $get) => $get('applies_to') === 'node'),
                        TextInput::make('scale_max')->label(__('pedagogy.scale_max'))->required()->default('20')->regex('/^\d{1,4}([.,]\d{1,2})?$/')
                            ->helperText(__('pedagogy.scale_max_help')),
                        Select::make('step')->label(__('pedagogy.step'))->required()->default('0.25')->selectablePlaceholder(false)
                            ->options(['0.25' => Formats::number(0.25, 2), '0.5' => Formats::number(0.5, 2), '1' => Formats::number(1)]),
                        Select::make('rounding')->label(__('pedagogy.rounding'))->required()->default('half_up')->selectablePlaceholder(false)->options(__('pedagogy.roundings')),
                        TextInput::make('pass_percent')->label(__('pedagogy.pass_mark'))->required()->default('50')->numeric()->minValue(1)->maxValue(100)->suffix('%'),
                        DatePicker::make('valid_from')->label(__('pedagogy.assigned_from'))->minDate(now()->toDateString())->helperText(__('pedagogy.rule_from_help')),
                    ])
                    ->action(fn (array $data) => SchoolScreen::change('lms.grading.manage', fn () => SchoolScreen::call('lms.grading.manage', GradingController::class, 'store',
                        $this->sent($data)))),
            ])->recordActions([ActionGroup::make([
                Action::make('end')->label(__('pedagogy.end_rule'))->modalDescription(__('pedagogy.end_rule_help'))->color('warning')
                    ->visible(fn (array $record) => $record['valid_to'] === null && $this->manages())
                    ->schema(fn (array $record) => [DatePicker::make('valid_to')->label(__('pedagogy.assigned_until'))->required()->default(now()->toDateString())
                        ->minDate(max($record['valid_from'], now()->subDay()->toDateString()))])
                    ->action(fn (array $record, array $data) => SchoolScreen::change('lms.grading.manage', fn () => SchoolScreen::call('lms.grading.manage',
                        GradingController::class, 'end', ['valid_to' => substr((string) $data['valid_to'], 0, 10), 'version' => $record['version']], ['rule' => $record['id']]))),
                Action::make('remove')->label(__('pedagogy.remove_rule'))->modalDescription(__('pedagogy.remove_rule_help'))->color('danger')->requiresConfirmation()
                    ->visible(fn (array $record) => $record['valid_from'] > now()->toDateString() && $this->manages())
                    ->action(fn (array $record) => SchoolScreen::change('lms.grading.manage', fn () => SchoolScreen::call('lms.grading.manage',
                        GradingController::class, 'destroy', ['version' => $record['version']], ['rule' => $record['id']]))),
            ])]);
    }

    /** As the API takes it: the programme or the level, the pass mark as a ratio, no start for today. */
    private function sent(array $data): array
    {
        $sent = ['scale_max' => str_replace(',', '.', trim((string) $data['scale_max'])), 'step' => (string) $data['step'], 'rounding' => $data['rounding'],
            'pass_ratio' => rtrim(rtrim(number_format((float) $data['pass_percent'] / 100, 3, '.', ''), '0'), '.')];
        $sent[$data['applies_to'] === 'program' ? 'program_id' : 'node_id'] = $data[$data['applies_to'] === 'program' ? 'program_id' : 'node_id'];
        if (!empty($data['valid_from'])) $sent['valid_from'] = substr((string) $data['valid_from'], 0, 10);
        return $sent;
    }

    /** @return array<string, string> the programmes in use, newest year first */
    private function programOptions(): array
    {
        return collect(DB::select("SELECT p.id, p.code, p.labels, y.code AS year FROM school.program p JOIN school.academic_year y ON y.tenant_id = p.tenant_id AND y.id = p.year_id
            WHERE p.tenant_id = ? AND p.archived_at IS NULL AND p.status <> 'inactive' ORDER BY y.start_date DESC, p.code", [$this->schoolId]))
            ->mapWithKeys(fn ($row) => [$row->id => $row->code.' · '.Locales::label(json_decode($row->labels, true)).' · '.$row->year])->all();
    }
}
