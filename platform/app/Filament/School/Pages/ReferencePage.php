<?php

namespace App\Filament\School\Pages;

use Filament\Forms\Components\TextInput;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** Shared presentation for the profile's small catalogs; SQL remains the source of search normalization. */
abstract class ReferencePage extends SchoolPage implements HasTable
{
    use InteractsWithTable;

    protected static array $permissions = ['referential.node.read'];
    protected static string|array $routeMiddleware = 'permission:referential.node.read';
    protected string $view = 'filament.school.table';

    protected function catalogPage(array $records, ?string $search, int $page, int $perPage): LengthAwarePaginator
    {
        $rows = collect($records)->keyBy(fn ($row) => $row['id'] ?? $row['code']);
        if ($search !== null && trim($search) !== '') {
            $texts = $rows->map(fn ($row) => implode(' ', [$row['code'], implode(' ', $row['labels'] ?? []), $row['year_code'] ?? '']));
            $keys = array_column(DB::select("SELECT key FROM jsonb_each_text(?::jsonb)
                WHERE position(school.search_key(?) IN school.search_key(value)) > 0",
                [json_encode((object) $texts->all(), JSON_THROW_ON_ERROR), $search]), 'key');
            $rows = $rows->only($keys);
        }
        return new LengthAwarePaginator($rows->forPage($page, $perPage), $rows->count(), $perPage, $page);
    }

    protected function labelFields(): array
    {
        return [TextInput::make('labels.fr')->label(__('school.label_fr'))->requiredWithout('labels.ar')->maxLength(150),
            TextInput::make('labels.ar')->label(__('school.label_ar'))->requiredWithout('labels.fr')->maxLength(150)->extraInputAttributes(['dir' => 'rtl'])];
    }

    protected function codeField(): TextInput
    {
        return TextInput::make('code')->label(__('school.code'))->required()->regex('/^[A-Z0-9_]{1,20}$/')
            ->helperText(__('reference.stable_code'));
    }

    protected function orderField(int $max = 100000): TextInput
    {
        return TextInput::make('sort_order')->label(__('reference.sort_order'))->numeric()->integer()->required()->minValue(0)->maxValue($max)->default(0);
    }

    protected function archiveFilter(): SelectFilter
    {
        return SelectFilter::make('archive_status')->label(__('school.status'))->options(__('reference.archive_states'))->default('active');
    }

    protected function filterArchived(array $rows, array $filters): array
    {
        $status = $filters['archive_status']['value'] ?? null;
        return array_values(array_filter($rows, fn ($row) => !$status || ($status === 'archived') === ($row['archived_at'] !== null)));
    }
}
