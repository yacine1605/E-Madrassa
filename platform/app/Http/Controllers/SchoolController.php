<?php

namespace App\Http\Controllers;

use App\Support\CurrentSchool;
use Illuminate\Support\Facades\DB;

final class SchoolController
{
    /** The current school and its enabled modules (menus are built from this list, ARC-04). */
    public function show(CurrentSchool $school)
    {
        $row = DB::selectOne('SELECT id, slug, name, default_locale FROM platform.tenant WHERE id = ?', [$school->id()]);
        $modules = array_column(DB::select('SELECT school.my_modules() AS module'), 'module');
        return ['data' => [...(array) $row, 'mode' => $school->mode(), 'modules' => $modules]];
    }
}
