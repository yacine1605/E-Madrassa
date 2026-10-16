<?php

namespace App\Filament\School\Pages\Concerns;

use App\Http\Controllers\LeadController;
use App\Http\Controllers\LeadOptionController;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

/**
 * What the prospects' screens share (FR-CRM-01): the school's lists of sources and steps, the
 * members a prospect is assigned to, a contact's and a child's fields.
 */
trait FollowsLeads
{
    private ?array $leadOptionRows = null;
    private array $assigneeRows = [];

    /** The school's lists, archived entries included, by kind and code. */
    protected function leadOptionRows(): array
    {
        return $this->leadOptionRows ??= SchoolScreen::call('crm.lead.read', LeadOptionController::class, 'index', ['include_archived' => true])['data'];
    }

    /** @return array<string, string> a list's entries still in use, and those given */
    protected function leadOptions(string $kind, array $kept = []): array
    {
        return collect($this->leadOptionRows())->where('kind', $kind)->filter(fn (array $option) => !$option['archived_at'] || in_array($option['code'], $kept, true))
            ->mapWithKeys(fn (array $option) => [$option['code'] => Locales::label($option['labels']) ?? $option['code']])->all();
    }

    /** @return array<string, string> the steps a prospect moves to: the open ones, and lost; registered comes with a conversion */
    protected function stepOptions(string $current): array
    {
        return collect($this->leadOptions('status', [$current]))->except([$current, 'registered'])->all();
    }

    protected function leadOptionName(string $kind, ?string $code): string
    {
        if ($code === null) return '';
        $option = collect($this->leadOptionRows())->first(fn (array $option) => $option['kind'] === $kind && $option['code'] === $code);
        return $option ? (Locales::label($option['labels']) ?? $code) : $code;
    }

    /** @return array<int, string> the members who read the prospects of this site */
    protected function assignees(?string $site): array
    {
        return $this->assigneeRows[$site ?? ''] ??= collect(SchoolScreen::call('crm.lead.read', LeadController::class, 'assignees',
            array_filter(['site_id' => $site]))['data'])->mapWithKeys(fn (array $member) => [$member['id'] => $member['name']])->all();
    }

    protected function contactFields(): array
    {
        return [
            TextInput::make('last_name')->label(__('admission.last_name'))->maxLength(80)->requiredWithout('first_name')->helperText(__('admission.contact_help')),
            TextInput::make('first_name')->label(__('admission.first_name'))->maxLength(80),
            TextInput::make('phone')->label(__('schooling.phone'))->tel()->maxLength(40)->requiredWithout('email')->helperText(__('admission.phone_help')),
            TextInput::make('email')->label(__('school.email'))->email()->maxLength(200),
            Select::make('relationship')->label(__('admission.relationship'))->options(__('admission.relationships')),
        ];
    }

    /** A child: their name, birth date if known, the level they aim at (a node of the referential still in use). */
    protected function childFields(): array
    {
        return [
            TextInput::make('first_name')->label(__('admission.first_name'))->required()->maxLength(80),
            TextInput::make('last_name')->label(__('admission.last_name'))->maxLength(80),
            DatePicker::make('birth_date')->label(__('admission.birth_date'))->maxDate(now()),
            Select::make('level_id')->label(__('admission.level'))->searchable()
                ->options(fn () => collect($this->nodes())->whereNull('archived_at')->mapWithKeys(fn (array $node) => [$node['id'] => $this->nodeName($node['id'])])->all()),
        ];
    }

    /** A child as the API takes them: empty texts are none, the birth date a day. */
    protected function sentChild(array $child): array
    {
        $child = array_map(fn ($value) => is_string($value) && trim($value) === '' ? null : $value, $child);
        if (!empty($child['birth_date'])) $child['birth_date'] = substr((string) $child['birth_date'], 0, 10);
        return array_intersect_key($child, array_flip(['first_name', 'last_name', 'birth_date', 'level_id', 'version']));
    }

    /** « Benali Karim », the contact as the family gave it. */
    protected function contactName(array $lead): string
    {
        return trim(($lead['last_name'] ?? '').' '.($lead['first_name'] ?? ''));
    }

    protected function childName(array $child): string
    {
        return trim(($child['first_name'] ?? '').' '.($child['last_name'] ?? ''));
    }
}
