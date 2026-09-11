<?php
/**
 * Callback do gateway RivonPay Pix.
 *
 * Recebe o postback da RivonPay e da baixa na fatura.
 *
 * SEGURANCA: o conteudo do POST nunca e usado como prova de pagamento. Dele sai
 * apenas o ID da transacao; o status vem sempre de uma reconsulta autenticada a
 * GET /v1/transactions/{id}.
 *
 * Isso nao e preciosismo. Verificado em 2026-09-11: o webhook da RivonPay NAO E
 * ASSINADO - nenhum HMAC, token ou cabecalho de origem, so
 * "user-agent: RivonPay-Webhooks/1.0". Quem descobrir esta URL pode postar
 * {"event":"transaction.paid","data":{"id":"...","status":"PAID"}} a vontade.
 * A reconsulta e a unica coisa que separa um postback forjado de uma fatura
 * baixada sem dinheiro.
 *
 * Formato real do payload (docs/API_FINDINGS.md secao 6):
 *
 *   {"event":"transaction.paid","createdAt":"...",
 *    "data":{"id":"<uuid>","amount":100,"status":"PAID","paidAt":"...",
 *            "endToEndId":"E004...","externalRef":"WHMCS-123"}}
 *
 * O id vem em data.id. Os demais caminhos em rivonpay_extractTransactionId()
 * ficam como tolerancia caso o formato mude; nenhum deles afeta a seguranca,
 * ja que o que sai dali e so um palpite sobre o que consultar na API.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

use WHMCS\Database\Capsule;

$gatewayModuleName = 'rivonpay';
$gatewayParams     = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    http_response_code(503);
    die('Module Not Activated');
}

// O modulo principal traz RIVONPAY_API_BASE, RIVONPAY_TABLE e rivonpay_request().
require_once __DIR__ . '/../rivonpay.php';

/**
 * Procura o id da transacao em qualquer formato de payload plausivel.
 * O webhook da RivonPay nao esta especificado, entao aceitamos varios caminhos.
 */
function rivonpay_extractTransactionId($payload)
{
    if (!is_array($payload)) {
        return null;
    }

    $candidates = array(
        isset($payload['id']) ? $payload['id'] : null,
        isset($payload['transactionId']) ? $payload['transactionId'] : null,
        isset($payload['transaction_id']) ? $payload['transaction_id'] : null,
        isset($payload['data']['id']) ? $payload['data']['id'] : null,
        isset($payload['data']['transactionId']) ? $payload['data']['transactionId'] : null,
        isset($payload['transaction']['id']) ? $payload['transaction']['id'] : null,
        isset($payload['data']['transaction']['id']) ? $payload['data']['transaction']['id'] : null,
        isset($payload['payment']['id']) ? $payload['payment']['id'] : null,
        isset($payload['data']['payment']['id']) ? $payload['data']['payment']['id'] : null,
    );

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && $candidate !== '') {
            return $candidate;
        }
    }

    return null;
}

/**
 * Descobre a fatura: primeiro pelo externalRef gravado na cobranca
 * ("WHMCS-123"), depois pela tabela de apoio.
 */
function rivonpay_resolveInvoiceId(array $transaction, $transactionId)
{
    if (!empty($transaction['externalRef'])
        && preg_match('/^WHMCS-(\d+)$/', $transaction['externalRef'], $m)) {
        return (int) $m[1];
    }

    try {
        $row = Capsule::table(RIVONPAY_TABLE)->where('transid', $transactionId)->first();
        if ($row) {
            return (int) $row->invoiceid;
        }
    } catch (Exception $e) {
        // tabela pode nao existir ainda
    }

    return null;
}

// ------------------------------------------------------------------ execucao

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);

if (!is_array($payload)) {
    // Alguns provedores postam form-encoded em vez de JSON.
    $payload = $_POST;
}

$transactionId = rivonpay_extractTransactionId($payload);

// Permite reconsulta manual: callback/rivonpay.php?id=<uuid>
if ($transactionId === null && !empty($_GET['id'])) {
    $transactionId = $_GET['id'];
}

if ($transactionId === null) {
    logTransaction($gatewayModuleName, $rawBody !== '' ? $rawBody : $_GET, 'Sem ID de transacao no callback');
    http_response_code(400);
    die('Missing transaction id');
}

// --- a unica fonte de verdade: reconsulta autenticada -----------------------
$response = rivonpay_request($gatewayParams, 'GET', '/v1/transactions/' . rawurlencode($transactionId));

if ($response['code'] < 200 || $response['code'] >= 300 || !is_array($response['decoded'])) {
    logTransaction($gatewayModuleName, array(
        'transactionId' => $transactionId,
        'httpCode'      => $response['code'],
        'resposta'      => $response['body'],
    ), 'Falha ao reconsultar a transacao');
    http_response_code(502);
    die('Could not verify transaction');
}

$transaction = $response['decoded'];
$status      = isset($transaction['status']) ? (string) $transaction['status'] : '';

// Confirmado contra a API: PENDING na criacao, PAID apos a liquidacao.
// A lista vive em rivonpay_paidStatuses(), no modulo do gateway.
$paidStatuses = rivonpay_paidStatuses();

$invoiceId = rivonpay_resolveInvoiceId($transaction, $transactionId);

// Mantem a tabela de apoio em dia, pago ou nao.
try {
    Capsule::table(RIVONPAY_TABLE)
        ->where('transid', $transactionId)
        ->update(array('status' => $status, 'updated_at' => date('Y-m-d H:i:s')));
} catch (Exception $e) {
    // nao impede a baixa
}

if (!in_array(strtoupper($status), $paidStatuses, true)) {
    logTransaction($gatewayModuleName, array(
        'transactionId' => $transactionId,
        'invoiceId'     => $invoiceId,
        'status'        => $status,
    ), 'Ainda nao pago (' . $status . ')');
    http_response_code(200);
    die('OK');
}

if ($invoiceId === null) {
    logTransaction($gatewayModuleName, array(
        'transactionId' => $transactionId,
        'externalRef'   => isset($transaction['externalRef']) ? $transaction['externalRef'] : null,
    ), 'Pago, mas sem fatura correspondente');
    http_response_code(404);
    die('Invoice not found');
}

// Valida a fatura e impede baixa duplicada (ambas encerram a execucao se falharem).
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);
checkCbTransID($transactionId);

// Valores vem em centavos.
$amount = isset($transaction['amount']) ? ((int) $transaction['amount']) / 100 : 0;
$fee    = isset($transaction['fee']) ? ((int) $transaction['fee']) / 100 : 0;

addInvoicePayment($invoiceId, $transactionId, $amount, $fee, $gatewayModuleName);

logTransaction($gatewayModuleName, array(
    'transactionId' => $transactionId,
    'invoiceId'     => $invoiceId,
    'status'        => $status,
    'amount'        => $amount,
    'fee'           => $fee,
), 'Successful');

http_response_code(200);
echo 'OK';
