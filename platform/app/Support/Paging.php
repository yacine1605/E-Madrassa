<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * NFR-PERF-04: a list by pages, 25 rows by default and 100 at most, when the caller asks for it
 * (`paginate=1`, `page`, `per_page`), with its total; the whole list otherwise, as the API's
 * first callers expect it.
 */
final class Paging
{
    public const RULES = ['paginate' => 'boolean', 'page' => 'integer|min:1|max:100000', 'per_page' => 'integer|min:1|max:100'];

    /**
     * @param string $sql the list's query, without its order
     * @param ?int $whole the most rows the whole list gives, for the lists that always had a limit
     * @return array{data: list<mixed>, meta?: array{total: int, per_page: int, page: int}}
     */
    public static function select(Request $request, string $sql, array $bindings, string $order, ?callable $present = null, ?int $whole = null): array
    {
        $present ??= fn ($row) => $row;
        if (!$request->boolean('paginate')) {
            return ['data' => array_map($present, DB::select($sql.' ORDER BY '.$order.($whole ? ' LIMIT '.$whole : ''), $bindings))];
        }
        $perPage = (int) ($request->input('per_page') ?: 25);
        $page = (int) ($request->input('page') ?: 1);
        $total = (int) DB::selectOne('SELECT count(*) AS n FROM ('.$sql.') listed', $bindings)->n;
        $rows = DB::select($sql.' ORDER BY '.$order.' LIMIT ? OFFSET ?', [...$bindings, $perPage, ($page - 1) * $perPage]);
        return ['data' => array_map($present, $rows), 'meta' => ['total' => $total, 'per_page' => $perPage, 'page' => $page]];
    }

    /** The condition that finds a text however it is written (FR-I18-01 AC2), on the given columns. */
    public static function search(array $columns): string
    {
        return "position(coalesce(school.search_key(?), '') IN coalesce(school.search_key(concat_ws(' ', ".implode(', ', $columns).")), '')) > 0";
    }
}
