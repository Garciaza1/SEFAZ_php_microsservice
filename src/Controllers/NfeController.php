<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\SefazService;
use Exception;

class NfeController
{

    public function handleEmit(Request $request, Response $response, $args)
    {
        $payload = $request->getParsedBody();

        // S3: nunca logar o payload. Ele leva certificate_base64 e certificate_pass já decifrados.

        // Validação estrita do que vem do Go
        if (empty($payload['order_id']) || empty($payload['fiscal_config']) || empty($payload['customer'])) {
            $response->getBody()->write(json_encode(["error" => "Payload incompleto. order_id, fiscal_config e customer são obrigatórios."]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $service = new SefazService();
            $resultado = $service->emitirNota($payload);

            $response->getBody()->write(json_encode([
                "message" => "Requisição processada com sucesso",
                "data" => $resultado
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (\InvalidArgumentException $e) {
            // F5/F6: dado obrigatório ausente/inválido — não chegou à SEFAZ.
            $response->getBody()->write(json_encode([
                "error" => $e->getMessage(),
                "code" => "NFE_VALIDATION"
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
        } catch (Exception $e) {
            $response->getBody()->write(json_encode([
                "error" => "Erro no Gateway Fiscal (NFePHP): " . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}
