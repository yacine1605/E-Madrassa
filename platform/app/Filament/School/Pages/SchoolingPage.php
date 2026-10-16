<?php

namespace App\Filament\School\Pages;

use App\Exceptions\Refusal;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CustomFieldController;
use App\Http\Controllers\GuardianController;
use App\Http\Controllers\ReferentialController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SubjectController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\Permissions;
use App\Support\PupilRecords;
use App\Support\SchoolScreen;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;

/**
 * Lot 2's screens (programmes, groups, pupils, enrolments): lists by pages from the API, the
 * referential's names for its identifiers, the forms of people and of the school's own fields.
 * What a reader may see is the database's business.
 */
abstract class SchoolingPage extends SchoolPage implements HasTable
{
    use InteractsWithTable;

    protected static ?string $section = 'schooling';
    protected string $view = 'filament.school.table';
    // Private properties are request-local; Livewire never carries these catalogs across requests.
    private ?array $yearRows = null;
    private ?array $siteRows = null;
    private ?array $nodeRows = null;
    private array $fieldRows = [];

    /** The school's years, newest first, by identifier. */
    protected function years(): array
    {
        return $this->yearRows ??= collect($this->lookup(fn () => SchoolScreen::call('referential.node.read', CalendarController::class, 'index')['data']))
            ->keyBy('id')->all();
    }

    /** @return array<string, string> the years still open to changes (FR-REF-05), by identifier */
    protected function openYears(): array
    {
        return collect($this->years())->where('status', '!=', 'closed')->pluck('code', 'id')->all();
    }

    protected function yearClosed(?string $year): bool { return ($this->years()[$year ?? '']['status'] ?? null) === 'closed'; }

    /** Every site, archived ones included: a group keeps the site it was opened on. */
    protected function sites(): array
    {
        return $this->siteRows ??= collect($this->lookup(fn () => SchoolScreen::call('referential.node.read', SiteController::class, 'index', ['include_archived' => true])['data']))
            ->keyBy('id')->all();
    }

    protected function siteName(?string $site): ?string
    {
        $row = $this->sites()[$site ?? ''] ?? null;
        return $row ? (Locales::label($row['labels']) ?? $row['code']) : null;
    }

    /** Every node of the referential, archived ones included, for the names of what refers to them. */
    protected function nodes(): array
    {
        return $this->nodeRows ??= collect($this->lookup(fn () => SchoolScreen::call('referential.node.read', ReferentialController::class, 'nodes', ['include_archived' => true])['data']))
            ->keyBy('id')->all();
    }

    protected function nodeName(?string $node): ?string
    {
        $row = $this->nodes()[$node ?? ''] ?? null;
        return $row ? $row['code'].' · '.Locales::label($row['labels']) : null;
    }

    /** A catalog the reader may not read leaves identifiers without names, never the page broken. */
    private function lookup(callable $read): array
    {
        try {
            return $read();
        } catch (Refusal) {
            return [];
        }
    }

    /** @return array<string, string> the sites still in use a new record may belong to, for this permission (SQL 029) */
    protected function creationSites(string $permission): array
    {
        $allowed = app(Permissions::class)->sites($permission);
        return collect($this->sites())->whereNull('archived_at')->filter(fn (array $site) => $allowed === null || in_array($site['id'], $allowed, true))
            ->mapWithKeys(fn (array $site) => [$site['id'] => $this->siteName($site['id'])])->all();
    }

    /** The site a new record belongs to: the school's default one when the writer may use it, else their first. */
    protected function siteField(string $permission): Select
    {
        return Select::make('site_id')->label(__('schooling.site'))->required()->helperText(__('schooling.record_site_help'))
            ->options(fn () => $this->creationSites($permission))
            ->default(function () use ($permission) {
                $sites = $this->creationSites($permission);
                $default = collect($this->sites())->firstWhere('is_default', true)['id'] ?? null;
                return isset($sites[$default]) ? $default : array_key_first($sites);
            });
    }

    /** @return array<string, string> the catalogue's subjects and modules still in use (FR-REF-03) */
    protected function subjectOptions(): array
    {
        return collect($this->lookup(fn () => SchoolScreen::call('referential.node.read', SubjectController::class, 'index')['data']))->whereNull('archived_at')
            ->mapWithKeys(fn (array $subject) => [$subject['id'] => $subject['code'].' · '.Locales::label($subject['labels'])])->all();
    }

    protected function money(int|float|string|null $amount): string
    {
        return $amount === null ? '' : Formats::number((float) $amount, 2).' '.__('formats.currency');
    }

    /** An amount in dinars, two decimals at most, as the school writes it (« 12 500,50 »). */
    protected function amountField(string $name): TextInput
    {
        return TextInput::make($name)->label(__('finance.amount'))->required()->suffix(__('formats.currency'))
            ->regex('/^\s*[0-9][0-9\s]{0,15}([.,][0-9]{1,2})?\s*$/')->helperText(__('finance.amount_help'));
    }

    protected function sentAmount(string $amount): string
    {
        // Spaces between thousands and a decimal comma, as written in Algeria; the API checks the rest.
        return str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], trim($amount));
    }

    protected function labelFields(): array
    {
        return [TextInput::make('labels.fr')->label(__('school.label_fr'))->requiredWithout('labels.ar')->maxLength(150),
            TextInput::make('labels.ar')->label(__('school.label_ar'))->requiredWithout('labels.fr')->maxLength(150)->extraInputAttributes(['dir' => 'rtl'])];
    }

    protected function codeField(): TextInput
    {
        return TextInput::make('code')->label(__('school.code'))->required()->regex('/^[A-Z0-9_-]{1,30}$/')->helperText(__('schooling.code_help'));
    }

    /** A person's name in the reader's alphabet first, then in the other (BR-18): « Benali Amine · بن علي أمين ». */
    protected function personName(array $person): string
    {
        $latin = trim(($person['last_name_lat'] ?? '').' '.($person['first_name_lat'] ?? ''));
        $arabic = trim(($person['last_name_ar'] ?? '').' '.($person['first_name_ar'] ?? ''));
        $names = app()->getLocale() === 'ar' ? [$arabic, $latin] : [$latin, $arabic];
        return implode(' · ', array_filter($names, fn ($name) => $name !== ''));
    }

    /** A full name in Arabic or in Latin letters, or both (FR-ELV-01). */
    protected function nameFields(): array
    {
        $arabic = fn (TextInput $field) => $field->maxLength(80)->extraInputAttributes(['dir' => 'rtl']);
        return [TextInput::make('last_name_lat')->label(__('schooling.last_name_lat'))->maxLength(80)->requiredWith('first_name_lat')
                ->requiredWithoutAll(['last_name_ar', 'first_name_ar'])->helperText(__('schooling.names_help')),
            TextInput::make('first_name_lat')->label(__('schooling.first_name_lat'))->maxLength(80)->requiredWith('last_name_lat'),
            $arabic(TextInput::make('last_name_ar')->label(__('schooling.last_name_ar'))->requiredWith('first_name_ar')),
            $arabic(TextInput::make('first_name_ar')->label(__('schooling.first_name_ar'))->requiredWith('last_name_ar'))];
    }

    protected function studentFields(): array
    {
        return [...$this->nameFields(),
            DatePicker::make('birth_date')->label(__('schooling.birth_date'))->required()->maxDate(now()),
            TextInput::make('birth_place')->label(__('schooling.birth_place'))->maxLength(120),
            Select::make('gender')->label(__('schooling.gender'))->options(__('schooling.genders')),
            TextInput::make('phone')->label(__('schooling.phone'))->tel()->maxLength(40),
            TextInput::make('email')->label(__('school.email'))->email()->maxLength(200),
            Textarea::make('address')->label(__('schooling.address'))->maxLength(300),
            TextInput::make('origin_school')->label(__('schooling.origin_school'))->maxLength(200)];
    }

    protected function guardianFields(): array
    {
        return [...$this->nameFields(), TextInput::make('phone')->label(__('schooling.phone'))->tel()->maxLength(40)->helperText(__('schooling.guardian_phone_help')),
            TextInput::make('email')->label(__('school.email'))->email()->maxLength(200),
            TextInput::make('profession')->label(__('schooling.profession'))->maxLength(120)];
    }

    /** The school's own fields of a record (FR-REF-04), archived ones included when their values are read. */
    protected function customDefinitions(string $entity, bool $archived = false): array
    {
        $key = $entity.($archived ? ':all' : '');
        return $this->fieldRows[$key] ??= $this->lookup(fn () => SchoolScreen::call('referential.node.read', CustomFieldController::class, 'index',
            ['entity' => $entity, 'include_archived' => $archived])['data']);
    }

    /** The inputs of the school's own fields; a sensitive one only for whoever may read it. */
    protected function customFields(string $entity): array
    {
        $sensitive = app(Permissions::class)->everywhere(PupilRecords::sensitivePermission($entity));
        return array_values(array_filter(array_map(function (array $field) use ($sensitive) {
            if ($field['is_sensitive'] && !$sensitive) return null;
            $name = 'custom.'.$field['key'];
            $input = match ($field['field_type']) {
                'number' => TextInput::make($name)->numeric(),
                'date' => DatePicker::make($name),
                'boolean' => Toggle::make($name),
                'select' => Select::make($name)->options(collect($field['options'])->mapWithKeys(fn ($option) => [$option['code'] => Locales::label($option['labels'])])->all()),
                default => TextInput::make($name)->maxLength(1000),
            };
            return $input->label(Locales::label($field['labels']) ?? $field['key'])->required((bool) $field['required'] && $field['field_type'] !== 'boolean');
        }, $this->customDefinitions($entity))));
    }

    protected function customValue(array $field, mixed $value): string
    {
        return match ($field['field_type']) {
            'boolean' => __($value ? 'admin.yes' : 'admin.no'),
            'date' => Formats::date($value),
            'number' => Formats::number((float) $value, floor((float) $value) == (float) $value ? 0 : 2),
            'select' => Locales::label(collect($field['options'])->firstWhere('code', $value)['labels'] ?? null) ?? (string) $value,
            default => (string) $value,
        };
    }

    /** As the API reads a person: empty texts are none; the school's numbers are numbers, its dates days. */
    /**
     * FR-ELV-02: the guardians already recorded who are probably the one being typed (same name,
     * same phone or e-mail), as the reader may see them; none for a reader who may not.
     */
    protected function guardianDuplicates(array $data): array
    {
        if (!app(Permissions::class)->can('guardian.record.read')) return [];
        return SchoolScreen::call('guardian.record.read', GuardianController::class, 'duplicates', $this->sentPerson($data, 'guardian'))['data'];
    }

    /** A probable duplicate as a person recognizes them: name, phone or e-mail, and why. */
    protected function guardianLine(array $guardian): string
    {
        return $this->personName($guardian).' · '.Formats::isolated($guardian['match'] === 'email' ? $guardian['email'] : $guardian['phone'])
            .' ('.__('schooling.duplicate_matches.'.$guardian['match']).')';
    }

    protected function sentPerson(array $data, string $entity): array
    {
        foreach ($data as $field => $value) if (is_string($value) && trim($value) === '') $data[$field] = null;
        if (isset($data['custom'])) {
            $types = collect($this->customDefinitions($entity))->pluck('field_type', 'key');
            // Only the fields the form shows: the API keeps the others (archived, sensitive) as they are.
            $data['custom'] = collect($data['custom'])->intersectByKeys($types)->map(fn ($value, string $key) => match (true) {
                $value === null || $value === '' => null,
                $types[$key] === 'number' => $value + 0,
                $types[$key] === 'date' => substr((string) $value, 0, 10),
                $types[$key] === 'boolean' => (bool) $value,
                default => $value,
            })->all();
        }
        return $data;
    }

    protected function sentStudent(array $data): array
    {
        $data = $this->sentPerson($data, 'student');
        if (!empty($data['birth_date'])) $data['birth_date'] = substr((string) $data['birth_date'], 0, 10);
        if (($data['matricule'] ?? null) === null) unset($data['matricule']);
        return $data;
    }
}
