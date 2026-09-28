<?php
return Illuminate\Foundation\Application::configure(basePath: dirname(__DIR__))
 ->withRouting(web: __DIR__.'/../routes/web.php',commands: __DIR__.'/../routes/console.php',health:'/up')
 ->withMiddleware(function($middleware) {
     // Cloudflare Tunnel terminates HTTPS before forwarding to the container.
     $middleware->trustProxies(at: '*');
 })
 ->withExceptions(function($exceptions) {})
 ->create();
