<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\DeliveredHoursController;
use App\Support\Formats;
use App\Support\SchoolScreen;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * FR-PTG-03, « Heures assurées »: by teacher, the sessions held in a month by the validated
 * attendance as it stands, their hours, those held as a substitute and those corrected; the sum the
 * month's pays count (AC1). A teacher reads their own.
 */
final class DeliveredHours extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 23;
    protected static ?string $section = 'planning';
    protected static array $permissions = ['hours.validated.read', 'payroll.run.generate'];
    protected static ?string $module = 'attendance';
    protected static string|array $routeMiddleware = ['module:attendance', 'permission:hours.validated.read,payroll.run.generate'];

    public static function getNavigationLabel(): string { return __('attendance.delivered_hours'); }
    public function getTitle(): string { return __('attendance.delivered_hours'); }

    public function table(Table $table): Table
    {
        return $table->records(function (array $filters, int $page, int $recordsPerPage) {
            $month = Carbon::parse($filters['month']['month'] ?? now()->toDateString());
            $rows = collect(SchoolScreen::call(static::$permissions, DeliveredHoursController::class, 'index', ['from' => $month->copy()->startOfMonth()->toDateString(),
                'to' => $month->copy()->endOfMonth()->toDateString()])['data'])->keyBy('id');
            return new LengthAwarePaginator($rows->forPage($page, $recordsPerPage), $rows->count(), $recordsPerPage, $page);
        })->paginationPageOptions([50, 100])->defaultPaginationPageOption(50)->description(__('attendance.delivered_hours_help'))
            ->emptyStateHeading(__('attendance.no_hours'))
            ->columns([
                TextColumn::make('last_name_lat')->label(__('planning.teacher'))->state(fn (array $record) => $this->name($record))
                    ->description(fn (array $record) => $record['staff_number']),
                TextColumn::make('sessions')->label(__('attendance.sessions'))->formatStateUsing(fn ($state) => Formats::number((int) $state))
                    ->description(fn (array $record) => implode(' · ', array_filter([
                        $record['replaced_sessions'] > 0 ? __('attendance.as_substitute', ['count' => Formats::number($record['replaced_sessions'])]) : null,
                        $record['corrected_sessions'] > 0 ? __('attendance.corrected_count', ['count' => Formats::number($record['corrected_sessions'])]) : null]))),
                TextColumn::make('hours')->label(__('attendance.hours'))->formatStateUsing(fn ($state) => Formats::number((float) $state, 2)),
            ])->filters([
                Filter::make('month')->schema([DatePicker::make('month')->label(__('attendance.month'))->default(now()->toDateString())]),
            ]);
    }

    private function name(array $record): string
    {
        $latin = trim(($record['last_name_lat'] ?? '').' '.($record['first_name_lat'] ?? ''));
        $arabic = trim(($record['last_name_ar'] ?? '').' '.($record['first_name_ar'] ?? ''));
        return Formats::isolated(app()->getLocale() === 'ar' ? ($arabic ?: $latin) : ($latin ?: $arabic));
    }
}
