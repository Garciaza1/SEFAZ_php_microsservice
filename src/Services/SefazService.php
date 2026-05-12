<?php

namespace App\Services;

use NFePHP\NFe\Make;
use NFePHP\NFe\Tools;
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
            "tpAmb" => $fiscal['environment'],
            "razaosocial" => $fiscal['company_name'],
            "siglaUF" => $fiscal['state'],
            "cnpj" => preg_replace('/[^0-9]/', '', $fiscal['document']),
            "schemes" => "PL_009_V4",
            "versao" => "4.00",
            "tokenIBPT" => "",
            "CSC" => $fiscal['csc'] ?? "",
            "CSCid" => $fiscal['csc_id'] ?? ""
        ];

        // 2. Proteção de Certificado (Lê localmente se for arquivo do R2)
        if (empty($fiscal['certificate_base64']) || empty($fiscal['certificate_pass'])) {
            throw new Exception("Certificado base64 ou senha ausentes no payload.");
        }

        $pfxContent = "";
        $password = $fiscal['certificate_pass'];

        if (strpos($fiscal['certificate_base64'], 'private/tenants') !== false) {
            $caminhoLocal = __DIR__ . '/../../certificado.pfx';
            if (file_exists($caminhoLocal)) {
                $pfxContent = file_get_contents($caminhoLocal);
            } else {
                throw new Exception("Certificado de teste não encontrado em: " . $caminhoLocal);
            }
        } else {
            $pfxContent = base64_decode($fiscal['certificate_base64']);
        }

        $certificate = Certificate::readPfx($pfxContent, $password);
        $this->tools = new Tools(json_encode($config), $certificate);

        $nfe = new Make();

        // 3. IDENTIFICAÇÃO E EMITENTE (Seu código original)
        $std = new \stdClass();
        $std->cUF = $this->getUfCode($fiscal['state']);
        $std->natOp = 'VENDA DE MERCADORIA';
        $std->mod = 55;
        $std->serie = $fiscal['nfe_series'];
        $std->nNF = $fiscal['next_nfe_num'];
        $std->dhEmi = date('Y-m-d\TH:i:sP');
        $std->tpNF = 1;
        $std->idDest = ($fiscal['state'] === $customer['state']) ? 1 : 2;
        $std->cMunFG = $fiscal['city_code_ibge'];
        $std->tpAmb = $fiscal['environment'];
        $nfe->tagide($std);

        $std = new \stdClass();
        $std->CNPJ = preg_replace('/[^0-9]/', '', $fiscal['document']);
        $std->xNome = $fiscal['company_name'];
        if (!empty($fiscal['trade_name'])) $std->xFant = $fiscal['trade_name'];
        $std->IE = preg_replace('/[^0-9]/', '', $fiscal['state_registration']);
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
        if (empty($itens)) {
            $itens[] = ['sku' => '01', 'produto_nome' => 'Servico ou Produto Generico', 'quantidade' => 1, 'preco_unitario' => $payload['total_amount'], 'subtotal' => $payload['total_amount']];
        }

        foreach ($itens as $item) {
            // 1. Dados básicos do produto (TAG <prod>)
            $std = new \stdClass();
            $std->item = $contItem;
            $std->cProd = preg_replace('/[^a-zA-Z0-9]/', '', $item['sku'] ?? '001');
            $std->cEAN = 'SEM GTIN';
            $std->xProd = $item['produto_nome'];
            $std->NCM = '84713012';
            $std->CFOP = ($fiscal['state'] === ($customer['state'] ?? 'SP')) ? '5102' : '6102';
            $std->uCom = 'UN';
            $std->qCom = $this->formatNum($item['quantidade']);
            $std->vUnCom = $this->formatNum($item['preco_unitario']);
            $std->vProd = $this->formatNum($item['subtotal']);
            $std->cEANTrib = 'SEM GTIN';
            $std->uTrib = 'UN';
            $std->qTrib = $this->formatNum($item['quantidade']);
            $std->vUnTrib = $this->formatNum($item['preco_unitario']);
            $std->indTot = 1;
            $nfe->tagprod($std);

            // 2. Inicializa o bloco de impostos do item (TAG <imposto>)
            // Isso DEVE vir logo após o tagprod
            $std = new \stdClass();
            $std->item = $contItem;
            $nfe->tagimposto($std);

            // 3. ICMS para Simples Nacional (TAG <ICMS>)
            $std = new \stdClass();
            $std->item = $contItem;
            $std->orig = 0;
            $std->CSOSN = '102';
            $nfe->tagicmssn($std);

            // 4. PIS (TAG <PIS>) - Obrigatório para não dar erro de inicialização
            $std = new \stdClass();
            $std->item = $contItem;
            $std->CST = '07'; // Isento
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

            $totalProdutos += (float)$item['subtotal'];
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

        // 9. ASSINATURA E GERAÇÃO
        try {
            $xmlNaoAssinado = $nfe->getXML();
            $xmlAssinado = $this->tools->signNFe($xmlNaoAssinado);
            $chaveAcesso = $nfe->getChave();
        } catch (\Exception $e) {
            throw new Exception("Erro ao assinar XML: " . $e->getMessage());
        }

        $protocolo = '1' . date('ymdHis') . rand(10, 99);
        $xmlAutorizado = $this->mockProtocoloSefaz($xmlAssinado, $chaveAcesso, $protocolo);

        $pdfBase64 = "";
        try {
            if (class_exists(Danfe::class)) {
                $danfe = new Danfe($xmlAutorizado);
                $danfe->debugMode(false);
                $danfe->creditsIntegratorFooter('Gerado por: Seu ERP SaaS');
                $pdfBase64 = base64_encode($danfe->render());
            }
        } catch (\Exception $e) {
            // Ignora erro de PDF
        }

        return [
            "xml_gerado" => $xmlAutorizado,
            "pdf_base64" => $pdfBase64,
            "chave_acesso" => $chaveAcesso,
            "recibo" => $protocolo,
            "status" => "autorizada",
            "mensagem" => "Autorizado o uso da NF-e (Simulado)"
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

    private function mockProtocoloSefaz(string $xmlAssinado, string $chave, string $protocolo): string
    {
        $prot = "<protNFe versao=\"4.00\"><infProt><tpAmb>2</tpAmb><verAplic>SP_NFE_PL_009_V4</verAplic><chNFe>{$chave}</chNFe><dhRecbto>" . date('Y-m-d\TH:i:sP') . "</dhRecbto><nProt>{$protocolo}</nProt><digVal>mock</digVal><cStat>100</cStat><xMotivo>Autorizado o uso da NF-e</xMotivo></infProt></protNFe>";
        return str_replace('</NFe>', '</NFe>' . $prot, $xmlAssinado);
    }
}
