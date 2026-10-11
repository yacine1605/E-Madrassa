<?php

namespace App\Exports;

use App\Support\Accounts;
use App\Support\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** The school's accounts, as the accounts screen lists them. */
final class AccountsExport implements Dataset
{
    public function __construct(private CurrentSchool $school) {}

    public function permission(): string { return 'user.account.read'; }

    public function title(string $locale): string { return __('exports.accounts', [], $locale); }

    public function columns(string $locale): array
    {
        return array_map(fn ($key) => ['key' => $key, 'label' => __("exports.columns.$key", [], $locale)],
            ['name', 'email', 'username', 'external_ref', 'status', 'activated_at']);
    }

    public function filters(): array
    {
        return ['status' => ['nullable', Rule::in(['invited', 'active', 'suspended', 'revoked'])]];
    }

    public function count(array $filters): int
    {
        [$where, $bindings] = $this->where($filters);
        return (int) DB::selectOne("SELECT count(*) AS n FROM school.membership m WHERE $where", $bindings)->n;
    }

    public function rows(array $filters, string $locale): iterable
    {
        [$where, $bindings] = $this->where($filters);
        foreach (DB::cursor(Accounts::SELECT." WHERE $where ORDER BY u.name, m.user_id", $bindings) as $row) {
            yield ['name' => $row->name, 'email' => $row->email, 'username' => $row->username, 'external_ref' => $row->external_ref,
                'status' => __("exports.status.$row->status", [], $locale), 'activated_at' => $row->activated_at ? substr($row->activated_at, 0, 10) : null];
        }
    }

    private function where(array $filters): array
    {
        $where = 'm.tenant_id = ?';
        $bindings = [$this->school->id()];
        if (isset($filters['status'])) { $where .= ' AND m.status = ?'; $bindings[] = $filters['status']; }
        return [$where, $bindings];
    }
}
