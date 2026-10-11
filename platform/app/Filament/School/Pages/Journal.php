<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\AuditController;
use App\Support\Formats;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\Paginator;

/**
 * FR-AUD-01: the school's journal, read-only, newest first. Row-level security shows each
 * reader the domains their permissions open; the filters narrow by domain, object, action and
 * period.
 */
final class Journal extends SchoolPage implements HasTable
{
    use InteractsWithTable;

    protected static bool $isDiscovered = true;
    protected static array $permissions = ['audit.log.read'];
    protected static ?string $section = 'administration';
    protected static ?int $navigationSort = 120;
    protected static string|array $routeMiddleware = 'permission:audit.log.read';
    protected string $view = 'filament.school.table';

    /** What the audit trigger writes in place of a masked value (003, BR-17). */
    private const MASKED = "[masqu\u{00E9}]";

    public static function getNavigationLabel(): string { return __('admin.journal'); }
    public function getTitle(): string { return __('admin.journal'); }

    public function table(Table $table): Table
    {
        return $table->records(function (array $filters, int $page, int $recordsPerPage) {
            $query = ['page' => $page, 'limit' => $recordsPerPage];
            foreach (['domain', 'object_type', 'action'] as $filter) {
                if ($value = $filters[$filter]['value'] ?? null) $query[$filter] = $value;
            }
            foreach (['from', 'to'] as $bound) {
                if ($value = $filters['period'][$bound] ?? null) $query[$bound] = substr((string) $value, 0, 10);
            }
            if ($object = $filters['object']['object_id'] ?? null) $query['object_id'] = $object;
            $result = SchoolScreen::call('audit.log.read', AuditController::class, 'index', $query);
            return (new Paginator(collect($result['data'])->keyBy('id'), $recordsPerPage, $page))->hasMorePagesWhen($result['next'] !== null);
        })->paginationMode(PaginationMode::Simple)->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(50)->columns([
            TextColumn::make('occurred_at')->label(__('admin.occurred_at'))->formatStateUsing(fn ($state) => Formats::dateTime($state)),
            TextColumn::make('actor_name')->label(__('admin.actor'))->default(__('admin.system')),
            TextColumn::make('action')->label(__('admin.action'))->badge()->formatStateUsing(fn (string $state) => self::named('actions', $state)),
            TextColumn::make('object_type')->label(__('admin.object'))->formatStateUsing(fn (string $state) => self::named('objects', $state)),
            TextColumn::make('changes')->label(__('admin.changed_fields'))->wrap()
                ->state(fn (array $record) => implode(', ', array_map(fn ($field) => self::named('fields', $field), array_keys($record['after'] ?? [])))),
            TextColumn::make('reason')->label(__('admin.reason'))->limit(60),
        ])->filters([
            SelectFilter::make('domain')->label(__('admin.domain'))->options(__('admin.domains')),
            SelectFilter::make('object_type')->label(__('admin.object'))->options(__('admin.objects')),
            SelectFilter::make('action')->label(__('admin.action'))->options(__('admin.actions')),
            Filter::make('period')->schema([DatePicker::make('from')->label(__('admin.from')), DatePicker::make('to')->label(__('admin.to'))->afterOrEqual('from')]),
            Filter::make('object')->schema([TextInput::make('object_id')->label(__('admin.object_id'))->uuid()]),
        ])->recordActions([
            Action::make('details')->label(__('admin.details'))->modalHeading(__('admin.details'))->modalSubmitAction(false)
                ->modalContent(fn (array $record) => view('filament.school.journal-entry', ['entry' => $record,
                    'rows' => array_map(fn ($field) => ['field' => self::named('fields', $field), 'before' => self::value($record['before'][$field] ?? null),
                        'after' => self::value($record['after'][$field] ?? null)], array_keys($record['after'] ?? []))])),
        ]);
    }

    /** A code with its name in the catalog; a code the catalog does not know yet is shown as it is. */
    private static function named(string $group, string $code): string
    {
        return trans()->has("admin.$group.$code") ? __("admin.$group.$code") : $code;
    }

    private static function value(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            $value === self::MASKED => __('admin.masked'),
            is_bool($value) => __($value ? 'admin.yes' : 'admin.no'),
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };
    }
}
