<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\TimetableVersionController;
use App\Support\Formats;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/**
 * FR-PLN-05, « Publications du planning »: the versions of the school's timetable, newest first.
 * A version is prepared over a period with its reason, validated, then published; its conflicts
 * and its differences with another version or with the sessions as they are can be read. The
 * database refuses what conflicts or has changed since the copy (SQL 065).
 */
final class TimetableVersions extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 21;
    protected static ?string $section = 'planning';
    protected static array $permissions = ['schedule.session.update', 'schedule.timetable.publish'];
    protected static ?string $module = 'scheduling_manual';
    protected static string|array $routeMiddleware = ['module:scheduling_manual', 'permission:schedule.session.update,schedule.timetable.publish'];
    private array $differences = [];

    public static function getNavigationLabel(): string { return __('planning.versions'); }
    public function getTitle(): string { return __('planning.versions'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (int $page, int $recordsPerPage) => $this->apiPage('schedule.session.read', TimetableVersionController::class, 'index', [], $page, $recordsPerPage))
            ->paginationPageOptions([25, 50])->defaultPaginationPageOption(25)->description(__('planning.versions_help'))
            ->emptyStateHeading(__('planning.no_version'))
            ->columns([
                TextColumn::make('number')->label(__('planning.version_number'))->formatStateUsing(fn ($state) => Formats::number((int) $state)),
                TextColumn::make('date_from')->label(__('planning.period'))->state(fn (array $record) => Formats::date($record['date_from']).' – '.Formats::date($record['date_to'])),
                TextColumn::make('status')->label(__('school.status'))->badge()->formatStateUsing(fn (string $state) => __('planning.version_states.'.$state))
                    ->color(fn (string $state) => match ($state) { 'published' => 'success', 'validated' => 'info', 'draft' => 'warning', default => 'gray' })
                    ->description(fn (array $record) => Formats::isolated($record['reason'])),
                TextColumn::make('sessions')->label(__('planning.sessions_count'))->formatStateUsing(fn ($state) => Formats::number((int) $state)),
                TextColumn::make('created_at')->label(__('planning.prepared_by'))->formatStateUsing(fn ($state) => Formats::dateTime($state))
                    ->description(fn (array $record) => Formats::isolated($record['prepared_by_name'])),
                TextColumn::make('published_at')->label(__('planning.published'))->formatStateUsing(fn ($state) => Formats::dateTime($state))
                    ->description(fn (array $record) => Formats::isolated($record['published_by_name'])),
            ])->headerActions([
                Action::make('prepare')->label(__('planning.prepare_version'))->visible(fn () => $this->writable('schedule.session.update'))
                    ->schema([DatePicker::make('date_from')->label(__('planning.from'))->required()->live(),
                        DatePicker::make('date_to')->label(__('planning.until'))->required()->minDate(fn (Get $get) => $get('date_from')), $this->reason()])
                    ->action(fn (array $data) => SchoolScreen::change('schedule.session.update', fn () => SchoolScreen::call('schedule.session.update', TimetableVersionController::class, 'store',
                        ['date_from' => substr((string) $data['date_from'], 0, 10), 'date_to' => substr((string) $data['date_to'], 0, 10), 'reason' => $data['reason']]))),
            ])->recordActions([ActionGroup::make([
                Action::make('conflicts')->label(__('planning.version_conflicts'))->modalSubmitAction(false)
                    ->visible(fn (array $record) => in_array($record['status'], ['draft', 'validated'], true))
                    ->schema(fn (array $record) => [TextEntry::make('conflicts')->hiddenLabel()->listWithLineBreaks()->state($this->conflicts($record['id']))]),
                Action::make('compare')->label(__('planning.compare_version'))->modalSubmitAction(false)
                    ->schema(fn (array $record) => [
                        Select::make('with')->label(__('planning.compare_with'))->live()->placeholder(__('planning.compare_current'))
                            ->options(fn () => $this->others($record['id'])),
                        ...array_map(fn (string $change) => TextEntry::make($change)->label(__('planning.change_list.'.$change))->listWithLineBreaks()
                            ->state(fn (Get $get) => $this->difference($record['id'], $get('with'), $change)), ['added', 'removed', 'changed'])]),
                Action::make('refresh')->label(__('planning.refresh_version'))->requiresConfirmation()->modalDescription(__('planning.refresh_version_help'))
                    ->visible(fn (array $record) => in_array($record['status'], ['draft', 'validated'], true) && $this->writable('schedule.session.update'))
                    ->action(fn (array $record) => $this->step('schedule.session.update', 'refresh', $record)),
                Action::make('validate')->label(__('planning.validate_version'))->requiresConfirmation()
                    ->visible(fn (array $record) => $record['status'] === 'draft' && $this->writable('schedule.timetable.publish'))
                    ->action(fn (array $record) => $this->step('schedule.timetable.publish', 'validateVersion', $record)),
                Action::make('publish')->label(__('planning.publish_version'))->color('success')->requiresConfirmation()->modalDescription(__('planning.publish_version_help'))
                    ->visible(fn (array $record) => $record['status'] === 'validated' && $this->writable('schedule.timetable.publish'))
                    ->action(fn (array $record) => SchoolScreen::change('schedule.timetable.publish', fn () => SchoolScreen::call('schedule.timetable.publish', TimetableVersionController::class, 'publish',
                        ['version' => $record['version']], ['version' => $record['id']]), fn (array $result) => Notification::make()->title(__('school.saved'))
                        ->body(__('planning.published_told', ['count' => Formats::number((int) $result['told'])]))->success())),
                Action::make('discard')->label(__('planning.discard_version'))->color('danger')->requiresConfirmation()
                    ->visible(fn (array $record) => in_array($record['status'], ['draft', 'validated'], true) && $this->writable('schedule.session.update'))
                    ->action(fn (array $record) => $this->step('schedule.session.update', 'discard', $record)),
            ])]);
    }

    private function step(string $permission, string $method, array $record): mixed
    {
        return SchoolScreen::change($permission, fn () => SchoolScreen::call($permission, TimetableVersionController::class, $method, ['version' => $record['version']],
            ['version' => $record['id']]));
    }

    /** AC1: the version's blocking conflicts, one a line; or that it has none. */
    private function conflicts(string $version): array
    {
        $lines = array_map(fn (array $conflict) => Formats::date($conflict['day']).' '.$conflict['start_time'].' · '.$conflict['group_code'].' : '.$conflict['message'],
            SchoolScreen::call('schedule.session.read', TimetableVersionController::class, 'conflicts', [], ['version' => $version])['data']);
        return $lines === [] ? [__('planning.no_conflict')] : $lines;
    }

    /** The other versions to compare with, newest first. */
    private function others(string $version): array
    {
        return collect(SchoolScreen::call('schedule.session.read', TimetableVersionController::class, 'index', ['per_page' => 100, 'paginate' => true])['data'])
            ->reject(fn (array $row) => $row['id'] === $version)
            ->mapWithKeys(fn (array $row) => [$row['id'] => __('planning.version_option', ['number' => $row['number'], 'state' => __('planning.version_states.'.$row['status'])])])
            ->all();
    }

    /** The sessions added, removed or changed between the version and the other one (or the sessions as they are), one a line. */
    private function difference(string $version, ?string $other, string $change): array
    {
        $rows = $this->differences[$version.'|'.$other] ??= SchoolScreen::call('schedule.session.read', TimetableVersionController::class, 'compare',
            array_filter(['with' => $other]), ['version' => $version])['data'];
        $lines = [];
        foreach ($rows as $row) {
            if ($row['change'] !== $change) continue;
            $lines[] = match ($change) {
                'added' => $this->line($row['after']),
                'removed' => $this->line($row['before']),
                default => $this->line($row['before']).' → '.$this->line($row['after']),
            };
        }
        return $lines === [] ? [__('planning.no_difference')] : $lines;
    }

    private function line(array $session): string
    {
        return implode(' · ', array_filter([__('admin.days')[Carbon::parse($session['day'])->dayOfWeek].' '.Formats::date($session['day']),
            $session['start_time'].'–'.$session['end_time'], $session['group_code'], $session['subject_code'],
            $session['teacher'] !== null ? Formats::isolated($session['teacher']) : null, $session['room_code'],
            $session['status'] !== 'planned' ? __('planning.session_states.'.$session['status']) : null]));
    }
}
