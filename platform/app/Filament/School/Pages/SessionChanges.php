<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\ClassSessionController;
use App\Support\Formats;
use App\Support\Locales;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FR-PLN-06 AC2, « Historique des séances »: the sessions cancelled, replaced or given back to
 * their teacher, of a group or all, eight weeks from the day chosen; each change with its reason,
 * the teacher before and after, who made it and when. The database says which the reader sees.
 */
final class SessionChanges extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 20;
    protected static ?string $section = 'planning';
    protected static array $permissions = ['schedule.session.read'];
    protected static ?string $module = 'scheduling_manual';
    protected static string|array $routeMiddleware = ['module:scheduling_manual', 'permission:schedule.session.read'];

    public static function getNavigationLabel(): string { return __('planning.session_changes'); }
    public function getTitle(): string { return __('planning.session_changes'); }

    public function table(Table $table): Table
    {
        return $table->records(function (array $filters, int $page, int $recordsPerPage) {
            $from = Carbon::parse($filters['from']['from'] ?? now()->subDays(28)->toDateString());
            return $this->apiPage(static::$permissions, ClassSessionController::class, 'changes', array_filter(['from' => $from->toDateString(),
                'to' => $from->copy()->addDays(55)->toDateString(), 'group_id' => $filters['group']['value'] ?? null]), $page, $recordsPerPage);
        })->paginationPageOptions([50, 100])->defaultPaginationPageOption(50)->description(__('planning.session_changes_help'))
            ->emptyStateHeading(__('planning.no_session_change'))
            ->columns([
                TextColumn::make('day')->label(__('planning.day'))->formatStateUsing(fn ($state) => Formats::date($state))
                    ->description(fn (array $record) => $record['start_time'].'–'.$record['end_time']),
                TextColumn::make('group_code')->label(__('planning.group'))->state(fn (array $record) => $this->label($record['group_code'], $record['group_labels']))
                    ->description(fn (array $record) => $this->label($record['subject_code'], $record['subject_labels'])),
                TextColumn::make('kind')->label(__('planning.change'))->badge()->formatStateUsing(fn (string $state) => __('planning.change_kinds.'.$state))
                    ->color(fn (string $state) => match ($state) { 'cancelled' => 'gray', 'replaced' => 'warning', default => 'info' })
                    ->description(fn (array $record) => Formats::isolated($record['reason'])),
                TextColumn::make('teacher_last_name')->label(__('planning.before'))->state(fn (array $record) => $this->name($record, 'teacher_')),
                TextColumn::make('new_last_name')->label(__('planning.after'))->state(fn (array $record) => $this->name($record, 'new_')),
                TextColumn::make('changed_at')->label(__('planning.changed_at'))->formatStateUsing(fn ($state) => Formats::dateTime($state))
                    ->description(fn (array $record) => $record['changed_by_name'] !== null ? Formats::isolated($record['changed_by_name']) : null),
            ])->filters([
                Filter::make('from')->schema([DatePicker::make('from')->label(__('planning.from'))->default(now()->subDays(28)->toDateString())]),
                SelectFilter::make('group')->label(__('planning.group'))->searchable()->options(fn () => collect(DB::select("SELECT id, code, labels FROM school.study_group
                    WHERE tenant_id = ? AND archived_at IS NULL ORDER BY code", [$this->schoolId]))
                    ->mapWithKeys(fn ($row) => [$row->id => $this->label($row->code, json_decode($row->labels, true))])->all()),
            ]);
    }

    private function label(?string $code, ?array $labels): string
    {
        return implode(' · ', array_filter([$code, $labels ? Locales::label($labels) : null]));
    }

    /** A teacher's name as the reader's language prefers it; none after a cancellation. */
    private function name(array $record, string $prefix): ?string
    {
        $latin = trim(($record[$prefix.'last_name'] ?? '').' '.($record[$prefix.'first_name'] ?? ''));
        $arabic = trim(($record[$prefix.'last_name_ar'] ?? '').' '.($record[$prefix.'first_name_ar'] ?? ''));
        $name = app()->getLocale() === 'ar' ? ($arabic ?: $latin) : ($latin ?: $arabic);
        return $name !== '' ? Formats::isolated($name) : null;
    }
}
