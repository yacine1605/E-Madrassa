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
    /** The person's own jobs and notifications, which row-level security alone decides (tested in tests/sql). */
    private const PERSONAL = ['api/jobs', 'api/notifications'];

    public function test_school_panel_pages_declare_permissions_and_persist_the_school_context(): void
    {
        $panel = \Filament\Facades\Filament::getPanel('school');
        $this->assertContains(\App\Http\Middleware\SchoolPanelContext::class, $panel->getAuthMiddleware());
        $persistent = app(\Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware::class)->getPersistentMiddleware();
        $this->assertContains(\App\Http\Middleware\SchoolPanelContext::class, $persistent);
        $this->assertContains(\App\Http\Middleware\RequirePermission::class, $persistent);
        foreach (['accounts' => 'user.account.read', 'roles' => 'role.definition.manage', 'assignments' => 'role.assignment.manage',
            'reference-types' => 'referential.node.read', 'reference-nodes' => 'referential.node.read', 'subjects' => 'referential.node.read',
            'years' => 'referential.node.read', 'periods' => 'referential.node.read', 'custom-fields' => 'referential.node.read',
            'settings' => 'tenant.settings.manage', 'support-access' => 'support.access.grant,audit.log.read', 'journal' => 'audit.log.read'] as $slug => $permission) {
            $route = Route::getRoutes()->getByName('filament.school.pages.'.$slug);
            $this->assertNotNull($route);
            $this->assertContains('permission:'.$permission, $route->gatherMiddleware());
        }
    }

    public function test_application_code_never_names_a_role(): void
    {
        $roles = array_column(DB::connection('owner')->select('SELECT code FROM platform.role_template'), 'code');
        $roles[] = 'super_admin';
        // These three also name kinds of people (custom field entities, staff kinds): a role is
        // meant only when the line speaks of roles. The other codes are never written at all.
        $people = ['student', 'parent', 'teacher'];
        $strict = '/[\'"]('.implode('|', array_map('preg_quote', array_diff($roles, $people))).')[\'"]/';
        $loose = '/[\'"]('.implode('|', $people).')[\'"]/';
        $found = [];
        foreach (['app', 'routes', 'config'] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory))) as $file) {
                if ($file->getExtension() !== 'php') continue;
                foreach (file($file->getPathname()) as $number => $line) {
                    if (preg_match($strict, $line) || (preg_match($loose, $line) && stripos($line, 'role') !== false)) {
                        $found[] = $file->getPathname().':'.($number + 1).': '.trim($line);
                    }
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
            $personal = (bool) array_filter(self::PERSONAL, fn ($prefix) => str_starts_with($route->uri(), $prefix));
            if (!$declared && !$personal && !in_array($name, self::OPEN, true)) $missing[] = $name;
        }
        $this->assertGreaterThan(10, $checked);
        $this->assertSame([], $missing, 'School routes without a permission.');
    }
}
