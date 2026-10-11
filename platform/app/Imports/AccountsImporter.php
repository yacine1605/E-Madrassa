<?php

namespace App\Imports;

use App\Support\Accounts;
use App\Support\CurrentSchool;
use Illuminate\Support\Facades\DB;

/** Accounts by their reference (FR-IAM-01 AC3), created invited; undone only while nobody has used them. */
final class AccountsImporter implements Importer
{
    public function __construct(private Accounts $accounts, private CurrentSchool $school) {}

    public function permission(): string { return 'user.account.create'; }

    public function rules(): array
    {
        return ['external_ref' => 'required|string|max:100', 'name' => 'required|string|max:200', 'email' => 'nullable|email|max:200',
            'username' => ['nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{2,39}$/']];
    }

    public function key(array $row): string { return trim((string) $row['external_ref']); }

    public function apply(array $row, array $options = []): array
    {
        $current = DB::selectOne(Accounts::SELECT.' WHERE m.tenant_id = ? AND m.external_ref = ?', [$this->school->id(), $this->key($row)]);
        if (!$current) {
            $created = $this->accounts->create([...$row, 'external_ref' => $this->key($row)], null);
            // Invitations leave only with the real import, never with its preview.
            if (($options['invite'] ?? false) && $created->status === 'invited' && !empty($row['email'])) $this->accounts->invite($created->user_id);
            return ['outcome' => 'created', 'target_type' => 'account', 'target_id' => (string) $created->user_id, 'previous' => null];
        }
        $previous = ['name' => $current->name, 'email' => $current->email, 'username' => $current->username];
        if ($this->wanted($row) === $previous) return ['outcome' => 'unchanged', 'target_type' => 'account', 'target_id' => (string) $current->id, 'previous' => null];
        DB::select('SELECT school.update_account(?, ?, ?, ?, ?)', [$current->id, $row['name'], $row['email'] ?? null, $row['username'] ?? null, $this->key($row)]);
        return ['outcome' => 'updated', 'target_type' => 'account', 'target_id' => (string) $current->id, 'previous' => $previous];
    }

    public function undo(string $outcome, string $targetId, ?array $previous, array $row): void
    {
        if ($outcome === 'created') {
            DB::select('SELECT school.discard_account(?)', [(int) $targetId]);
            return;
        }
        $current = DB::selectOne(Accounts::SELECT.' WHERE m.tenant_id = ? AND m.user_id = ?', [$this->school->id(), (int) $targetId]);
        if (!$current || [$current->name, $current->email, $current->username] !== array_values($this->wanted($row))) {
            throw new DependentOperation(0, __('errors.account_changed'));
        }
        DB::select('SELECT school.update_account(?, ?, ?, ?, ?)', [(int) $targetId, $previous['name'], $previous['email'], $previous['username'], $this->key($row)]);
    }

    private function wanted(array $row): array
    {
        $lower = fn (?string $value) => ($value = mb_strtolower(trim((string) $value))) === '' ? null : $value;
        return ['name' => trim($row['name']), 'email' => $lower($row['email'] ?? null), 'username' => $lower($row['username'] ?? null)];
    }
}
