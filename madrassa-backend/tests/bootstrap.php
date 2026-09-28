<?php
// php tests/bootstrap.php /absolute/path/to/installed/laravel
$root = $argv[1] ?? throw new RuntimeException('Laravel project path required');
$loader = require $root.'/vendor/autoload.php';
$loader->addPsr4('App\\', $root.'/app/', true);
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->instance('request', Illuminate\Http\Request::create('/'));
$kernel->bootstrap();
config(['session.driver'=>'array', 'cache.default'=>'array', 'app.debug'=>false]);
$routes = array_filter(iterator_to_array(app('router')->getRoutes()), fn($r)=>str_starts_with($r->uri(),'school-api/'));
if (count($routes)!==15) throw new RuntimeException('Expected 15 backend routes');
echo "PASS Laravel provider and 15 routes load\n";
foreach ([['GET','/school-api/csrf',200],['GET','/school-api/v1/tenants/11111111-1111-4111-8111-111111111111/students',401],['POST','/school-api/v1/tenants/11111111-1111-4111-8111-111111111111/payments',419]] as [$method,$uri,$status]) {
    $request = Illuminate\Http\Request::create($uri,$method,[],[],[],['HTTP_ACCEPT'=>'application/json']);
    $response = $kernel->handle($request);
    if ($response->getStatusCode()!==$status) throw new RuntimeException($uri.': expected '.$status.', got '.$response->getStatusCode());
    $kernel->terminate($request,$response);
    echo "PASS $method $uri -> $status\n";
}
