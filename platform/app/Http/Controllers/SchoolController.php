<?php

namespace App\Http\Controllers;

use App\Support\CurrentSchool;
use Illuminate\Support\Facades\DB;

final class SchoolController
{
    /** The current school and its enabled modules (menus are built from this list, ARC-04). */
    public function show(CurrentSchool $school)
    {
        $row = DB::selectOne('SELECT t.id, t.slug, t.name, t.default_locale, t.profile_code, t.profile_version, p.subject_kind, p.labels AS profile_labels
            FROM platform.tenant t LEFT JOIN platform.profile p ON p.code = t.profile_code AND p.version = t.profile_version WHERE t.id = ?', [$school->id()]);
        $modules = array_column(DB::select('SELECT school.my_modules() AS module'), 'module');
        // FR-REF-03 AC3: "Matières" or "Modules" follows the profile, with the same codes. Time zone fixed (FR-REF-06).
        $profile = $row->profile_code ? ['code' => $row->profile_code, 'version' => $row->profile_version, 'subject_kind' => $row->subject_kind,
            'labels' => json_decode($row->profile_labels, true, 4, JSON_THROW_ON_ERROR)] : null;
        return ['data' => ['id' => $row->id, 'slug' => $row->slug, 'name' => $row->name, 'default_locale' => $row->default_locale,
            'time_zone' => 'Africa/Algiers', 'mode' => $school->mode(), 'modules' => $modules, 'profile' => $profile]];
    }
}
