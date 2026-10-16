<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\RetentionController;
use App\Http\Controllers\SettingController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/** FR-REF-06: the school's settings, each within the bounds the catalog and the database set. */
final class Settings extends SchoolPage implements HasTable
{
    use InteractsWithTable;

    protected static bool $isDiscovered = true;
    protected static array $permissions = ['tenant.settings.manage'];
    protected static ?string $section = 'administration';
    protected static ?int $navigationSort = 100;
    protected static string|array $routeMiddleware = 'permission:tenant.settings.manage';
    protected string $view = 'filament.school.table';

    public static function getNavigationLabel(): string { return __('admin.settings'); }
    public function getTitle(): string { return __('admin.settings'); }

    public function table(Table $table): Table
    {
        return $table->records(fn () => collect(SchoolScreen::call('tenant.settings.manage', SettingController::class, 'index')['data'])->keyBy('key')->all())
            ->paginated(false)->headerActions([
                // NFR-DATA-03: what the next daily run will do with the school's durations.
                Action::make('retention')->label(__('admin.retention'))->color('gray')->modalHeading(__('admin.retention'))
                    ->modalDescription(__('admin.retention_help'))->modalSubmitAction(false)->schema(fn () => $this->retention()),
            ])->columns([
                TextColumn::make('labels')->label(__('admin.setting'))->state(fn (array $record) => Locales::label($record['labels'])),
                TextColumn::make('value')->label(__('admin.value'))->wrap()->state(fn (array $record) => self::shown($record, $record['value'])),
                TextColumn::make('default_value')->label(__('admin.default'))->wrap()->state(fn (array $record) => self::shown($record, $record['default_value'])),
            ])->recordActions([
                Action::make('edit')->label(__('school.edit'))->visible(fn () => $this->writable('tenant.settings.manage'))
                    ->fillForm(fn (array $record) => ['value' => $record['value']])
                    ->schema(fn (array $record) => [self::field($record)])
                    ->action(fn (array $record, array $data) => SchoolScreen::change('tenant.settings.manage',
                        fn () => SchoolScreen::call('tenant.settings.manage', SettingController::class, 'update', ['value' => self::stored($record, $data['value'] ?? null)],
                            ['key' => $record['key']]))),
            ]);
    }

    /** Counts only: what the next run archives, anonymises and purges. */
    private function retention(): array
    {
        $due = SchoolScreen::call('tenant.settings.manage', RetentionController::class, 'show')['data']['due'];
        return [Section::make(__('admin.retention_next'))->compact()->columns(['default' => 1, 'sm' => 2])->schema(array_map(
            fn (string $category) => TextEntry::make('due_'.$category)->label(__('admin.retention_due.'.$category))->state(Formats::number($due[$category] ?? 0)),
            ['archive', 'pupil', 'guardian', 'journal']))];
    }

    /** The form field for the setting's kind of value; the database still checks the value. */
    private static function field(array $record): Component
    {
        $label = Locales::label($record['labels']);
        if (self::isDay($record)) return Select::make('value')->label($label)->options(__('admin.days'))->required();
        return match ($record['value_type']) {
            'integer' => TextInput::make('value')->label($label)->numeric()->integer()->required()
                ->minValue($record['min_value'])->maxValue($record['max_value'])
                ->helperText($record['min_value'] !== null && $record['max_value'] !== null
                    ? __('admin.bounds', ['min' => Formats::number((int) $record['min_value']), 'max' => Formats::number((int) $record['max_value'])]) : null),
            'boolean' => Toggle::make('value')->label($label),
            'text' => TextInput::make('value')->label($label)->maxLength((int) ($record['max_value'] ?? 1000)),
            'day_set' => CheckboxList::make('value')->label($label)->options(__('admin.days'))->required()->columns(4),
            'language_set' => CheckboxList::make('value')->label($label)->required()
                ->options(array_intersect_key(Locales::NAMES, array_flip(Locales::AVAILABLE))),
            'code' => Select::make('value')->label($label)->required()
                ->options(collect($record['allowed_values'])->mapWithKeys(fn (string $code) => [$code => self::code($record, $code)])->all()),
            'code_set' => CheckboxList::make('value')->label($label)->required()->columns(3)
                ->options(collect($record['allowed_values'])->mapWithKeys(fn (string $code) => [$code => self::code($record, $code)])->all()),
        };
    }

    /** The value as the database expects it: numbers as numbers, days as integers. */
    private static function stored(array $record, mixed $value): mixed
    {
        return match ($record['value_type']) {
            'integer' => (int) $value,
            'boolean' => (bool) $value,
            'text', 'code' => (string) ($value ?? ''),
            'day_set' => array_map('intval', array_values($value ?? [])),
            default => array_values($value ?? []),
        };
    }

    private static function shown(array $record, mixed $value): string
    {
        if (self::isDay($record)) return __('admin.days.'.$value);
        return match ($record['value_type']) {
            'integer' => Formats::number((int) $value),
            'boolean' => __($value ? 'admin.yes' : 'admin.no'),
            'text' => $value === '' || $value === null ? '—' : (string) $value,
            'day_set' => Formats::list(array_map(fn ($day) => __('admin.days.'.$day), $value)),
            'language_set' => Formats::list(array_map(fn ($locale) => Locales::NAMES[$locale] ?? $locale, $value)),
            'code' => self::code($record, (string) $value),
            'code_set' => Formats::list(array_map(fn ($code) => self::code($record, $code), $value)),
        };
    }

    /** A day of the week, held as its number (0, Sunday, to 6): shown and chosen by its name. */
    private static function isDay(array $record): bool
    {
        return $record['key'] === 'calendar.first_day_of_week';
    }

    private static function code(array $record, string $code): string
    {
        return __('admin.codes.'.$record['key'].'.'.$code);
    }
}
