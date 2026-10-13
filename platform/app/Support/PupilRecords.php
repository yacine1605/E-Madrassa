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
            'tenant_id' => 'prohibited', 'user_id' => 'prohibited', 'photo_file_id' => 'prohibited', 'site_id' => 'sometimes|uuid',
        ];
    }

    public function name(array $data): void
    {
        if (!(($data['last_name_ar'] ?? null) && ($data['first_name_ar'] ?? null))
            && !(($data['last_name_lat'] ?? null) && ($data['first_name_lat'] ?? null))) {
            throw ValidationException::withMessages(['last_name_lat' => __('errors.person_name_required')]);
        }
    }

    /**
     * Granted somewhere: a new record belongs to a site from its creation (SQL 029), which the
     * database checks against the grant's sites, and the default site for a grant of the whole school.
     */
    public function createPermission(string $permission): void
    {
        if (!$this->permissions->can($permission)) throw Refusal::permissionDenied();
    }

    /** A site of the school, archived or not (the database refuses an archived one with its reason). */
    public function site(array $data): void
    {
        if (isset($data['site_id']) && !DB::selectOne('SELECT 1 FROM school.site WHERE tenant_id = ? AND id = ?', [$this->school->id(), $data['site_id']])) {
            throw ValidationException::withMessages(['site_id' => __('errors.site_unknown')]);
        }
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
        // A pupil's photo, read at /api/students/{id}/photo; none while the school has photos turned off.
        if (array_key_exists('has_photo', $data)) $data['has_photo'] = $data['has_photo'] && $this->cached('photos', fn () => app(StudentPhotos::class)->enabled());
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
            'birth_date' => 'nullable|date_format:Y-m-d', 'status' => 'nullable|in:active,former,blocked', 'site_id' => 'nullable|uuid']);
        $sql = $select.' WHERE tenant_id = ?';
        $bindings = [$this->school->id()];
        if (!$request->boolean('include_archived')) $sql .= ' AND archived_at IS NULL';
        if (isset($data['q']) && $data['q'] !== '') {
            $sql .= ' AND '.Paging::search([...self::NAMES, 'phone', 'email', ...($entity === 'student' ? ['matricule'] : [])]);
            $bindings[] = $data['q'];
        }
        foreach ($entity === 'student' ? ['birth_date', 'status', 'site_id'] : ['site_id'] as $field) {
            if (isset($data[$field])) { $sql .= ' AND '.$field.' = ?'; $bindings[] = $data[$field]; }
        }
        // Domain lists always paginate, even when a caller asks for paginate=0.
        $request->merge(['paginate' => true]);
        return Paging::select($request, $sql, $bindings, 'name_key, id', fn ($row) => $this->present($row, $entity));
    }

    /**
     * FR-ELV-04: at most ten pupils the reader may see who are probably this one: the same phone, or
     * the same birth date and the same name, in the same alphabet or the other (NameMatch). Each
     * says why (`match`: phone, name, other_alphabet). A warning, never a merge.
     */
    public function duplicates(array $data): array
    {
        $candidates = DB::select("SELECT * FROM (SELECT id, matricule, last_name_ar, first_name_ar, last_name_lat, first_name_lat, birth_date,
              coalesce(nullif(regexp_replace(school.search_key(?), '[^0-9+]', '', 'g'), '') =
                regexp_replace(school.search_key(phone), '[^0-9+]', '', 'g'), false) AS same_phone
            FROM school.student WHERE tenant_id = ? AND archived_at IS NULL) s
            WHERE same_phone OR birth_date = ?::date ORDER BY matricule, id LIMIT 500", [$data['phone'] ?? '', $this->school->id(), $data['birth_date']]);
        return $this->matching($candidates, $data);
    }

    /**
     * FR-ELV-02: the guardians the reader may see who are probably this one: the same phone or
     * e-mail, and the same name in either alphabet. A household's phone shared by the father and the
     * mother is no duplicate. With their reference from the school's file, for the import's message.
     */
    public function guardianDuplicates(array $data): array
    {
        $candidates = DB::select("SELECT * FROM (SELECT id, last_name_ar, first_name_ar, last_name_lat, first_name_lat, phone, email, external_ref,
              coalesce(nullif(regexp_replace(school.search_key(?), '[^0-9+]', '', 'g'), '') =
                regexp_replace(school.search_key(phone), '[^0-9+]', '', 'g'), false) AS same_phone,
              coalesce(nullif(lower(btrim(?)), '') = email, false) AS same_email
            FROM school.guardian WHERE tenant_id = ? AND archived_at IS NULL) g
            WHERE same_phone OR same_email ORDER BY id LIMIT 500", [$data['phone'] ?? '', $data['email'] ?? '', $this->school->id()]);
        return $this->matching($candidates, $data, true);
    }

    /** @return list<array> at most ten, each with why it matches */
    private function matching(array $candidates, array $data, bool $guardian = false): array
    {
        $found = [];
        foreach ($candidates as $row) {
            $row = (array) $row;
            $name = NameMatch::compare($data, $row);
            $match = $guardian ? ($name === null ? null : ($row['same_phone'] ? 'phone' : 'email'))
                : ($row['same_phone'] ? 'phone' : ($row['birth_date'] === ($data['birth_date'] ?? null) ? $name : null));
            if ($match === null) continue;
            unset($row['same_phone'], $row['same_email']);
            $found[] = [...$row, 'match' => $match];
            if (count($found) === 10) break;
        }
        return $found;
    }
}
