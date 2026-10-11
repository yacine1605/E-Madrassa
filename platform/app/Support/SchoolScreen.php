<?php

namespace App\Support;

use App\Exceptions\Refusal;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Calls the existing controller validations/services inside the panel's school transaction. */
final class SchoolScreen
{
    public static function request(array $data = []): Request
    {
        // Livewire's JSON content type belongs to its envelope, not this form payload.
        $request = Request::create('/', 'POST', $data, server: ['REMOTE_ADDR' => request()->ip()]);
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

    public static function call(string $permission, string $controller, string $method, array $data = [], array $parameters = []): array
    {
        if (!app(Permissions::class)->can($permission)) throw Refusal::permissionDenied();
        return self::payload(app()->call([app($controller), $method], ['request' => self::request($data), ...$parameters]));
    }

    public static function writable(string $permission): bool
    {
        return app(CurrentSchool::class)->mode() === 'active' && !app(CurrentSchool::class)->support()
            && app(Permissions::class)->everywhere($permission);
    }

    /** Savepoint: a refused multi-step action rolls back, and the surrounding render still works. */
    public static function change(string $permission, callable $change): array
    {
        try {
            if (!self::writable($permission)) throw Refusal::permissionDenied();
            $result = DB::transaction(fn () => self::payload($change()));
            Notification::make()->title(__('school.saved'))->success()->send();
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
