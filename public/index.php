<?php

use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Controllers\NfeController;

require __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->addErrorMiddleware(true, true, true);

$app->get('/api/v1/health', function (Request $request, Response $response, $args) {
    $payload = json_encode([
        'status' => 'online',
        'service' => 'ERP Fiscal Gateway',
        'nfe_php_installed' => class_exists('\NFePHP\NFe\Make')
    ]);

    $response->getBody()->write($payload);
    return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
});

$app->post('/api/v1/nfe/emit', [NfeController::class, 'handleEmit']);

$app->post('/api/v1/test', function (Request $request, Response $response, $args) {
    $data = $request->getParsedBody();

    $payload = json_encode([
        'message' => 'Microsserviço PHP recebeu a ordem do Go!',
        'dados_recebidos' => $data
    ]);

    $response->getBody()->write($payload);
    return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
});

$app->run();
