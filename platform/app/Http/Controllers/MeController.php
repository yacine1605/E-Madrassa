<?php

namespace App\Http\Controllers;

use App\Support\Permissions;
use Illuminate\Http\Request;

final class MeController
{
    /** The signed-in user and what they may do here: the front end builds its menus from it, the server still checks every call. */
    public function show(Request $request, Permissions $permissions)
    {
        $user = $request->user();
        return ['data' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'permissions' => $permissions->all()]];
    }
}
