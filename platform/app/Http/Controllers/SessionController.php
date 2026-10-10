<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Sign-in and choice of school (W1.1). The chosen school is kept in the server session;
 * it is only ever one of the user's active memberships, as the database lists them.
 */
final class SessionController
{
    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => 'required|email|max:200', 'password' => 'required|string|max:200']);
        if (!Auth::attempt($credentials)) {
            return response()->json(['message' => 'Identifiants incorrects.', 'code' => 'invalid_credentials'], 401);
        }
        $schools = $this->schoolsOf((int) Auth::id());
        if ($schools->isEmpty()) {
            // FR-PLT-01 AC2: no session without an open school (suspended, revoked, none).
            $this->end($request);
            return response()->json(['message' => 'Aucun établissement accessible avec ce compte.', 'code' => 'no_school'], 403);
        }
        $request->session()->regenerate();
        // One school (always the case for a dedicated installation): it is opened directly.
        if ($schools->count() === 1) $request->session()->put('school.tenant', $schools->first()->tenant_id);
        return ['schools' => $schools, 'selected' => $request->session()->get('school.tenant')];
    }

    public function logout(Request $request)
    {
        $this->end($request);
        return response()->noContent();
    }

    public function schools(Request $request)
    {
        return ['schools' => $this->schoolsOf((int) $request->user()->getAuthIdentifier()), 'selected' => $request->session()->get('school.tenant')];
    }

    public function select(Request $request)
    {
        $data = $request->validate(['tenant_id' => 'required|uuid']);
        if (!$this->schoolsOf((int) $request->user()->getAuthIdentifier())->contains('tenant_id', $data['tenant_id'])) {
            return response()->json(['message' => 'Cet établissement ne vous est pas accessible.', 'code' => 'school_forbidden'], 403);
        }
        // A new session identifier for a new school: nothing carries over between schools.
        $request->session()->regenerate();
        $request->session()->put('school.tenant', $data['tenant_id']);
        return response()->noContent();
    }

    /** The schools this user may open; a dedicated installation offers only its own. */
    private function schoolsOf(int $user): Collection
    {
        $schools = collect(DB::transaction(function () use ($user) {
            DB::select("SELECT set_config('app.user_id', ?, true)", [(string) $user]);
            return DB::select('SELECT tenant_id, slug, name, default_locale, mode FROM school.my_schools()');
        }));
        $pinned = config('platform.dedicated_school');
        return $pinned ? $schools->where('slug', $pinned)->values() : $schools;
    }

    private function end(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
