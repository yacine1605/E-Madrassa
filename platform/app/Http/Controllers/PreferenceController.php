<?php

namespace App\Http\Controllers;

use App\Support\Locales;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The person's own preferences, outside any school (FR-I18-01: their language). */
final class PreferenceController
{
    /** A language among those with a catalog, or none to follow the school. */
    public function locale(Request $request)
    {
        $data = $request->validate(['locale' => ['present', 'nullable', Rule::in(Locales::AVAILABLE)]],
            ['locale.in' => __('errors.language_unavailable')]);
        $user = $request->user();
        $user->forceFill(['locale' => $data['locale']])->save();
        // The school's offer still decides; outside a school, the person's choice or the browser's.
        $tenant = $request->session()->get('school.tenant');
        $request->session()->forget('locale');
        $effective = is_string($tenant) ? Locales::of($user->id, $tenant) : Locales::usable($data['locale'] ?? Locales::fromRequest($request));
        if (is_string($tenant)) $request->session()->put('locale', $effective);
        Locales::apply($effective);
        return ['data' => ['locale' => $data['locale'], 'effective_locale' => $effective, 'text_direction' => Locales::direction($effective)]];
    }
}
