<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/config/bootstrap.php';

$environment = $_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: 'prod';
$debug = filter_var($_SERVER['APP_DEBUG'] ?? getenv('APP_DEBUG'), FILTER_VALIDATE_BOOL);
$kernel = new Kernel($environment, $debug);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
