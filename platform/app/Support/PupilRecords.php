<?php

namespace App\Support;

use App\Exceptions\Refusal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Shared data rules for pupil and guardian records. Row access remains enforced by SQL. */
final class PupilRecords
{
    public const NAMES = ['last_name_ar', 'first_name_ar', 'last_name_lat', 'first_name_lat'];
    private \WeakMap $requests;

    public function __construct(private CurrentSchool $school, private Permissions $permissions)
    {
        $this->requests = new \WeakMap();
    }

    public function rules(): array
    {
        return array_fill_keys(self::NAMES, 'nullable|string|max:80') + [
            'phone' => 'nullable|string|max:40', 'email' => 'nullable|email|max:200', 'custom' => 'sometimes|array|max:20',
            'tenant_id' => 'prohibited', 'user_id' => 'prohibited', 'photo_file_id' => 'prohibited',
        ];
    }

    public function name(array $data): void
    {
        if (!(($data['last_name_ar'] ?? null) && ($data['first_name_ar'] ?? null))
            && !(($data['last_name_lat'] ?? null) && ($data['first_name_lat'] ?? null))) {
            throw ValidationException::withMessages(['last_name_lat' => __('errors.person_name_required')]);
        }
    }

    /** An unlinked new record has no site/group yet: creation stays explicitly school-wide. */
    public function createPermission(string $permission): void
    {
        if (!$this->permissions->everywhere($permission)) throw Refusal::permissionDenied();
    }

    public function requireAction(string $permission, string $entity, string $id): void
    {
        DB::select('SELECT school.require_core(?, ?, ?)', [$permission, $entity, $id]);
    }

    public function present(object $row, string $entity): array
    {
        $data = (array) $row;
        $custom = json_decode($data['custom'], true, 32, JSON_THROW_ON_ERROR);
        if (!$this->sensitive($entity, $data['id'])) $custom = array_diff_key($custom, array_flip($this->sensitiveKeys($entity)));
        $data['custom'] = (object) $custom;
        return $data;
    }

    /** Submitted custom values patch the object; omitted (especially hidden) values survive. */
    public function custom(string $entity, array $values, ?object $current = null): string
    {
        if (!$this->sensitive($entity, $current?->id)
            && array_intersect(array_keys($values), $this->sensitiveKeys($entity))) throw Refusal::permissionDenied();
        $dates = $this->cached('dates:'.$entity, fn () => array_column(DB::select("SELECT key FROM school.custom_field
            WHERE tenant_id = ? AND entity = ? AND field_type = 'date' AND archived_at IS NULL", [$this->school->id(), $entity]), 'key'));
        foreach ($dates as $key) {
            if (array_key_exists($key, $values) && Validator::make(['value' => $values[$key]], ['value' => 'nullable|date_format:Y-m-d'])->fails())
                throw ValidationException::withMessages(['custom.'.$key => __('errors.custom_value_invalid')]);
        }
        $before = $current ? json_decode($current->custom, true, 32, JSON_THROW_ON_ERROR) : [];
        return json_encode((object) array_replace($before, $values), JSON_THROW_ON_ERROR);
    }

    private function sensitive(string $entity, ?string $id): bool
    {
        $access = $this->cached('access:'.$entity, fn () => DB::selectOne("SELECT school.can_everywhere('student.sensitive.read') AS everywhere,
            to_json(school.core_ids('student.sensitive.read', ?)) AS ids", [$entity]));
        return $access->everywhere || ($id !== null && in_array($id, json_decode($access->ids, true), true));
    }

    private function sensitiveKeys(string $entity): array
    {
        // Archived definitions still protect values already stored in a dossier.
        return $this->cached('fields:'.$entity, fn () => array_column(DB::select('SELECT key FROM school.custom_field WHERE tenant_id = ? AND entity = ? AND is_sensitive',
            [$this->school->id(), $entity]), 'key'));
    }

    /** A controller can survive several requests in a worker or a test; permissions cannot. */
    private function cached(string $key, callable $read): mixed
    {
        $request = request();
        $values = $this->requests[$request] ?? [];
        if (!array_key_exists($key, $values)) {
            $values[$key] = $read();
            $this->requests[$request] = $values;
        }
        return $values[$key];
    }

    public function listing(Request $request, string $select, string $entity): array
    {
        $data = $request->validate(Paging::RULES + ['q' => 'nullable|string|max:200', 'include_archived' => 'boolean',
            'birth_date' => 'nullable|date_format:Y-m-d', 'status' => 'nullable|in:active,former,blocked']);
        $sql = $select.' WHERE tenant_id = ?';
        $bindings = [$this->school->id()];
        if (!$request->boolean('include_archived')) $sql .= ' AND archived_at IS NULL';
        if (isset($data['q']) && $data['q'] !== '') {
            $sql .= ' AND '.Paging::search([...self::NAMES, 'phone', 'email', ...($entity === 'student' ? ['matricule'] : [])]);
            $bindings[] = $data['q'];
        }
        foreach ($entity === 'student' ? ['birth_date', 'status'] : [] as $field) {
            if (isset($data[$field])) { $sql .= ' AND '.$field.' = ?'; $bindings[] = $data[$field]; }
        }
        // Domain lists always paginate, even when a caller asks for paginate=0.
        $request->merge(['paginate' => true]);
        return Paging::select($request, $sql, $bindings, 'name_key, id', fn ($row) => $this->present($row, $entity));
    }

    /** Bounded, scoped suggestions. Cross-alphabet matches on birth date are warnings, never merges. */
    public function duplicates(array $data): array
    {
        $lat = trim(($data['last_name_lat'] ?? '').' '.($data['first_name_lat'] ?? ''));
        $ar = trim(($data['last_name_ar'] ?? '').' '.($data['first_name_ar'] ?? ''));
        return DB::select("SELECT id, matricule, last_name_ar, first_name_ar, last_name_lat, first_name_lat, birth_date
            FROM school.student WHERE tenant_id = ? AND archived_at IS NULL AND (
              (nullif(regexp_replace(school.search_key(?), '[^0-9+]', '', 'g'), '') IS NOT NULL AND
               regexp_replace(school.search_key(phone), '[^0-9+]', '', 'g') = regexp_replace(school.search_key(?), '[^0-9+]', '', 'g'))
              OR (birth_date = ?::date AND (
                (nullif(?, '') IS NOT NULL AND school.search_key(concat_ws(' ', last_name_lat, first_name_lat)) = school.search_key(?)) OR
                (nullif(?, '') IS NOT NULL AND school.search_key(concat_ws(' ', last_name_ar, first_name_ar)) = school.search_key(?)) OR
                (nullif(?, '') IS NOT NULL AND last_name_lat IS NULL AND last_name_ar IS NOT NULL) OR
                (nullif(?, '') IS NOT NULL AND last_name_ar IS NULL AND last_name_lat IS NOT NULL))))
            ORDER BY matricule, id LIMIT 10", [$this->school->id(), $data['phone'] ?? '', $data['phone'] ?? '',
                $data['birth_date'], $lat, $lat, $ar, $ar, $lat, $ar]);
    }
}
