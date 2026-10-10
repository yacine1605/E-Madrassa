<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Str;

/** Labels in fr, ar, en (at least fr or ar, BR-18), JSON columns and identifiers of the referential's controllers. */
trait HandlesLabels
{
    private function labelRules(string $field = 'labels'): array
    {
        return [$field => 'required|array',
            "$field.fr" => "required_without:$field.ar|nullable|string|max:150",
            "$field.ar" => "required_without:$field.fr|nullable|string|max:150",
            "$field.en" => 'nullable|string|max:150'];
    }

    private function labels(array $labels): string
    {
        $kept = array_filter(array_intersect_key($labels, ['fr' => 1, 'ar' => 1, 'en' => 1]), fn ($label) => $label !== null && trim($label) !== '');
        return json_encode($kept, JSON_THROW_ON_ERROR);
    }

    /** The row, with its JSON columns decoded. */
    private function decoded(object $row, array $columns = ['labels']): array
    {
        $data = (array) $row;
        foreach ($columns as $column) {
            if (isset($data[$column])) $data[$column] = json_decode($data[$column], true, 16, JSON_THROW_ON_ERROR);
        }
        return $data;
    }

    private function uuid(string $id): string
    {
        abort_unless(Str::isUuid($id), 404);
        return $id;
    }
}
