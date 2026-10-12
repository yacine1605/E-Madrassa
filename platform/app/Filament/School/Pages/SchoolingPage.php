<?php

namespace App\Filament\School\Pages;

use App\Exceptions\Refusal;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ReferentialController;
use App\Http\Controllers\SiteController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;

/**
 * Lot 2's screens (programmes, groups, pupils, enrolments): lists by pages from the API, the
 * referential's names for its identifiers. What a reader may see is the database's business.
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

    protected function money(int|float|string|null $amount): string
    {
        return $amount === null ? '' : Formats::number((float) $amount, 2).' '.__('formats.currency');
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
}
