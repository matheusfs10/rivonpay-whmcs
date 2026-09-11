<?php
/**
 * Fase 1 - validacao isolada da API RivonPay (sem WHMCS).
 *
 * Uso:
 *   RIVONPAY_PK=... RIVONPAY_SK=... php tests/rivonpay_test.php
 *   ou preencha tests/.env (nao versionado) e rode: php tests/rivonpay_test.php
 *
 * Consultar uma cobranca ja criada (para descobrir o status de "pago"):
 *   php tests/rivonpay_test.php <id-da-transacao>
 *
 * Nunca coloque chaves neste arquivo - ele e versionado.
 */

define('API_BASE', 'https://api.rivonpay.com.br');

// ---------------------------------------------------------------- utilidades

function loadEnvFile($path)
{
    if (!is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        list($k, $v) = explode('=', $line, 2);
        $k = trim($k);
        $v = trim(trim($v), "\"'");
        if (getenv($k) === false) {
            putenv($k . '=' . $v);
        }
    }
}

function env($key, $default = null)
{
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

function mask($secret)
{
    $len = strlen($secret);
    if ($len <= 10) {
        return str_repeat('*', $len);
    }
    return substr($secret, 0, 6) . str_repeat('*', 6) . substr($secret, -4);
}

function pretty($json)
{
    $decoded = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return $json;
    }
    return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function hr($title = '')
{
    echo "\n" . str_repeat('=', 72) . "\n";
    if ($title !== '') {
        echo $title . "\n" . str_repeat('=', 72) . "\n";
    }
}

/**
 * Executa uma requisicao e devolve [httpCode, headers, body, erroCurl].
 */
function request($method, $path, array $headers, $body = null)
{
    $ch = curl_init(API_BASE . $path);
    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => $headers,
    ));
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($raw === false) {
        return array(0, '', '', $err);
    }
    return array($code, substr($raw, 0, $size), substr($raw, $size), $err);
}

// ------------------------------------------------------------------ execucao

loadEnvFile(__DIR__ . '/.env');

$pk = env('RIVONPAY_PK');
$sk = env('RIVONPAY_SK');

if ($pk === null || $sk === null) {
    fwrite(STDERR, "ERRO: defina RIVONPAY_PK e RIVONPAY_SK (variaveis de ambiente ou tests/.env).\n");
    exit(1);
}

echo "RivonPay - validacao da API\n";
echo 'Base .......: ' . API_BASE . "\n";
echo 'Public key .: ' . mask($pk) . "\n";
echo 'Secret key .: ' . mask($sk) . "\n";

// O openapi.json da RivonPay declara:
//   securitySchemes.integracao = http/basic, "Basic base64(publicKey:secret)"
// As demais variantes ficam como fallback caso a conta use outro formato.
$authModes = array(
    'Basic base64(pk:sk)' => 'Basic ' . base64_encode($pk . ':' . $sk),
    'Basic base64(sk:)'   => 'Basic ' . base64_encode($sk . ':'),
    'Basic base64(sk:x)'  => 'Basic ' . base64_encode($sk . ':x'),
    'Basic base64(pk:)'   => 'Basic ' . base64_encode($pk . ':'),
);

// --- modo consulta: php tests/rivonpay_test.php <id> ------------------------
if ($argc > 1) {
    $id = $argv[1];
    hr('GET /v1/transactions/' . $id);
    foreach ($authModes as $label => $authHeader) {
        list($code, $headers, $body, $err) = request(
            'GET',
            '/v1/transactions/' . rawurlencode($id),
            array('Accept: application/json', 'Authorization: ' . $authHeader)
        );
        printf("[%-22s] HTTP %d%s\n", $label, $code, $err ? ' (cURL: ' . $err . ')' : '');
        if ($code >= 200 && $code < 300) {
            echo pretty($body) . "\n";
            $t = json_decode($body, true);
            hr('status = ' . (isset($t['status']) ? $t['status'] : '?'));
            exit(0);
        }
    }
    echo "Nao foi possivel consultar.\n";
    exit(1);
}

// --- modo criacao -----------------------------------------------------------
$docNumber = env('TEST_DOC_NUMBER');
if ($docNumber === null) {
    fwrite(STDERR, "ERRO: defina TEST_DOC_NUMBER (CPF/CNPJ do cliente de teste) em tests/.env.\n");
    exit(1);
}

// Campos obrigatorios segundo o openapi.json: amount + customer{name,email,phone,document{type,number}}
// amount e INTEIRO EM CENTAVOS. Nao existe paymentMethod nem items[].
$payloadArray = array(
    'amount'   => (int) env('TEST_AMOUNT_CENTS', 1000), // 1000 = R$ 10,00
    'customer' => array(
        'name'     => env('TEST_NAME', 'Cliente Teste'),
        'email'    => env('TEST_EMAIL', 'teste@example.com'),
        'phone'    => env('TEST_PHONE', '61999999999'),
        'document' => array(
            'type'   => env('TEST_DOC_TYPE', 'CPF'),
            'number' => $docNumber,
        ),
    ),
    'externalRef' => 'whmcs-fase1-' . date('YmdHis'),
);

// postbackUrl e opcional. Definir TEST_POSTBACK_URL (ex.: uma URL de captura como
// webhook.site) faz a RivonPay entregar o aviso de pagamento la, o que permite
// ver o formato real do payload - nao documentado no openapi.json.
$postback = env('TEST_POSTBACK_URL');
if ($postback !== null) {
    $payloadArray['postbackUrl'] = $postback;
    echo 'Postback ...: ' . $postback . "\n";
}

$payload = json_encode($payloadArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

hr('1) POST /v1/transactions/ - testando modos de autenticacao');
echo "Body enviado:\n" . pretty($payload) . "\n";

$ok      = null;
$created = false;
foreach ($authModes as $label => $authHeader) {
    list($code, $headers, $body, $err) = request(
        'POST',
        '/v1/transactions/',
        array('Content-Type: application/json', 'Accept: application/json', 'Authorization: ' . $authHeader),
        $payload
    );

    printf("\n[%-22s] HTTP %d%s\n", $label, $code, $err ? ' (cURL: ' . $err . ')' : '');
    echo pretty($body) . "\n";

    // 401 = credencial/esquema rejeitado -> tenta o proximo modo.
    // Qualquer outro codigo significa que a AUTENTICACAO PASSOU; o que vier
    // depois e erro de negocio e nao adianta testar outro modo.
    if ($code !== 401) {
        $ok      = array('label' => $label, 'auth' => $authHeader, 'headers' => $headers, 'body' => $body);
        $created = ($code >= 200 && $code < 300);
        echo "\n>>> AUTENTICACAO ACEITA: " . $label . "\n";
        if (!$created) {
            echo ">>> ...mas a cobranca NAO foi criada (HTTP " . $code . ") - erro de negocio, nao de credencial.\n";
        }
        break;
    }
}

if ($ok === null) {
    hr('RESULTADO');
    echo "Todos os modos deram 401. Confira as chaves no painel\n";
    echo "(Integracao > Credenciais / GET /me/api-key) e rode de novo.\n";
    exit(1);
}

if (!$created) {
    hr('RESULTADO');
    echo "As chaves estao corretas. Resolva o erro acima (conta/adquirente) e rode de novo.\n";
    exit(2);
}

hr('2) Headers da resposta aceita');
echo trim($ok['headers']) . "\n";

$created = json_decode($ok['body'], true);
$id      = isset($created['id']) ? $created['id'] : null;

hr('3) Campos relevantes da criacao');
printf("id ............: %s\n", $id ? $id : '(ausente)');
printf("status ........: %s\n", isset($created['status']) ? $created['status'] : '(ausente)');
printf("amount ........: %s\n", isset($created['amount']) ? var_export($created['amount'], true) : '(ausente)');
printf("fee / netAmount: %s / %s\n",
    isset($created['fee']) ? var_export($created['fee'], true) : '?',
    isset($created['netAmount']) ? var_export($created['netAmount'], true) : '?');
printf("pix.qrCode ....: %s\n", isset($created['pix']['qrCode'])
    ? substr($created['pix']['qrCode'], 0, 60) . '... (' . strlen($created['pix']['qrCode']) . ' chars)'
    : '(ausente)');
printf("pix.qrCodeImage: %s\n", isset($created['pix']['qrCodeImage']) && $created['pix']['qrCodeImage'] !== null
    ? substr($created['pix']['qrCodeImage'], 0, 40) . '... (' . strlen($created['pix']['qrCodeImage']) . ' chars)'
    : '(null/ausente)');
printf("pix.expiresAt .: %s\n", isset($created['pix']['expiresAt']) ? $created['pix']['expiresAt'] : '(ausente)');

if ($id === null) {
    echo "\nSem id na resposta - nao e possivel consultar.\n";
    exit(1);
}

hr('4) GET /v1/transactions/{id}');
list($code, $headers, $body, $err) = request(
    'GET',
    '/v1/transactions/' . rawurlencode($id),
    array('Accept: application/json', 'Authorization: ' . $ok['auth'])
);
printf("HTTP %d%s\n", $code, $err ? ' (cURL: ' . $err . ')' : '');
echo pretty($body) . "\n";

$fetched = json_decode($body, true);

hr('RESUMO PARA docs/API_FINDINGS.md');
echo 'Autenticacao ..: ' . $ok['label'] . "\n";
echo 'Status inicial : ' . (isset($created['status']) ? $created['status'] : '?') . "\n";
echo 'Status na GET .: ' . (isset($fetched['status']) ? $fetched['status'] : '?') . "\n";
echo "Copia-e-cola ..: pix.qrCode\n";
echo 'Imagem ........: pix.qrCodeImage'
    . (isset($created['pix']['qrCodeImage']) && $created['pix']['qrCodeImage'] !== null
        ? ''
        : ' (veio null - gerar o QR no navegador)')
    . "\n";
echo "\nPague o Pix acima (ou marque como pago no painel) e rode:\n";
echo '  php tests/rivonpay_test.php ' . $id . "\n";
echo "para descobrir o valor de status equivalente a PAGO.\n";
