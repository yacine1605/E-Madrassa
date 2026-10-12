<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Rules\StrongPassword;
use App\Support\AccountLinks;
use App\Support\Locales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Before sign-in: activation (72 h) and recovery (1 h) links, single use (FR-IAM-01, FR-IAM-02).
 * The database consumes a link atomically; an unknown, used or expired link gets one answer.
 */
final class AccountLinkController
{
    /** What the page shows before the link is used; 404 for any invalid link. */
    public function show(Request $request, string $purpose)
    {
        $data = $request->validate(['token' => 'required|string|size:64']);
        $row = DB::selectOne('SELECT name, username, school, needs_password FROM platform.peek_token(?, ?)', [$purpose, AccountLinks::hash($data['token'])]);
        abort_unless($row, 404);
        return ['data' => $row];
    }

    /** Opens an invited account, choosing its first password unless the person already has one. */
    public function activate(Request $request)
    {
        return $this->redeem($request, 'activation');
    }

    public function reset(Request $request)
    {
        return $this->redeem($request, 'recovery');
    }

    /** Always the same answer: nothing tells whether the email has an account. */
    public function forgot(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:200']);
        [$token, $hash] = AccountLinks::token();
        $person = DB::selectOne('SELECT email, name, locale FROM platform.issue_recovery(?, ?)', [$data['email'], $hash]);
        // In the person's own language, else in the language of the page that asked.
        if ($person) AccountLinks::queue('recovery', $person->email, $person->name, $token, Locales::usable($person->locale ?? app()->getLocale()));
        return response()->json(['message' => __('messages.recovery_sent')], 202);
    }

    private function redeem(Request $request, string $purpose)
    {
        $request->validate(['token' => 'required|string|size:64']);
        $hash = AccountLinks::hash($request->input('token'));
        $owner = DB::selectOne('SELECT name, email, username, needs_password FROM platform.peek_token(?, ?)', [$purpose, $hash]);
        if (!$owner) return Refusal::response('token_invalid', 409);
        // A person who already has a password keeps it: accepting the invitation is enough.
        $data = $request->validate(['password' => [$owner->needs_password ? 'required' : 'prohibited', 'string', 'confirmed',
            new StrongPassword([$owner->name, $owner->email, $owner->username])]]);
        // The database uses the link once, even if two requests race past the check above.
        DB::select('SELECT * FROM platform.redeem_token(?, ?, ?)', [$purpose, $hash, isset($data['password']) ? Hash::make($data['password']) : null]);
        return response()->noContent();
    }
}
