<?php
// Framework-independent contract checks for feature selection and boundaries.
require __DIR__.'/../madrassa-backend/app/School/Modules/ModuleRegistry.php';
use App\School\Modules\ModuleRegistry;
$definitions = [];
foreach (glob(__DIR__.'/../madrassa-backend/config/modules/*.php') as $path) $definitions[basename($path, '.php')] = require $path;
$check = function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$full = new ModuleRegistry($definitions);
$small = new ModuleRegistry($definitions, 'students,lifecycle');
$check($full->enabled('report_cards') && $full->enabled('teacher_portal'), 'Default keeps the complete platform');
$check($small->enabled('core') && $small->enabled('catalog'), 'Dependencies are included');
$check(!$small->enabled('finance') && !$small->enabled('pedagogy'), 'Unrequested features remain disabled');
$check($small->effectivePermissions(['students.read','students.write','finance.read','pedagogy.write']) === ['students.read','students.write'], 'Deployment cannot be bypassed by old grants');
$check($small->canAccessPage('students', ['students.read']), 'Selected page permits its reader');
$check(!$small->canAccessPage('students', ['catalog.read']), 'Unrelated grant cannot access a page');
$check(!$small->canAccessPage('payments', ['finance.read']), 'Disabled feature denies even a valid grant');
$check(!$full->canAccessPage('teacher-payments', ['payroll.read']), 'Compound workflows require all read permissions');
$check(!$full->canAccessPage('student-space', $full->permissions()), 'Unimplemented pages are unavailable');
$check($small->pageResources('enrollments') === ['enrollments'], 'Disabled finance resources disappear from enabled student pages');
$check(!$small->resourceEnabled('payments') && !$small->resourceEnabled('not_a_resource'), 'Disabled and unknown resources are denied');
$check(!$small->apiActionEnabled('payment') && !$small->apiActionEnabled('unknown'), 'Dedicated and unknown operations are denied');
$check($small->apiActionEnabled('enroll') && $small->apiActionEnabled('overview'), 'Selected module operations are available');
$check((new ModuleRegistry($definitions, 'payroll'))->enabled('finance'), 'Payroll includes payment dependencies');
$check((new ModuleRegistry($definitions, 'teacher_portal'))->enabled('pedagogy'), 'Teacher portal includes class and grade dependencies');
foreach (['mistyped', 'students,unknown'] as $selection) {
    try { new ModuleRegistry($definitions, $selection); throw new RuntimeException('Unknown feature accepted'); }
    catch (InvalidArgumentException $expected) {}
}
try {
    new ModuleRegistry(['core'=>['requires'=>['loop']], 'loop'=>['requires'=>['core']]]);
    throw new RuntimeException('Dependency cycle accepted');
} catch (InvalidArgumentException $expected) {}
$resources = []; $pages = []; $routes = []; $actions = [];
foreach ($definitions as $name => $definition) {
    foreach ($definition['api_actions'] ?? [] as $action) {
        $check(!isset($actions[$action]), 'Duplicate API action owner: '.$action); $actions[$action] = $name;
    }
    foreach ($definition['resources'] as $resource) {
        $check(!isset($resources[$resource]), 'Duplicate resource owner: '.$resource); $resources[$resource] = $name;
    }
    foreach ($definition['pages'] as $page => $entry) {
        $check(!isset($pages[$page]), 'Duplicate page owner: '.$page); $pages[$page] = $name;
        foreach ($entry['all'] ?? [] as $permission) $check(in_array($permission, $full->permissions(), true), 'Unknown page permission: '.$permission);
        foreach ($entry['resources'] as $resource) $check($full->resourceModule($resource) !== null, 'Unknown page resource: '.$resource);
    }
    foreach ($definition['routes'] ?? [] as $route) {
        $check(is_file(__DIR__.'/../ecole-filament/routes/'.$route), 'Missing module routes: '.$route);
        $check(!isset($routes[$route]), 'Duplicate route entrypoint: '.$route); $routes[$route] = $name;
    }
}
echo "PASS module dependencies, selection, permission filtering, page/resource boundaries, compound access, manifest ownership, routes, unknown configuration and cycle rejection\n";
