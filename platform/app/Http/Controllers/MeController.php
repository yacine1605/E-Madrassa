<?php

namespace App\Http\Controllers;

use App\Support\Locales;
use App\Support\Permissions;
use Illuminate\Http\Request;

final class MeController
{
    /** The signed-in user and what they may do here: the front end builds its menus from it, the server still checks every call. */
    public function show(Request $request, Permissions $permissions)
    {
        $user = $request->user();
        // FR-I18-01: the person's choice (none follows the school) and the language in use, with its direction.
        return ['data' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'locale' => $user->locale,
            'effective_locale' => app()->getLocale(), 'text_direction' => Locales::direction(), 'permissions' => $permissions->all()]];
    }
}
