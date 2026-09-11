<?php
/**
 * Gateway de pagamento Pix RivonPay para WHMCS.
 *
 * Gera a cobranca via POST /v1/transactions/ e exibe QR Code + copia-e-cola
 * na fatura. A baixa automatica acontece em callback/rivonpay.php.
 *
 * Referencia da API: docs/API_FINDINGS.md
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

// Guardas porque o callback inclui este arquivo (require_once) e o WHMCS tambem
// pode carrega-lo no mesmo request.
if (!defined('RIVONPAY_API_BASE')) {
    define('RIVONPAY_API_BASE', 'https://api.rivonpay.com.br');
}
if (!defined('RIVONPAY_TABLE')) {
    define('RIVONPAY_TABLE', 'mod_rivonpay');
}
if (!defined('RIVONPAY_LINK_TABLE')) {
    define('RIVONPAY_LINK_TABLE', 'mod_rivonpay_links');
}
if (!defined('RIVONPAY_MIN_CENTS')) {
    define('RIVONPAY_MIN_CENTS', 100); // API rejeita abaixo de R$ 1,00 com 422
}

// ---------------------------------------------------------------- WHMCS meta

function rivonpay_MetaData()
{
    return array(
        'DisplayName'                 => 'RivonPay Pix',
        'APIVersion'                  => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage'            => false,
    );
}

/**
 * Etiqueta de secao na tela de configuracao.
 *
 * Vai dentro da Description de um campo real, e nao num campo proprio: o WHMCS
 * IGNORA campos com Type "System" que so tenham Description - eles nao chegam a
 * virar linha na tabela. Ja a Description dos campos normais e renderizada como
 * HTML, e e por isso que a formatacao mora aqui.
 */
function rivonpay_tag($texto)
{
    return '<span style="display:inline-block;padding:2px 8px;margin-right:6px;'
        . 'border-radius:4px;background:#e6f6f4;color:#12796f;font-size:11px;'
        . 'font-weight:700;letter-spacing:.05em;text-transform:uppercase;">'
        . $texto . '</span>';
}

/**
 * Marca da RivonPay, em escala pequena, para a primeira linha da configuracao.
 */
function rivonpay_brand()
{
    return '<img src="https://app.rivonpay.com.br/logo.svg" alt="" width="16" height="16" '
        . 'style="vertical-align:-3px;margin-right:5px;">'
        . '<strong style="color:#12796f;">RivonPay</strong>';
}

function rivonpay_hint($texto)
{
    return '<span style="color:#7a8296;">' . $texto . '</span>';
}

function rivonpay_config()
{
    return array(
        'FriendlyName' => array(
            'Type'  => 'System',
            'Value' => 'RivonPay Pix',
        ),

        'publicKey' => array(
            'FriendlyName' => 'Public Key',
            'Type'         => 'text',
            'Size'         => '60',
            'Description'  => rivonpay_tag('Credenciais') . rivonpay_brand()
                . rivonpay_hint(' &middot; painel &rsaquo; Integração. Começa com <code>rvp_pk_</code>.'),
        ),
        'secretKey' => array(
            'FriendlyName' => 'Secret Key',
            'Type'         => 'password',
            'Size'         => '60',
            'Description'  => rivonpay_hint('Começa com <code>rvp_sk_</code>. Enviada como '
                . '<code>Basic base64(pk:sk)</code> e nunca aparece nos logs.'),
        ),

        'taxIdCustomField' => array(
            'FriendlyName' => 'Campo personalizado CPF/CNPJ',
            'Type'         => 'text',
            'Size'         => '40',
            'Default'      => 'CPF/CNPJ',
            'Description'  => rivonpay_tag('Dados do cliente')
                . rivonpay_hint('A RivonPay exige CPF ou CNPJ em toda cobrança. Este campo é '
                    . 'consultado quando o cliente não tem o documento no cadastro padrão do '
                    . 'WHMCS. Sem nenhum dos dois, a fatura exibe um aviso pedindo para '
                    . 'completar o cadastro.'),
        ),

        'splitEnabled' => array(
            'FriendlyName' => 'Ativar split',
            'Type'         => 'yesno',
            'Description'  => rivonpay_tag('Split de valores')
                . rivonpay_hint('Opcional. Repassa parte de <strong>todo</strong> pagamento '
                    . 'recebido por este gateway a outro recebedor. Deixe desligado para '
                    . 'receber o valor integral.'),
        ),
        'splitRecipientId' => array(
            'FriendlyName' => 'Recebedor',
            'Type'         => 'text',
            'Size'         => '46',
            'Description'  => rivonpay_hint('UUID do recebedor, copiado do painel da RivonPay. '
                . 'Não há API para consultar essa lista.'),
        ),
        'splitType' => array(
            'FriendlyName' => 'Tipo de divisão',
            'Type'         => 'dropdown',
            'Options'      => array(
                'PERCENTAGE' => 'Porcentagem (%)',
                'FIXED'      => 'Valor fixo (R$)',
            ),
            'Default'      => 'PERCENTAGE',
        ),
        'splitValue' => array(
            'FriendlyName' => 'Quanto repassar',
            'Type'         => 'text',
            'Size'         => '14',
            'Description'  => rivonpay_hint('Informe no formato natural: <strong>10</strong> para '
                . '10%, ou <strong>5,00</strong> para R$ 5,00 — a conversão para a unidade da API '
                . 'é feita pelo módulo.<br>O split incide sobre o <strong>valor líquido</strong>, '
                . 'já descontada a taxa da RivonPay.'),
        ),

        'debugLog' => array(
            'FriendlyName' => 'Log detalhado',
            'Type'         => 'yesno',
            'Description'  => rivonpay_tag('Diagnóstico')
                . rivonpay_hint('Registra cada chamada em <em>Utilitários &rsaquo; Logs &rsaquo; '
                    . 'Gateway Log</em>, com a chave secreta mascarada. Ligue durante os testes.'),
        ),
    );
}

// ------------------------------------------------------------------- suporte

/**
 * Cria a tabela de apoio na primeira execucao.
 */
/**
 * Colunas que o modulo usa. Se qualquer uma faltar, o INSERT falha.
 */
function rivonpay_requiredColumns()
{
    return array(
        'id', 'invoiceid', 'transid', 'qrcode', 'qrcodeimage',
        'status', 'amount', 'expires_at', 'created_at', 'updated_at',
    );
}

function rivonpay_createTable()
{
    Capsule::schema()->create(RIVONPAY_TABLE, function ($table) {
        $table->increments('id');
        $table->integer('invoiceid')->index();
        $table->string('transid', 64)->index();
        $table->text('qrcode')->nullable();
        $table->text('qrcodeimage')->nullable();
        $table->string('status', 32)->default('PENDING');
        $table->integer('amount')->default(0); // centavos
        $table->dateTime('expires_at')->nullable();
        $table->timestamps();
    });
}

/**
 * Garante a tabela de apoio NO LAYOUT ATUAL.
 *
 * Nao basta perguntar hasTable(): instalacoes que rodaram versoes anteriores do
 * modulo tem uma tabela mod_rivonpay com outras colunas. O nome existe, a criacao
 * e pulada, e todo INSERT falha silenciosamente - sem gravacao nao ha
 * reaproveitamento, e cada recarregamento da fatura gera uma cobranca nova.
 *
 * Quando o layout nao bate, a tabela antiga e preservada com outro nome em vez de
 * removida: os dados sao efemeros, mas descartar dados de pagamento sem deixar
 * copia nao e aceitavel.
 */
function rivonpay_ensureTable()
{
    try {
        if (!Capsule::schema()->hasTable(RIVONPAY_TABLE)) {
            rivonpay_createTable();
            return true;
        }

        $missing = array();
        foreach (rivonpay_requiredColumns() as $column) {
            if (!Capsule::schema()->hasColumn(RIVONPAY_TABLE, $column)) {
                $missing[] = $column;
            }
        }

        if (empty($missing)) {
            return true;
        }

        $legacy = RIVONPAY_TABLE . '_legacy_' . date('YmdHis');
        Capsule::schema()->rename(RIVONPAY_TABLE, $legacy);
        rivonpay_createTable();

        if (function_exists('logModuleCall')) {
            logModuleCall(
                'rivonpay',
                'migracao-tabela',
                array('colunas_faltando' => $missing),
                'Tabela antiga preservada como ' . $legacy . '; ' . RIVONPAY_TABLE . ' recriada no layout atual.'
            );
        }

        return true;
    } catch (Exception $e) {
        // Sem tabela nao ha reaproveitamento de cobranca - o modulo segue
        // funcionando, mas o auto-reload fica desligado (ver rivonpay_renderPix).
        if (function_exists('logModuleCall')) {
            logModuleCall('rivonpay', 'ensureTable-FALHOU', RIVONPAY_TABLE, $e->getMessage());
        }
        return false;
    }
}

/**
 * Tabela dos tokens de link. Um token POR FATURA, nao por cobranca.
 *
 * A distincao importa: cobrancas expiram em ~1h e sao regeradas, entao um token
 * preso a uma cobranca viraria link morto na caixa de entrada do cliente. O
 * token acompanha a fatura e sobrevive a quantas cobrancas forem necessarias.
 */
function rivonpay_ensureLinkTable()
{
    try {
        if (Capsule::schema()->hasTable(RIVONPAY_LINK_TABLE)) {
            return true;
        }

        Capsule::schema()->create(RIVONPAY_LINK_TABLE, function ($table) {
            $table->increments('id');
            $table->integer('invoiceid')->unique();
            $table->string('token', 64)->unique();
            $table->dateTime('created_at')->nullable();
        });

        return true;
    } catch (Exception $e) {
        if (function_exists('logModuleCall')) {
            logModuleCall('rivonpay', 'ensureLinkTable-FALHOU', RIVONPAY_LINK_TABLE, $e->getMessage());
        }
        return false;
    }
}

/**
 * Token aleatorio da fatura, criado na primeira vez e reaproveitado depois.
 *
 * Sao 32 bytes de random_bytes() em hex (256 bits), e nao um UUID: UUIDv4 tem
 * 122 bits e costuma ser gerado por funcoes sem garantia criptografica. Aqui o
 * token e a unica credencial do link, entao vale usar a fonte forte.
 *
 * Devolve null se nao for possivel gravar - quem chama deve tratar.
 */
function rivonpay_invoiceToken($invoiceId)
{
    $invoiceId = (int) $invoiceId;

    if (!rivonpay_ensureLinkTable()) {
        return null;
    }

    try {
        $existing = Capsule::table(RIVONPAY_LINK_TABLE)->where('invoiceid', $invoiceId)->value('token');
        if (!empty($existing)) {
            return $existing;
        }

        $token = bin2hex(random_bytes(32));

        try {
            Capsule::table(RIVONPAY_LINK_TABLE)->insert(array(
                'invoiceid'  => $invoiceId,
                'token'      => $token,
                'created_at' => date('Y-m-d H:i:s'),
            ));
            return $token;
        } catch (Exception $e) {
            // Corrida: outro processo criou o token entre o SELECT e o INSERT.
            // O indice unico em invoiceid garante que so um vence; relemos o dele.
            $existing = Capsule::table(RIVONPAY_LINK_TABLE)->where('invoiceid', $invoiceId)->value('token');
            return !empty($existing) ? $existing : null;
        }
    } catch (Exception $e) {
        if (function_exists('logModuleCall')) {
            logModuleCall('rivonpay', 'invoiceToken-FALHOU', $invoiceId, $e->getMessage());
        }
        return null;
    }
}

/**
 * Fatura correspondente a um token de link, ou null.
 */
function rivonpay_invoiceIdForToken($token)
{
    $token = (string) $token;

    // Formato fixo: 64 hex. Descarta lixo antes de ir ao banco.
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    try {
        $id = Capsule::table(RIVONPAY_LINK_TABLE)->where('token', $token)->value('invoiceid');
        return $id ? (int) $id : null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * URL completa da pagina de pagamento. O ID da fatura nao aparece na URL.
 */
function rivonpay_paymentUrl($invoiceId, array $params)
{
    $token = rivonpay_invoiceToken($invoiceId);
    if ($token === null) {
        return '';
    }

    $base = isset($params['systemurl']) ? $params['systemurl'] : '';
    if ($base === '') {
        $base = (string) Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->value('value');
    }

    return rtrim($base, '/') . '/rivonpix.php?token=' . $token;
}

/**
 * Status que significam pagamento liquidado.
 *
 * Era um campo de configuracao; virou constante depois que o ciclo foi
 * confirmado contra a API real (PENDING -> PAID) e o webhook mostrou o mesmo
 * vocabulario. Se a RivonPay introduzir outro status de liquidacao, e aqui que
 * se acrescenta - e so depois de observa-lo numa cobranca realmente paga.
 *
 * Errar para menos e seguro (fatura fica em aberto); errar para mais credita
 * fatura sem dinheiro.
 */
function rivonpay_paidStatuses()
{
    return array('PAID');
}

/**
 * Monta o campo `split` do corpo da cobranca a partir da configuracao.
 *
 * CUIDADO COM AS UNIDADES. O openapi.json declara `value` apenas como integer,
 * mas a unidade muda conforme o amountType:
 *
 *   FIXED       -> centavos              (500  = R$ 5,00)
 *   PERCENTAGE  -> centesimos de por cento (1000 = 10%)
 *
 * Quem manda 10 esperando 10% repassa 0,1% e recebe 201 Created, sem erro
 * nenhum. Por isso o admin informa no formato natural ("10" ou "5,00") e a
 * conversao acontece aqui, num lugar so.
 *
 * Devolve null quando o split esta desligado ou mal configurado - nesse caso a
 * cobranca segue sem split, o que e preferivel a dividir errado.
 */
function rivonpay_splitFor(array $params)
{
    if (empty($params['splitEnabled'])) {
        return null;
    }

    $descarta = function ($motivo) use ($params) {
        if (function_exists('logModuleCall')) {
            logModuleCall('rivonpay', 'split-ignorado', array(
                'recebedor' => isset($params['splitRecipientId']) ? $params['splitRecipientId'] : '',
                'tipo'      => isset($params['splitType']) ? $params['splitType'] : '',
                'valor'     => isset($params['splitValue']) ? $params['splitValue'] : '',
            ), $motivo);
        }
        return null;
    };

    $recipient = trim((string) $params['splitRecipientId']);
    if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $recipient)) {
        return $descarta('Split ligado, mas o ID do recebedor nao e um UUID valido.');
    }

    // Tolerante ao formato do dropdown: algumas versoes do WHMCS devolvem a
    // chave da opcao, outras o rotulo exibido.
    $tipoBruto = strtoupper((string) $params['splitType']);
    if (strpos($tipoBruto, 'PERCENT') !== false || strpos($tipoBruto, 'PORCENT') !== false || strpos($tipoBruto, '%') !== false) {
        $tipo = 'PERCENTAGE';
    } elseif (strpos($tipoBruto, 'FIX') !== false || strpos($tipoBruto, 'R$') !== false) {
        $tipo = 'FIXED';
    } else {
        return $descarta('Tipo de divisao nao reconhecido: ' . $tipoBruto);
    }

    // Aceita virgula decimal, como se escreve em portugues.
    $bruto = str_replace(',', '.', trim((string) $params['splitValue']));
    if ($bruto === '' || !is_numeric($bruto)) {
        return $descarta('Valor do split vazio ou nao numerico.');
    }

    $numero = (float) $bruto;
    if ($numero <= 0) {
        return $descarta('Valor do split precisa ser maior que zero.');
    }

    if ($tipo === 'PERCENTAGE') {
        if ($numero > 100) {
            return $descarta('Percentual de split maior que 100%.');
        }
        $value = (int) round($numero * 100); // 10 -> 1000 centesimos de %
    } else {
        $value = (int) round($numero * 100); // 5.00 -> 500 centavos
    }

    if ($value <= 0) {
        return $descarta('Valor do split arredondou para zero.');
    }

    return array(array(
        'recipientSplitId' => $recipient,
        'amountType'       => $tipo,
        'value'            => $value,
    ));
}

/**
 * Mascara a chave secreta no Gateway Log.
 */
function rivonpay_maskValues(array $params)
{
    $mask = array();
    if (!empty($params['secretKey'])) {
        $mask[] = $params['secretKey'];
    }
    if (!empty($params['publicKey'])) {
        $mask[] = $params['publicKey'];
    }
    return $mask;
}

/**
 * Chamada HTTP a API. Devolve array com code/body/decoded/error.
 *
 * Autenticacao: Basic base64(publicKey:secretKey) - confirmado contra a API,
 * qualquer outro esquema responde 401 (ver docs/API_FINDINGS.md secao 1).
 */
function rivonpay_request(array $params, $method, $path, array $body = null)
{
    $auth = base64_encode($params['publicKey'] . ':' . $params['secretKey']);

    $headers = array(
        'Accept: application/json',
        'Authorization: Basic ' . $auth,
    );
    $encoded = null;
    if ($body !== null) {
        $encoded   = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
    }

    $ch = curl_init(RIVONPAY_API_BASE . $path);
    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => $headers,
    ));
    if ($encoded !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
    }

    $raw   = curl_exec($ch);
    $error = curl_error($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = array(
        'code'    => $code,
        'body'    => ($raw === false) ? '' : $raw,
        'decoded' => ($raw === false) ? null : json_decode($raw, true),
        'error'   => $error,
    );

    if (!empty($params['debugLog']) && function_exists('logModuleCall')) {
        logModuleCall(
            'rivonpay',
            $method . ' ' . $path,
            $encoded !== null ? $encoded : '(sem corpo)',
            $result['body'] !== '' ? $result['body'] : ('cURL: ' . $error),
            $result['decoded'],
            rivonpay_maskValues($params)
        );
    }

    return $result;
}

/**
 * Extrai a mensagem de erro de uma resposta.
 *
 * Atencao: "details" e polimorfico - array de {path,message} no ValidationError,
 * string no AcquirerUnavailable. Tratar os dois evita "array to string conversion".
 */
function rivonpay_errorMessage(array $response)
{
    $decoded = $response['decoded'];

    if (!is_array($decoded)) {
        return $response['error'] !== ''
            ? 'Falha de conexao com a RivonPay.'
            : 'Resposta inesperada da RivonPay (HTTP ' . $response['code'] . ').';
    }

    $message = isset($decoded['message']) ? $decoded['message'] : 'Erro desconhecido.';

    if (isset($decoded['details'])) {
        if (is_array($decoded['details'])) {
            $parts = array();
            foreach ($decoded['details'] as $detail) {
                if (is_array($detail)) {
                    $parts[] = trim((isset($detail['path']) ? $detail['path'] . ': ' : '')
                        . (isset($detail['message']) ? $detail['message'] : ''));
                } else {
                    $parts[] = (string) $detail;
                }
            }
            $message .= ' (' . implode('; ', $parts) . ')';
        } else {
            $message .= ' (' . $decoded['details'] . ')';
        }
    }

    return $message;
}

/**
 * Descobre o CPF/CNPJ do cliente: campo padrao do WHMCS e, se vazio,
 * o campo personalizado configurado.
 */
function rivonpay_documentFor(array $params, array $client)
{
    $raw = '';
    if (!empty($client['tax_id'])) {
        $raw = $client['tax_id'];
    }

    if ($raw === '' && !empty($params['taxIdCustomField'])) {
        try {
            $value = Capsule::table('tblcustomfieldsvalues')
                ->join('tblcustomfields', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
                ->where('tblcustomfields.type', 'client')
                ->where('tblcustomfields.fieldname', 'like', '%' . $params['taxIdCustomField'] . '%')
                ->where('tblcustomfieldsvalues.relid', $client['userid'])
                ->value('tblcustomfieldsvalues.value');
            if (!empty($value)) {
                $raw = $value;
            }
        } catch (Exception $e) {
            // segue sem o campo personalizado
        }
    }

    $digits = preg_replace('/\D/', '', (string) $raw);

    if ($digits === '') {
        return null;
    }

    return array(
        'type'   => strlen($digits) > 11 ? 'CNPJ' : 'CPF',
        'number' => $digits,
    );
}

/**
 * Mensagem de erro exibida na fatura.
 */
function rivonpay_notice($text, $isError = true)
{
    $color  = $isError ? '#b3261e' : '#1b5e20';
    $bg     = $isError ? '#fdecea' : '#e8f5e9';
    return '<div style="max-width:420px;margin:16px auto;padding:14px 16px;border-radius:8px;'
        . 'background:' . $bg . ';color:' . $color . ';font-size:14px;line-height:1.5;text-align:left;">'
        . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
        . '</div>';
}

/**
 * Busca uma cobranca reaproveitavel para a fatura (pendente e nao expirada).
 */
function rivonpay_reusableTransaction($invoiceId, $amountCents)
{
    try {
        $row = Capsule::table(RIVONPAY_TABLE)
            ->where('invoiceid', $invoiceId)
            ->where('amount', $amountCents)
            ->whereIn('status', array('PENDING', 'pending'))
            ->orderBy('id', 'desc')
            ->first();
    } catch (Exception $e) {
        return null;
    }

    if (!$row) {
        return null;
    }

    // Trava contra criacao em rajada: se a cobranca acabou de ser criada,
    // reaproveita sem depender da leitura do vencimento. Sem isso, qualquer
    // problema na interpretacao de expires_at vira uma cobranca nova por
    // recarregamento de pagina.
    if (!empty($row->created_at) && (time() - strtotime($row->created_at)) < 180) {
        return $row;
    }

    // Expirada? Deixa criar outra.
    if (!empty($row->expires_at) && strtotime($row->expires_at) <= time() + 60) {
        return null;
    }

    return $row;
}

/**
 * HTML do QR Code + copia-e-cola.
 *
 * $allowAutoReload so deve ser true quando a cobranca foi gravada em banco. Se
 * nao foi, recarregar a pagina criaria uma cobranca nova a cada ciclo - foi
 * exatamente assim que uma fatura acabou com 4 Pix abertos.
 */
function rivonpay_renderPix($qrCode, $qrCodeImage, $expiresAt, $amountFormatted, $allowAutoReload = false)
{
    $emv = htmlspecialchars($qrCode, ENT_QUOTES, 'UTF-8');

    // A API entrega um data URI PNG pronto; se vier null, cai no gerador publico
    // a partir do proprio EMV.
    if (!empty($qrCodeImage)) {
        $imgSrc = $qrCodeImage;
    } else {
        $imgSrc = 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&data=' . rawurlencode($qrCode);
    }
    $imgSrc = htmlspecialchars($imgSrc, ENT_QUOTES, 'UTF-8');

    $expiraTexto = '';
    if (!empty($expiresAt)) {
        $ts = strtotime($expiresAt);
        if ($ts) {
            $expiraTexto = 'Este código expira em ' . date('d/m/Y H:i', $ts) . '.';
        }
    }

    $html = '
<div id="rivonpay-box" style="max-width:420px;margin:0 auto;text-align:center;font-family:inherit;">
    <h3 style="margin:0 0 4px;font-size:18px;">Pague com Pix</h3>
    <p style="margin:0 0 16px;color:#555;font-size:14px;">
        Valor: <strong>' . htmlspecialchars($amountFormatted, ENT_QUOTES, 'UTF-8') . '</strong>
    </p>

    <img src="' . $imgSrc . '" alt="QR Code Pix"
         style="width:260px;height:260px;display:block;margin:0 auto 16px;border:1px solid #e0e0e0;border-radius:8px;padding:8px;background:#fff;" />

    <p style="margin:0 0 8px;color:#555;font-size:14px;">
        Abra o app do seu banco, escolha <strong>Pix &gt; Ler QR Code</strong><br>
        ou use o código abaixo.
    </p>

    <textarea id="rivonpay-emv" readonly rows="3"
        style="width:100%;box-sizing:border-box;font-family:monospace;font-size:11px;padding:8px;
               border:1px solid #ccc;border-radius:6px;resize:none;background:#fafafa;">' . $emv . '</textarea>

    <button type="button" id="rivonpay-copy"
        style="margin-top:10px;padding:11px 20px;border:0;border-radius:6px;background:#0b5ed7;color:#fff;
               font-size:15px;font-weight:600;cursor:pointer;width:100%;">
        Copiar código Pix
    </button>

    <p style="margin:14px 0 0;color:#777;font-size:12px;">' . htmlspecialchars($expiraTexto, ENT_QUOTES, 'UTF-8') . '</p>
    <p style="margin:6px 0 0;color:#777;font-size:12px;">
        Assim que o pagamento for confirmado, esta página é atualizada automaticamente.
    </p>
</div>

<script>
(function () {
    var btn = document.getElementById("rivonpay-copy");
    var box = document.getElementById("rivonpay-emv");
    if (!btn || !box) { return; }

    btn.addEventListener("click", function () {
        var done = function () {
            var original = btn.innerHTML;
            btn.innerHTML = "Código copiado!";
            btn.style.background = "#1b5e20";
            setTimeout(function () {
                btn.innerHTML = original;
                btn.style.background = "#0b5ed7";
            }, 2000);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(box.value).then(done, function () {
                box.select(); document.execCommand("copy"); done();
            });
        } else {
            box.select(); document.execCommand("copy"); done();
        }
    });

    // Segundos restantes calculados no servidor: o relogio do visitante pode
    // estar torto, e comparar datas entre as duas pontas daria vencimento errado.
    var restam = RIVONPAY_SEGUNDOS;
    var podeRecarregar = RIVONPAY_RELOAD;

    function mostrarExpirado() {
        var box = document.getElementById("rivonpay-box");
        if (!box) { return; }
        box.innerHTML =
            \'<h3 style="margin:0 0 8px;font-size:18px;">O código Pix expirou</h3>\' +
            \'<p style="margin:0 0 16px;color:#555;font-size:14px;">\' +
            \'Gere um novo código para concluir o pagamento.</p>\' +
            \'<button type="button" id="rivonpay-novo" style="padding:11px 20px;border:0;\' +
            \'border-radius:6px;background:#0b5ed7;color:#fff;font-size:15px;font-weight:600;\' +
            \'cursor:pointer;width:100%;">Gerar novo código</button>\';
        document.getElementById("rivonpay-novo").addEventListener("click", function () {
            window.location.reload();
        });
    }

    if (restam > 0) {
        setTimeout(mostrarExpirado, restam * 1000);

        // Recarrega enquanto a cobranca vale, para refletir a confirmacao do
        // pagamento. Para no vencimento: continuar recarregando geraria uma
        // cobranca nova a cada ciclo numa aba esquecida aberta.
        // So agenda se o proximo ciclo cair com folga ANTES do vencimento.
        // Recarregar depois dele criaria a cobranca nova que queremos evitar.
        if (podeRecarregar && restam > 50) {
            setTimeout(function () { window.location.reload(); }, 45000);
        }
    } else {
        mostrarExpirado();
    }
})();
</script>';

    // Sem persistencia nao ha reaproveitamento, entao recarregar criaria uma
    // cobranca nova a cada ciclo - nesse caso o reload fica desligado.
    $secondsLeft = 0;
    if (!empty($expiresAt)) {
        $ts = strtotime($expiresAt);
        if ($ts) {
            $secondsLeft = max(0, $ts - time());
        }
    }

    $html = str_replace(
        array('RIVONPAY_SEGUNDOS', 'RIVONPAY_RELOAD'),
        array((int) $secondsLeft, $allowAutoReload ? 'true' : 'false'),
        $html
    );

    return $html;
}

// --------------------------------------------------------------- ponto de uso

/**
 * Obtem a cobranca Pix de uma fatura: reaproveita a vigente ou cria uma nova.
 *
 * Usada pelo gateway (rivonpay_link) e pela pagina rivonpix.php, para que as duas
 * sigam exatamente as mesmas regras de reaproveitamento, minimo e tratamento de
 * erro - duplicar isso seria garantir que as duas divergissem com o tempo.
 *
 * Devolve array com 'ok' e, quando ok, transid/qrCode/qrCodeImage/expiresAt/persisted.
 * Quando !ok, 'error' traz mensagem pronta para o cliente final.
 */
function rivonpay_charge(array $params, $invoiceId, $amountCents, array $client)
{
    rivonpay_ensureTable();

    if (empty($params['publicKey']) || empty($params['secretKey'])) {
        return array('ok' => false, 'error' => 'Pagamento via Pix indisponível no momento. (Gateway sem credenciais configuradas.)');
    }

    $invoiceId   = (int) $invoiceId;
    $amountCents = (int) $amountCents;

    if ($amountCents < RIVONPAY_MIN_CENTS) {
        return array('ok' => false, 'error' => 'O valor mínimo para pagamento via Pix é R$ 1,00.');
    }

    // Reaproveita a cobranca ainda valida em vez de criar outra a cada F5.
    $existing = rivonpay_reusableTransaction($invoiceId, $amountCents);
    if ($existing) {
        return array(
            'ok'          => true,
            'transid'     => $existing->transid,
            'qrCode'      => $existing->qrcode,
            'qrCodeImage' => $existing->qrcodeimage,
            'expiresAt'   => $existing->expires_at,
            'persisted'   => true, // veio do banco, logo sera reaproveitada no proximo render
        );
    }

    $document = rivonpay_documentFor($params, $client);
    if ($document === null) {
        return array('ok' => false, 'error' =>
            'Para pagar com Pix é necessário ter o CPF/CNPJ cadastrado. '
            . 'Atualize seus dados no perfil e recarregue esta página.');
    }

    $name = trim($client['firstname'] . ' ' . $client['lastname']);
    if (strlen($name) < 3) {
        $name = 'Cliente ' . $client['userid']; // API exige minimo 3 caracteres
    }

    $body = array(
        'amount'   => $amountCents, // centavos
        'customer' => array(
            'name'     => $name,
            'email'    => $client['email'],
            'phone'    => preg_replace('/\D/', '', $client['phonenumber']),
            'document' => $document,
        ),
        'externalRef' => substr('WHMCS-' . $invoiceId, 0, 120),
        'postbackUrl' => rtrim($params['systemurl'], '/') . '/modules/gateways/callback/rivonpay.php',
    );

    // A validade nao e enviada: quem decide e a plataforma, e o vencimento real
    // vem em pix.expiresAt na resposta. Mandar expiresInSeconds so criaria a
    // chance de o admin configurar um valor diferente do que a API aplica.

    $split = rivonpay_splitFor($params);
    if ($split !== null) {
        $body['split'] = $split;
    }

    $response = rivonpay_request($params, 'POST', '/v1/transactions/', $body);

    // 502 AcquirerUnavailable e transitorio e acontece em producao - tratar sem
    // quebrar a fatura (ver docs/API_FINDINGS.md secao 7).
    if ($response['code'] === 502) {
        return array('ok' => false, 'error' =>
            'Não foi possível gerar o Pix agora. Isso costuma ser temporário — '
            . 'recarregue a página em alguns instantes para tentar de novo.');
    }

    if ($response['code'] < 200 || $response['code'] >= 300) {
        return array('ok' => false, 'error' => 'Não foi possível gerar o Pix: ' . rivonpay_errorMessage($response));
    }

    $tx = $response['decoded'];
    if (!is_array($tx) || empty($tx['id']) || empty($tx['pix']['qrCode'])) {
        return array('ok' => false, 'error' => 'A RivonPay respondeu sem o código Pix. Tente novamente em instantes.');
    }

    $persisted = false;
    try {
        Capsule::table(RIVONPAY_TABLE)->insert(array(
            'invoiceid'   => $invoiceId,
            'transid'     => $tx['id'],
            'qrcode'      => $tx['pix']['qrCode'],
            'qrcodeimage' => isset($tx['pix']['qrCodeImage']) ? $tx['pix']['qrCodeImage'] : null,
            'status'      => isset($tx['status']) ? $tx['status'] : 'PENDING',
            'amount'      => $amountCents,
            'expires_at'  => isset($tx['pix']['expiresAt'])
                ? date('Y-m-d H:i:s', strtotime($tx['pix']['expiresAt']))
                : null,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ));
        $persisted = true;
    } catch (Exception $e) {
        // O QR ja existe na RivonPay; nao impedir o pagamento por falha de gravacao.
        // Mas isso PRECISA aparecer no log mesmo sem debug: sem gravacao nao ha
        // reaproveitamento, e cada render passa a gerar uma cobranca nova.
        if (function_exists('logModuleCall')) {
            logModuleCall(
                'rivonpay',
                'db-insert-FALHOU',
                array('invoiceid' => $invoiceId, 'transid' => $tx['id']),
                $e->getMessage(),
                null,
                rivonpay_maskValues($params)
            );
        }
    }

    return array(
        'ok'          => true,
        'transid'     => $tx['id'],
        'qrCode'      => $tx['pix']['qrCode'],
        'qrCodeImage' => isset($tx['pix']['qrCodeImage']) ? $tx['pix']['qrCodeImage'] : null,
        'expiresAt'   => isset($tx['pix']['expiresAt']) ? $tx['pix']['expiresAt'] : null,
        'persisted'   => $persisted,
    );
}

/**
 * Ponto de entrada do WHMCS: renderiza o Pix dentro da fatura.
 */
function rivonpay_link($params)
{
    if (strtoupper($params['currency']) !== 'BRL') {
        return rivonpay_notice('O Pix só aceita faturas em BRL. Esta fatura está em ' . $params['currency'] . '.');
    }

    $charge = rivonpay_charge(
        $params,
        (int) $params['invoiceid'],
        (int) round(((float) $params['amount']) * 100),
        $params['clientdetails']
    );

    if (empty($charge['ok'])) {
        return rivonpay_notice($charge['error']);
    }

    return rivonpay_renderPix(
        $charge['qrCode'],
        $charge['qrCodeImage'],
        $charge['expiresAt'],
        $params['currency'] . ' ' . number_format($params['amount'], 2, ',', '.'),
        $charge['persisted']
    );
}
