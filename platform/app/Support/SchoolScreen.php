<?php

namespace App\Support;

use App\Exceptions\Refusal;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Calls the existing controller validations/services inside the panel's school transaction. */
final class SchoolScreen
{
    /** @var ?\WeakMap<Request, array<string, ?list<string>>> the scopes read during a request */
    private static ?\WeakMap $scopes = null;

    public static function request(array $data = []): Request
    {
        // Livewire's JSON content type belongs to its envelope, not this form payload. An
        // uploaded file goes where the API receives it: among the request's files.
        $files = array_filter($data, fn ($value) => $value instanceof UploadedFile);
        $request = Request::create('/', 'POST', array_diff_key($data, $files), files: $files, server: ['REMOTE_ADDR' => request()->ip()]);
        $request->setUserResolver(fn () => auth()->user());
        $request->setLaravelSession(session()->driver());
        return $request;
    }

    public static function payload(mixed $result): array
    {
        if ($result instanceof JsonResponse) {
            $payload = $result->getData(true);
            if ($result->getStatusCode() >= 400) throw new Refusal($payload['code'] ?? 'bad_request', $payload['message'], $result->getStatusCode());
            return $payload;
        }
        return is_array($result) ? json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR) : [];
    }

    /**
     * @param string|list<string> $permission one of them suffices, as for the route; none for the
     *   person's own records (tasks, notifications), which row-level security limits to them
     */
    public static function call(string|array $permission, string $controller, string $method, array $data = [], array $parameters = []): array
    {
        $any = (array) $permission;
        if ($any !== [] && !collect($any)->contains(fn (string $one) => app(Permissions::class)->can($one))) throw Refusal::permissionDenied();
        return self::payload(app()->call([app($controller), $method], ['request' => self::request($data), ...$parameters]));
    }

    /** @param ?string $permission none for the person's own records */
    public static function writable(?string $permission): bool
    {
        return app(CurrentSchool::class)->mode() === 'active' && !app(CurrentSchool::class)->support()
            && ($permission === null || app(Permissions::class)->everywhere($permission));
    }

    /**
     * For an action on one record (lot 2): granted for the whole school, or for a scope that
     * reaches this record (site, level, group, own). The database decides again on writing.
     */
    public static function writableFor(string $permission, string $entity, string $id): bool
    {
        if (!self::writable(null)) return false;
        $request = request();
        self::$scopes ??= new \WeakMap();
        $scopes = self::$scopes[$request] ?? [];
        // One reading per permission and table for the whole list, not one per row.
        $key = $permission.':'.$entity;
        if (!array_key_exists($key, $scopes)) {
            $row = DB::selectOne('SELECT school.can_everywhere(?) AS everywhere, to_json(school.core_ids(?, ?)) AS ids', [$permission, $permission, $entity]);
            $scopes[$key] = $row->everywhere ? null : json_decode($row->ids, true, 4, JSON_THROW_ON_ERROR);
            self::$scopes[$request] = $scopes;
        }
        return $scopes[$key] === null || in_array($id, $scopes[$key], true);
    }

    /**
     * Savepoint: a refused multi-step action rolls back, and the surrounding render still works.
     *
     * @param ?callable(array): Notification $done what to tell once done, from the result; « saved » by default
     */
    public static function change(?string $permission, callable $change, ?callable $done = null): array
    {
        return self::run(fn () => self::writable($permission), $change, $done);
    }

    /** The same, for an action on one record that a scoped grant may reach (writableFor). */
    public static function changeFor(string $permission, string $entity, string $id, callable $change, ?callable $done = null): array
    {
        return self::run(fn () => self::writableFor($permission, $entity, $id), $change, $done);
    }

    private static function run(callable $allowed, callable $change, ?callable $done): array
    {
        try {
            if (!$allowed()) throw Refusal::permissionDenied();
            $result = DB::transaction(fn () => self::payload($change()));
            // What a change moved (a pupil into a group) may widen or narrow what the list shows next.
            self::$scopes = null;
            ($done ? $done($result) : Notification::make()->title(__('school.saved'))->success())->send();
            return $result;
        } catch (QueryException $e) {
            $refusal = DatabaseErrors::classify($e) ?? throw $e;
            Notification::make()->title($refusal[2])->danger()->send();
            throw new Halt();
        } catch (Refusal $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            throw new Halt();
        } catch (ValidationException $e) {
            // Controller field paths are shared by multiple action forms; keep the entered data
            // and show all refusals without binding an error to the wrong form's state path.
            Notification::make()->title(__('school.invalid'))->body(implode("\n", $e->validator->errors()->all()))->danger()->send();
            throw new Halt();
        }
    }
}
