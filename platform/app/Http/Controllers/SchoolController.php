<?php

namespace App\Http\Controllers;

use App\Support\CurrentSchool;
use Illuminate\Support\Facades\DB;

final class SchoolController
{
    /** The current school and its enabled modules (menus are built from this list, ARC-04). */
    public function show(CurrentSchool $school)
    {
        $row = DB::selectOne("SELECT t.id, t.slug, t.name, t.default_locale, t.profile_code, t.profile_version, p.subject_kind, p.labels AS profile_labels,
            school.setting_value('locale.languages') AS languages, (school.setting_value('locale.arabic_digits'))::boolean AS arabic_digits
            FROM platform.tenant t LEFT JOIN platform.profile p ON p.code = t.profile_code AND p.version = t.profile_version WHERE t.id = ?", [$school->id()]);
        $modules = array_column(DB::select('SELECT school.my_modules() AS module'), 'module');
        // FR-REF-03 AC3: "Matières" or "Modules" follows the profile, with the same codes. Time zone fixed (FR-REF-06).
        $profile = $row->profile_code ? ['code' => $row->profile_code, 'version' => $row->profile_version, 'subject_kind' => $row->subject_kind,
            'labels' => json_decode($row->profile_labels, true, 4, JSON_THROW_ON_ERROR)] : null;
        // FR-PLT-04: in a support session, what the banner shows.
        $support = $school->support() ? DB::selectOne('SELECT id, expires_at, include_sensitive FROM school.support_grant WHERE id = ?', [$school->support()]) : null;
        // FR-I18-01: the languages the school offers, and its digits in Arabic.
        return ['data' => ['id' => $row->id, 'slug' => $row->slug, 'name' => $row->name, 'default_locale' => $row->default_locale,
            'languages' => json_decode($row->languages, true, 2, JSON_THROW_ON_ERROR), 'arabic_digits' => $row->arabic_digits,
            'time_zone' => 'Africa/Algiers', 'mode' => $school->mode(), 'modules' => $modules, 'profile' => $profile, 'support' => $support]];
    }
}
