<?php

namespace App\Services;

use NFePHP\NFe\Make;
use NFePHP\NFe\Tools;
use NFePHP\NFe\Complements;
use NFePHP\Common\Certificate;
use NFePHP\DA\NFe\Danfe;
use Exception;

class SefazService
{
    private ?Tools $tools = null;

    public function emitirNota(array $payload): array
    {
        $fiscal = $payload['fiscal_config'];
        $customer = $payload['customer'];

        // 1. Configuração Dinâmica
        $config = [
            "atualizacao" => date('Y-m-d H:i:s'),
            "tpAmb" => (int)($fiscal['environment'] ?? 2),
            "razaosocial" => $fiscal['company_name'] ?? '',
            "siglaUF" => $fiscal['state'] ?? 'SP',
            "cnpj" => preg_replace('/[^0-9]/', '', $fiscal['document'] ?? ''),
            "schemes" => "PL_009_V4",
            "versao" => "4.00",
            "tokenIBPT" => "",
            "CSC" => $fiscal['csc'] ?? "",
            "CSCid" => $fiscal['csc_id'] ?? ""
        ];

        // 2. Leitura do Certificado Digital A1
        if (empty($fiscal['certificate_base64']) || empty($fiscal['certificate_pass'])) {
            throw new Exception("Certificado digital ou senha ausentes na configuração fiscal.");
        }

        $pfxContent = base64_decode($fiscal['certificate_base64']);
        if (!$pfxContent) {
            throw new Exception("Falha ao decodificar conteúdo base64 do certificado digital.");
        }

        $password = $fiscal['certificate_pass'];
        try {
            $certificate = Certificate::readPfx($pfxContent, $password);
        } catch (\Exception $e) {
            throw new Exception("Falha ao ler certificado digital A1: " . $e->getMessage());
        }

        $this->tools = new Tools(json_encode($config), $certificate);

        $nfe = new Make();

        $stdInfNFe = new \stdClass();
        $stdInfNFe->versao = '4.00';
        $stdInfNFe->Id = '';
        $stdInfNFe->pk_nItem = null;
        $nfe->taginfNFe($stdInfNFe);

        // 3. IDENTIFICAÇÃO E EMITENTE (Seu código original)
        $stdIde = new \stdClass();
        $stdIde->cUF = $this->getUfCode($fiscal['state']); // GARANTA QUE ISSO NÃO VOLTA NULL
        $stdIde->natOp = 'VENDA DE MERCADORIA';
        $stdIde->mod = 55;
        $stdIde->serie = $fiscal['nfe_series'];
        $stdIde->nNF = $fiscal['next_nfe_num'];
        $stdIde->dhEmi = date('Y-m-d\TH:i:sP');
        $stdIde->tpNF = 1;
        $stdIde->idDest = ($fiscal['state'] === $customer['state']) ? 1 : 2;
        $stdIde->cMunFG = $fiscal['city_code_ibge'];
        $stdIde->tpImp = 1;
        $stdIde->tpEmis = 1;
        $stdIde->tpAmb = $fiscal['environment'];
        $stdIde->finNFe = 1; // 1 = NF-e normal
        $stdIde->indFinal = 1; // 1 = Consumidor Final (obrigatório se a IE do destinatário for 9)
        $stdIde->indPres = 2; // 2 = Operação pela Internet
        $stdIde->procEmi = 0; // 0 = Emissão de NF-e com aplicativo do contribuinte
        $stdIde->verProc = '1.0.0'; // Versão do seu sistema
        $nfe->tagide($stdIde);

        $std = new \stdClass();
        $std->CNPJ = preg_replace('/[^0-9]/', '', $fiscal['document']);
        $std->xNome = $fiscal['company_name'];
        if (!empty($fiscal['trade_name'])) $std->xFant = $fiscal['trade_name'];
        // $std->IE = preg_replace('/[^0-9]/', '', $fiscal['state_registration']);
        $ieLimpa = strtoupper(trim($fiscal['state_registration']));
        if ($ieLimpa === 'ISENTO') {
            $std->IE = 'ISENTO';
        } else {
            $std->IE = preg_replace('/[^0-9]/', '', $fiscal['state_registration']);
        }
        $std->CRT = $fiscal['crt'];
        $nfe->tagemit($std);

        $std = new \stdClass();
        $std->xLgr = $fiscal['street'];
        $std->nro = $fiscal['number'];
        if (!empty($fiscal['complement'])) $std->xCpl = $fiscal['complement'];
        $std->xBairro = $fiscal['district'];
        $std->cMun = $fiscal['city_code_ibge'];
        $std->xMun = $fiscal['city'];
        $std->UF = $fiscal['state'];
        $std->CEP = preg_replace('/[^0-9]/', '', $fiscal['cep']);
        $nfe->tagenderEmit($std);

        // 4. DESTINATÁRIO (CPF/CNPJ e Nome)
        $std = new \stdClass();
        $docCliente = preg_replace('/[^0-9]/', '', $customer['document'] ?? '00000000000');
        if (strlen($docCliente) === 14) {
            $std->CNPJ = $docCliente;
            $std->indIEDest = 9;
        } else {
            $std->CPF = str_pad($docCliente, 11, '0', STR_PAD_LEFT);
            $std->indIEDest = 9;
        }
        $std->xNome = $fiscal['environment'] == 2 ? 'NF-E EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL' : $customer['name'];
        $nfe->tagdest($std);

        // 5. ENDEREÇO DO DESTINATÁRIO (Novo!)
        $std = new \stdClass();
        $std->xLgr = $customer['street'] ?? 'Não informado';
        $std->nro = $customer['number'] ?? 'S/N';
        if (!empty($customer['complement'])) $std->xCpl = $customer['complement'];
        $std->xBairro = $customer['district'] ?? 'Não informado';
        // Se a cidade não tiver IBGE, usamos a do emitente temporariamente para não travar a validação
        $std->cMun = $customer['city_code_ibge'] ?? $fiscal['city_code_ibge'];
        $std->xMun = $customer['city'] ?? 'Não informado';
        $std->UF = $customer['state'] ?? 'SP';
        $std->CEP = preg_replace('/[^0-9]/', '', $customer['cep'] ?? '00000000');
        $nfe->tagenderDest($std);

        // 6. PRODUTOS E IMPOSTOS
        $contItem = 1;
        $totalProdutos = 0.0;

        $itens = !empty($payload['items']) ? $payload['items'] : [];
        $servicos = !empty($payload['services']) ? $payload['services'] : [];

        $todosItens = array_merge($itens, $servicos);

        if (empty($todosItens)) {
            throw new Exception("A nota não pode ser emitida sem itens ou serviços.");
        }

        foreach ($todosItens as $item) {
            // Se o Go não mandar, usamos valores de fallback para não quebrar o motor
            $ncmItem = !empty($item['ncm']) ? preg_replace('/[^0-9]/', '', $item['ncm']) : '00000000';
            $cfopItem = !empty($item['cfop']) ? preg_replace('/[^0-9]/', '', $item['cfop']) : '5102';
            $csosnItem = !empty($item['csosn']) ? preg_replace('/[^0-9]/', '', $item['csosn']) : '102';

            // 1. Dados básicos do produto (TAG <prod>)
            $std = new \stdClass();
            $std->item = $contItem;
            $std->cProd = preg_replace('/[^a-zA-Z0-9]/', '', $item['sku'] ?? '001');
            $std->cEAN = 'SEM GTIN';
            $std->xProd = $item['produto_nome'] ?? $item['name'] ?? 'Produto sem nome';
            $std->NCM = strlen($ncmItem) === 8 ? $ncmItem : '00000000'; // NCM deve ter exatos 8 dígitos
            $std->CFOP = $cfopItem;
            $std->uCom = 'UN';
            $std->qCom = $this->formatNum($item['quantidade'] ?? $item['quantity'] ?? 1);
            $std->vUnCom = $this->formatNum($item['preco_unitario'] ?? $item['unit_price'] ?? 0);
            $std->vProd = $this->formatNum($item['subtotal'] ?? 0);
            $std->cEANTrib = 'SEM GTIN';
            $std->uTrib = 'UN';
            $std->qTrib = $this->formatNum($item['quantidade'] ?? $item['quantity'] ?? 1);
            $std->vUnTrib = $this->formatNum($item['preco_unitario'] ?? $item['unit_price'] ?? 0);
            $std->indTot = 1;
            $nfe->tagprod($std);

            // 2. Inicializa o bloco de impostos do item (TAG <imposto>)
            $std = new \stdClass();
            $std->item = $contItem;
            $nfe->tagimposto($std);

            // 3. ICMS para Simples Nacional (TAG <ICMS>)
            $std = new \stdClass();
            $std->item = $contItem;
            $std->orig = 0; // Você pode trazer a origem do Perfil Fiscal também!
            $std->CSOSN = $csosnItem;
            $nfe->tagicmssn($std);

            // 4. PIS (TAG <PIS>) - Obrigatório para não dar erro de inicialização
            $std = new \stdClass();
            $std->item = $contItem;
            $std->CST = '07'; // Isento (Ajuste se o perfil exigir tributação de PIS)
            $std->vBC = '0.00';
            $std->pPIS = '0.00';
            $std->vPIS = '0.00';
            $nfe->tagpis($std);

            // 5. COFINS (TAG <COFINS>) - Obrigatório
            $std = new \stdClass();
            $std->item = $contItem;
            $std->CST = '07'; // Isento
            $std->vBC = '0.00';
            $std->pCOFINS = '0.00';
            $std->vCOFINS = '0.00';
            $nfe->tagcofins($std);

            $totalProdutos += (float)($item['subtotal'] ?? 0);
            $contItem++;
        }

        // 7. TOTAIS (Agora com Frete e Desconto!)
        $frete = isset($payload['freight']) ? (float)$payload['freight'] : 0.00;
        $desconto = isset($payload['discount']) ? (float)$payload['discount'] : 0.00;
        $valorTotalNF = $totalProdutos + $frete - $desconto;

        $std = new \stdClass();
        $std->vBC = 0.00;
        $std->vICMS = 0.00;
        $std->vICMSDeson = 0.00;
        $std->vFCP = 0.00;
        $std->vBCST = 0.00;
        $std->vST = 0.00;
        $std->vFCPST = 0.00;
        $std->vFCPSTRet = 0.00;
        $std->vProd = $this->formatNum($totalProdutos);
        $std->vFrete = $this->formatNum($frete);
        $std->vSeg = 0.00;
        $std->vDesc = $this->formatNum($desconto);
        $std->vII = 0.00;
        $std->vIPI = 0.00;
        $std->vIPIDevol = 0.00;
        $std->vPIS = 0.00;
        $std->vCOFINS = 0.00;
        $std->vOutro = 0.00;
        $std->vNF = $this->formatNum($valorTotalNF);
        $nfe->tagICMSTot($std);

        $std = new \stdClass();
        $std->modFrete = 9; // 9 = Sem frete embutido na transportadora
        $nfe->tagtransp($std);

        $std = new \stdClass();
        $std->vTroco = 0.00;
        $nfe->tagpag($std);

        $std = new \stdClass();
        $std->indPag = 0;
        $std->tPag = '01'; // 01 = Dinheiro
        $std->vPag = $this->formatNum($valorTotalNF);
        $nfe->tagdetPag($std);

        // 8. INFORMAÇÕES COMPLEMENTARES / OBSERVAÇÕES (Novo!)
        if (!empty($payload['notes'])) {
            $std = new \stdClass();
            $std->infCpl = $payload['notes'];
            $nfe->taginfAdic($std);
        }

        $errosMontagem = $nfe->getErrors();
        if (!empty($errosMontagem)) {
            // Em vez do Fatal Error, o PHP vai devolver um JSON legível pro Go com a tag que falhou!
            throw new Exception("Faltam dados obrigatórios na montagem da NF-e: " . json_encode($errosMontagem));
        }

        // 9. ASSINATURA E GERAÇÃO DO XML
        try {
            $xmlNaoAssinado = $nfe->getXML();
            $xmlAssinado = $this->tools->signNFe($xmlNaoAssinado);
            $chaveAcesso = $nfe->getChave();
        } catch (\Exception $e) {
            throw new Exception("Erro ao assinar XML da NF-e: " . $e->getMessage());
        }

        // 10. ENVIO PARA OS WEBSERVICES DA SEFAZ
        $idLote = str_pad((string)($fiscal['next_nfe_num'] ?? '1'), 15, '0', STR_PAD_LEFT);
        $indSinc = 1; // 1 = Modo Síncrono (Padrão para 1 NF-e no lote na NF-e v4.00)

        try {
            $respEnvioXml = $this->tools->sefazEnviaLote([$xmlAssinado], $idLote, $indSinc);
        } catch (\Exception $e) {
            throw new Exception("Falha de comunicação com a SEFAZ: " . $e->getMessage());
        }

        $xmlEnvioObj = @simplexml_load_string($respEnvioXml);
        if (!$xmlEnvioObj) {
            throw new Exception("Resposta inválida ou vazia recebida da SEFAZ: " . substr((string)$respEnvioXml, 0, 500));
        }

        $cStatLote = (string) $xmlEnvioObj->cStat;
        $xMotivoLote = (string) $xmlEnvioObj->xMotivo;

        $xmlAutorizado = null;
        $protocolo = '';
        $mensagemSefaz = '';

        // Caso 1: Modo Síncrono processado com sucesso pelo lote (cStat = 104)
        if ($cStatLote === '104') {
            $prot = $xmlEnvioObj->protNFe->infProt ?? null;
            if (!$prot) {
                throw new Exception("SEFAZ processou o lote, mas o protocolo não foi retornado: {$xMotivoLote}");
            }
            $cStatNota = (string) $prot->cStat;
            $xMotivoNota = (string) $prot->xMotivo;
            $protocolo = (string) $prot->nProt;
            $mensagemSefaz = "[{$cStatNota}] {$xMotivoNota}";

            if ($cStatNota === '100' || $cStatNota === '150') {
                $xmlAutorizado = Complements::toAuthorize($xmlAssinado, $respEnvioXml);
            } else {
                throw new Exception("SEFAZ Rejeição: {$mensagemSefaz}");
            }
        }
        // Caso 2: Modo Assíncrono - Lote recebido (cStat = 103)
        else if ($cStatLote === '103') {
            $nRec = (string) ($xmlEnvioObj->infRec->nRec ?? '');
            if (empty($nRec)) {
                throw new Exception("SEFAZ retornou lote recebido (103), mas sem número de recibo.");
            }

            $maxTentativas = 4;
            $processado = false;

            for ($i = 0; $i < $maxTentativas; $i++) {
                sleep(2); // Aguarda 2 segundos antes de cada consulta
                try {
                    $respConsultaXml = $this->tools->sefazConsultaRecibo($nRec);
                } catch (\Exception $e) {
                    continue;
                }

                $xmlConsObj = @simplexml_load_string($respConsultaXml);
                if (!$xmlConsObj) continue;

                $cStatCons = (string) $xmlConsObj->cStat;
                if ($cStatCons === '104') {
                    $prot = $xmlConsObj->protNFe->infProt ?? null;
                    if ($prot) {
                        $cStatNota = (string) $prot->cStat;
                        $xMotivoNota = (string) $prot->xMotivo;
                        $protocolo = (string) $prot->nProt;
                        $mensagemSefaz = "[{$cStatNota}] {$xMotivoNota}";

                        if ($cStatNota === '100' || $cStatNota === '150') {
                            $xmlAutorizado = Complements::toAuthorize($xmlAssinado, $respConsultaXml);
                            $processado = true;
                            break;
                        } else {
                            throw new Exception("SEFAZ Rejeição: {$mensagemSefaz}");
                        }
                    }
                } else if ($cStatCons !== '105') { // 105 = Lote em processamento
                    throw new Exception("SEFAZ Consulta Recibo: [{$cStatCons}] " . (string)$xmlConsObj->xMotivo);
                }
            }

            if (!$processado) {
                throw new Exception("Tempo limite esgotado ao aguardar processamento do recibo SEFAZ {$nRec}.");
            }
        }
        // Caso 3: Rejeição direta do lote
        else {
            throw new Exception("SEFAZ Lote Rejeitado: [{$cStatLote}] {$xMotivoLote}");
        }

        // 11. GERAÇÃO DO DANFE EM PDF
        $pdfBase64 = "";
        try {
            if (class_exists(Danfe::class) && !empty($xmlAutorizado)) {
                $danfe = new Danfe($xmlAutorizado);
                $danfe->debugMode(false);
                $danfe->creditsIntegratorFooter('Gerado por: ERP SaaS');
                $pdfBase64 = base64_encode($danfe->render());
            }
        } catch (\Exception $e) {
            error_log("Aviso: Falha ao renderizar PDF do DANFE: " . $e->getMessage());
        }

        return [
            "xml_gerado" => $xmlAutorizado,
            "pdf_base64" => $pdfBase64,
            "chave_acesso" => $chaveAcesso,
            "recibo" => $protocolo,
            "status" => "autorizada",
            "mensagem" => $mensagemSefaz ?: "Autorizado o uso da NF-e"
        ];
    }

    private function getUfCode(string $uf): int
    {
        $ufs = ['RO' => 11, 'AC' => 12, 'AM' => 13, 'RR' => 14, 'PA' => 15, 'AP' => 16, 'TO' => 17, 'MA' => 21, 'PI' => 22, 'CE' => 23, 'RN' => 24, 'PB' => 25, 'PE' => 26, 'AL' => 27, 'SE' => 28, 'BA' => 29, 'MG' => 31, 'ES' => 32, 'RJ' => 33, 'SP' => 35, 'PR' => 41, 'SC' => 42, 'RS' => 43, 'MS' => 50, 'MT' => 51, 'GO' => 52, 'DF' => 53];
        return $ufs[strtoupper($uf)] ?? 35;
    }

    private function formatNum($num): string
    {
        return number_format((float)$num, 2, '.', '');
    }
}
