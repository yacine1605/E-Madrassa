<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/** TST-02: code asks for permissions, never for roles (principle P4, FR-IAM-03 AC3). */
final class PermissionCoverageTest extends TestCase
{
    /** Routes describing the session itself, open to every member of the school. */
    private const OPEN = ['GET api/school', 'GET api/me'];

    public function test_application_code_never_names_a_role(): void
    {
        $roles = array_column(DB::connection('owner')->select('SELECT code FROM platform.role_template'), 'code');
        $roles[] = 'super_admin';
        $pattern = '/[\'"]('.implode('|', array_map('preg_quote', $roles)).')[\'"]/';
        $found = [];
        foreach (['app', 'routes', 'config'] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory))) as $file) {
                if ($file->getExtension() !== 'php') continue;
                foreach (file($file->getPathname()) as $number => $line) {
                    if (preg_match($pattern, $line)) $found[] = $file->getPathname().':'.($number + 1).': '.trim($line);
                }
            }
        }
        $this->assertSame([], $found, 'Role codes in application code: test permissions instead.');
    }

    public function test_every_school_route_declares_a_permission(): void
    {
        $missing = [];
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            if (!in_array('school', $middleware, true)) continue;
            $checked++;
            $name = implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri();
            $declared = array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'permission:'));
            if (!$declared && !in_array($name, self::OPEN, true)) $missing[] = $name;
        }
        $this->assertGreaterThan(10, $checked);
        $this->assertSame([], $missing, 'School routes without a permission.');
    }
}
