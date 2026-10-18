<?php

use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Controllers\NfeController;

require __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
// Detalhe de erro só em desenvolvimento (APP_DEBUG=1): em produção o stack trace pode carregar
// trechos do payload fiscal. logErrorDetails fica desligado pelo mesmo motivo.
$app->addErrorMiddleware(getenv('APP_DEBUG') === '1', true, false);

// S3: o Go é o único cliente deste serviço. Toda rota fiscal exige o token interno
// compartilhado: INTERNAL_MICROSERVICE_TOKEN (o mesmo valor nos dois serviços), enviado pelo
// Go em "Authorization: Bearer <token>". Sem token configurado o serviço recusa tudo (fail
// closed) em vez de aceitar chamadas sem autenticação.
$requireInternalToken = function (Request $request, \Psr\Http\Server\RequestHandlerInterface $handler) use ($app): Response {
    $expected = (string) getenv('INTERNAL_MICROSERVICE_TOKEN');
    $given = preg_match('/^Bearer\\s+(.+)$/i', $request->getHeaderLine('Authorization'), $m) ? trim($m[1]) : '';

    $deny = function (int $status, string $msg) use ($app): Response {
        $r = $app->getResponseFactory()->createResponse($status);
        $r->getBody()->write(json_encode(['error' => $msg]));
        return $r->withHeader('Content-Type', 'application/json');
    };

    if ($expected === '') {
        error_log('INTERNAL_MICROSERVICE_TOKEN não configurado: recusando requisição fiscal');
        return $deny(503, 'Serviço fiscal sem autenticação configurada');
    }
    if ($given === '' || !hash_equals($expected, $given)) {
        return $deny(401, 'Não autorizado');
    }
    return $handler->handle($request);
};

//OPENSSL_ENABLE_SHA1_SIGNATURES=1 php -S 0.0.0.0:8000 -t public
$app->get('/api/v1/health', function (Request $request, Response $response, $args) {
    $payload = json_encode([
        'status' => 'online',
        'service' => 'ERP Fiscal Gateway',
        'nfe_php_installed' => class_exists('\NFePHP\NFe\Make')
    ]);

    $response->getBody()->write($payload);
    return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
});

$app->post('/api/v1/nfe/emit', [NfeController::class, 'handleEmit'])->add($requireInternalToken);

// Rota /api/v1/test removida (S3): ecoava o payload recebido, que carrega o certificado A1.

$app->run();
