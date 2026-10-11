<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Creating, inviting and importing the school's accounts (FR-IAM-01), for the API and for
 * background jobs alike. The database's functions check the permission and the rules.
 */
final class Accounts
{
    public const SELECT = 'SELECT m.user_id AS id, u.name, u.email, m.username, m.external_ref, m.status, m.activated_at, m.created_at,
        u.mfa_enabled_at IS NOT NULL AS mfa_enabled FROM school.membership m JOIN public.users u ON u.id = m.user_id';
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private CurrentSchool $school) {}

    public function create(array $data, ?string $provisional): object
    {
        return DB::selectOne('SELECT user_id, linked, status FROM school.create_account(?, ?, ?, ?, ?)', [$data['name'], $data['email'] ?? null,
            $data['username'] ?? null, $data['external_ref'] ?? null, $provisional === null ? null : Hash::make($provisional)]);
    }

    /** @return array{delivery: string, activation_url?: string} */
    public function invite(int $user): array
    {
        [$token, $hash] = AccountLinks::token();
        DB::select('SELECT school.issue_activation(?, ?)', [$user, $hash]);
        $person = DB::selectOne('SELECT name, email, NOT has_password AS fresh FROM public.users WHERE id = ?', [$user]);
        $school = DB::selectOne('SELECT name FROM platform.tenant WHERE id = ?', [$this->school->id()])->name;
        $locale = Locales::of($user, $this->school->id());
        if ($person->email && AccountLinks::send('activation', $person->email, $person->name, $token, $locale, $school)) return ['delivery' => 'sent'];
        // FR-IAM-01: when sending fails, the administrator may hand the link over, but only for
        // a person who has no password yet: the link never opens an existing person's account.
        return ['delivery' => 'failed'] + ($person->fresh ? ['activation_url' => AccountLinks::url('activation', $token)] : []);
    }

    public function provisional(): string
    {
        $password = '';
        for ($i = 0; $i < 12; $i++) $password .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        return $password;
    }

    /**
     * FR-IAM-01 AC3, BR-05: accounts by their stable reference. Imported again, a row updates its
     * account or leaves it as it is, never duplicates it. Accounts are created invited; with
     * `invite`, those with an email receive their link. A refused row is reported, not fatal.
     * Rows are numbered from $first, for the report of a job that imports in batches.
     */
    public function import(array $rows, bool $invite, int $first = 1): array
    {
        $refs = array_map(fn ($row) => trim($row['external_ref']), $rows);
        $existing = collect(DB::select(self::SELECT.' WHERE m.tenant_id = ? AND m.external_ref IN (SELECT json_array_elements_text(?::json))',
            [$this->school->id(), json_encode($refs, JSON_THROW_ON_ERROR)]))->keyBy('external_ref');
        $report = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'invited' => 0, 'rejected' => []];
        foreach (array_values($rows) as $index => $row) {
            $row['external_ref'] = trim($row['external_ref']);
            $current = $existing->get($row['external_ref']);
            try {
                // Each row in its own savepoint: a refused row leaves the others in place.
                $outcome = DB::transaction(function () use ($row, $current, $invite) {
                    if (!$current) {
                        $created = $this->create($row, null);
                        return $created->status === 'invited' && $invite && !empty($row['email'])
                            && ($this->invite($created->user_id)['delivery'] === 'sent') ? 'invited' : 'created';
                    }
                    $wanted = [trim($row['name']), self::lower($row['email'] ?? null), self::lower($row['username'] ?? null)];
                    if ($wanted === [$current->name, $current->email, $current->username]) return 'unchanged';
                    DB::select('SELECT school.update_account(?, ?, ?, ?, ?)',
                        [$current->id, $row['name'], $row['email'] ?? null, $row['username'] ?? null, $row['external_ref']]);
                    return 'updated';
                });
                if ($outcome === 'invited') $report['created']++;
                $report[$outcome]++;
            } catch (QueryException $e) {
                $refusal = DatabaseErrors::classify($e) ?? throw $e;
                $report['rejected'][] = ['row' => $first + $index, 'external_ref' => $row['external_ref'], 'code' => $refusal[1], 'message' => $refusal[2]];
            }
        }
        return $report;
    }

    /** Adds a batch's report to the running total. */
    public static function merge(array $total, array $batch): array
    {
        foreach (['created', 'updated', 'unchanged', 'invited'] as $key) $total[$key] = ($total[$key] ?? 0) + $batch[$key];
        $total['rejected'] = [...($total['rejected'] ?? []), ...$batch['rejected']];
        return $total;
    }

    private static function lower(?string $value): ?string
    {
        $value = $value === null ? null : mb_strtolower(trim($value));
        return $value === '' ? null : $value;
    }
}
