<?php


use Divulgueregional\ApiBbPhp\BankingBB;
use Libraries\Gateways\BasePaymentGateway;
use Libraries\Gateways\Contracts\PaymentGateway;

class BancoDoBrasil extends BasePaymentGateway
{

    /** @var BankingBB $bbApi */

    private $bbApi;

    private $bbConfig;

    public function __construct()
    {
        $this->ci = &get_instance();
        $this->ci->load->config('payment_gateways');
        $this->ci->load->model('Os_model');
        $this->ci->load->model('vendas_model');
        $this->ci->load->model('cobrancas_model');
        $this->ci->load->model('mapos_model');
        $this->ci->load->model('email_model');
        $this->ci->load->model('clientes_model');
        $this->ci->load->model('financeiro_model');


        $bbConfig = $this->ci->config->item('payment_gateways')['BancoDoBrasil'];
        $this->bbConfig = $bbConfig;
    }

    protected function gerarCobrancaBoleto($id, $tipo)
    {
        $entity = $this->findEntity($id, $tipo);
        $produtos = $tipo === PaymentGateway::PAYMENT_TYPE_OS
            ? $this->ci->Os_model->getProdutos($id)
            : $this->ci->vendas_model->getProdutos($id);
        $servicos = $tipo === PaymentGateway::PAYMENT_TYPE_OS
            ? $this->ci->Os_model->getServicos($id)
            : [];

        $desconto = [
            $tipo === PaymentGateway::PAYMENT_TYPE_OS
            ? $this->ci->Os_model->getById($id)
            : $this->ci->vendas_model->getById($id)
        ];

        $tipo_desconto = [
            $tipo === PaymentGateway::PAYMENT_TYPE_OS
            ? $this->ci->Os_model->getById($id)
            : $this->ci->vendas_model->getById($id)
        ];

        $totalProdutos = array_reduce(
            $produtos,
            function ($total, $item) {
                return $total + (floatval($item->preco) * intval($item->quantidade));
            },
            0
        );
        $totalServicos = array_reduce(
            $servicos,
            function ($total, $item) {
                return $total + (floatval($item->preco) * intval($item->quantidade));
            },
            0
        );
        $tipoDesconto = array_reduce(
            $tipo_desconto,
            function ($total, $item) {
                return $item->tipo_desconto;
            },
            0
        );
        $totalDesconto = array_reduce(
            $desconto,
            function ($total, $item) {
                return $item->desconto;
            },
            0
        );

        if (empty($entity)) {
            throw new \Exception('OS ou venda não existe!');
        }

        if (($totalProdutos + $totalServicos) <= 0) {
            throw new \Exception('OS ou venda com valor negativo ou zero!');
        }

        if ($err = $this->errosCadastro($entity)) {
            throw new \Exception($err);
        }

        $config = [
            'endPoints' => $this->bbConfig['endPoints'],
            'client_id' => $this->bbConfig['client_id'],
            'client_secret' => $this->bbConfig['client_secret'],
            'application_key' => $this->bbConfig['application_key'],
            'token' => $this->gerarToken(),
        ];

        $nossonumero = '000' . $this->bbConfig['numeroConvenio'] . str_pad($id + 50100, 10, '0', STR_PAD_LEFT);

        $expirationDate = (new DateTime())->add(new DateInterval($this->bbConfig['boleto_expiration']));
        $expirationDate = ($expirationDate->format('d.m.Y'));

        $title = $tipo === PaymentGateway::PAYMENT_TYPE_OS ? "OS$id" : "VENDA$id";

        $emitente = $this->ci->mapos_model->getEmitente();

        $cliente = $this->ci->clientes_model->getById($entity->clientes_id);


        if ($cliente->pessoa_fisica = 0) {
            $tipoPessoa = 2;
        } else {
            $tipoPessoa = 1;
        }

        $data = [
            "numeroConvenio" => $this->bbConfig['numeroConvenio'],
            "numeroCarteira" => 17,
            "numeroVariacaoCarteira" => 35,
            "codigoModalidade" => '01',
            //01- SIMPLES; 04- VINCULADA
            "dataEmissao" => date('d.m.Y'),
            "dataVencimento" => $expirationDate,
            "valorOriginal" => $this->valorTotal($totalProdutos, $totalServicos, $totalDesconto, $tipoDesconto),
            "valorAbatimento" => 0,
            "quantidadeDiasProtesto" => 0,
            "quantidadeDiasNegativacao" => 0,
            "orgaoNegativador" => '',
            "indicadorAceiteTituloVencido" => 'S',
            //S- aceita após o vencimento; N- não aceita após o vencimento
            "numeroDiasLimiteRecebimento" => 30,
            "codigoAceite" => 'A',
            //A - Aceito; N - Não aceito
            "codigoTipoTitulo" => 2,
            //2-fixo
            "descricaoTipoTitulo" => 'DUPLICATA MERCANTIL',
            "indicadorPermissaoRecebimentoParcial" => 'N',
            "numeroTituloBeneficiario" => $title,
            //seu número para identificar o boleto
            "campoUtilizacaoBeneficiario" => '',
            "mensagemBloquetoOcorrencia" => '',
            "numeroTituloCliente" => $nossonumero,
            // "pagador" => [
            //     "tipoInscricao" => $tipoPessoa,
            //     "numeroInscricao" => $this->trataDoc($cliente->documento),
            //     "nome" => $cliente->nomeCliente,
            //     "endereco" => $cliente->rua,
            //     "cep" => str_replace("-", "", $cliente->cep),
            //     "cidade" => $cliente->cidade,
            //     "bairro" => $cliente->bairro,
            //     "uf" => $cliente->estado,
            // ],
            // "beneficiarioFinal" => [
            //     "tipoInscricao" => 2,
            //     "numeroInscricao" => $this->trataDoc($emitente->cnpj),
            //     "nome" => $emitente->nome,
            // ],
            "pagador" => [
                "tipoInscricao" => 1,
                "numeroInscricao" => 96050176876,
                "nome" => 'VALERIO DE AGUIAR ZORZATO',
                "endereco" => 'RUA TESTE COM COMPLEMENTO E NUMERO',
                "cep" => 74715715,
                "cidade" => 'CIDADE DO CLIENTE',
                "bairro" => 'BAIRRO DO CLIENTE',
                "uf" => 'GO',
                "telefone" => 62999999999,
            ],
            "beneficiarioFinal" => [
                "tipoInscricao" => 2,
                "numeroInscricao" => 92862701000158,
                "nome" => 'DOCERIA BARBOSA DE ALMEIDA',
            ],
            "indicadorPix" => 'N', // BOLETO COM QRCODE PIX - S ou N
        ];

        try {
            $BankingBB = new BankingBB($config);

            $registrarBoleto = $BankingBB->registrarBoleto($data);
        } catch (\Exception $e) {
            echo $e->getMessage();
        }

        $status = $registrarBoleto['status'];

        $result = $registrarBoleto['response'];

        if ($this->bbConfig['endPoints'] != 2) {
            $sucessCode = 201;
        } else {
            $sucessCode = 200;
        }

        if ($status != $sucessCode) {
            throw new \Exception('Erro ao chamar BB!');
        }

        $expirationDate = (new DateTime())->add(new DateInterval($this->bbConfig['boleto_expiration']));
        $expirationDate = ($expirationDate->format('Y-m-d'));

        $title = $tipo === PaymentGateway::PAYMENT_TYPE_OS ? "OS #$id" : "Venda #$id";
        $data = [
            'barcode' => $result->linhaDigitavel,
            'link' => '',
            'payment_url' => '',
            'pdf' => '',
            'expire_at' => $expirationDate,
            'charge_id' => $result->numero,
            'status' => 200,
            'total' => getMoneyAsCents($this->valorTotal($totalProdutos, $totalServicos, $totalDesconto, $tipoDesconto)),
            'payment' => 'Boleto',
            'clientes_id' => $entity->idClientes,
            'payment_method' => 'Boleto',
            'payment_gateway' => 'BancoDoBrasil',
            'message' => 'Pagamento referente a ' . $title,
        ];

        if ($id = $this->ci->cobrancas_model->add('cobrancas', $data, true)) {
            $data['idCobranca'] = $id;
            log_info('Cobrança criada com successo. ID: ' . $id);
        } else {
            throw new \Exception('Erro ao salvar cobrança!');
        }

        return $data;
    }

    protected function gerarCobrancaLink($id, $tipo)
    {
        throw new \Exception('Opção não disponivel');
    }

    protected function gerarToken()
    {
        $config = [
            'endPoints' => $this->bbConfig['endPoints'],
            'client_id' => $this->bbConfig['client_id'],
            'client_secret' => $this->bbConfig['client_secret'],
            'application_key' => $this->bbConfig['application_key'],
        ];
        try {
            $BankingBB = new BankingBB($config);
            $token = $BankingBB->gerarToken();
            return ($token);
        } catch (\Exception $e) {
            echo $e->getMessage();
        }
    }

    private function trataDoc($documento)
    {
        $documento = str_replace("/", "", str_replace("-", "", str_replace(".", "", $documento)));

        return $documento;
    }
    private function valorTotal($produtosValor, $servicosValor, $desconto, $tipo_desconto)
    {
        if ($tipo_desconto == "porcento") {
            $def_desconto = $desconto * ($produtosValor + $servicosValor) / 100;
        } elseif ($tipo_desconto == "real") {
            $def_desconto = $desconto;
        } else {
            $def_desconto = 0;
        }

        return ($produtosValor + $servicosValor) - $def_desconto;
    }
}