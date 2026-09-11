<?php
/**
 * Pagina de pagamento Pix da RivonPay.
 *
 *   https://seu-whmcs.com/rivonpix.php?token=<64 hex>
 *
 * Vai na RAIZ do WHMCS, ao lado do init.php.
 *
 * ACESSO: o ID da fatura nunca aparece na URL. O que circula e um token
 * aleatorio de 256 bits, guardado em mod_rivonpay_links e emitido POR FATURA -
 * nao por cobranca, porque cobrancas expiram em ~1h e sao regeradas, o que
 * transformaria o link do e-mail em link morto.
 *
 * Sao aceitos tres modos:
 *
 *   1. ?token=...   - qualquer pessoa com o link, sem login. Formas de obter:
 *        - admin logado:  /rivonpix.php?invoiceid=177084&link=1
 *        - automatico:    includes/hooks/rivonpix.php poe {$rivonpix_link}
 *                         nos e-mails de fatura
 *        - em codigo:     rivonpay_paymentUrl($id, getGatewayVariables('rivonpay'))
 *   2. ?invoiceid=N - cliente logado que seja dono da fatura.
 *   3. ?invoiceid=N - admin logado, para suporte.
 *
 * Revogar um link e apagar a linha correspondente em mod_rivonpay_links; os
 * demais continuam valendo, e trocar a secret key nao derruba nenhum.
 *
 * A cobranca em si reutiliza rivonpay_charge(), a mesma funcao do gateway, para
 * que as duas telas sigam identicas regras de reaproveitamento e minimo.
 */

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/includes/gatewayfunctions.php';
require_once __DIR__ . '/includes/invoicefunctions.php';
require_once __DIR__ . '/modules/gateways/rivonpay.php';

use WHMCS\Database\Capsule;

// A emissao e a resolucao de token vivem em modules/gateways/rivonpay.php
// (rivonpay_invoiceToken / rivonpay_invoiceIdForToken / rivonpay_paymentUrl),
// para que hooks e scripts possam gerar links sem incluir esta pagina - inclui-la
// executaria o request inteiro.

function rivonpix_fail($httpCode, $title, $message)
{
    http_response_code($httpCode);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="pt-br"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title></head>'
        . '<body style="margin:0;font:15px/1.6 -apple-system,Segoe UI,Roboto,Arial,sans-serif;'
        . 'background:#f5f6fa;color:#333;display:flex;align-items:center;justify-content:center;min-height:100vh;">'
        . '<div style="max-width:420px;padding:32px;background:#fff;border-radius:12px;'
        . 'box-shadow:0 1px 3px rgba(0,0,0,.08);text-align:center;">'
        . '<h1 style="margin:0 0 10px;font-size:19px;">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<p style="margin:0;color:#666;">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
        . '</div></body></html>';
    exit;
}

// ------------------------------------------------------------------- entrada

$isCheck   = !empty($_GET['check']);
$token     = isset($_GET['token']) ? (string) $_GET['token'] : '';
$invoiceId = 0;
$byToken   = false;

if ($token !== '') {
    // Caminho principal: o token e a propria credencial e resolve a fatura.
    // O ID nao aparece na URL, entao nao ha o que enumerar.
    $resolved = rivonpay_invoiceIdForToken($token);
    if ($resolved === null) {
        rivonpix_fail(404, 'Link inválido', 'Este link de pagamento não existe ou foi revogado.');
    }
    $invoiceId = $resolved;
    $byToken   = true;
} elseif (isset($_GET['invoiceid'])) {
    // Caminho interno: area do cliente e admin, protegidos por sessao.
    $invoiceId = (int) $_GET['invoiceid'];
}

if ($invoiceId < 1) {
    rivonpix_fail(400, 'Fatura não informada', 'Use rivonpix.php?token=SEU_TOKEN.');
}

$gatewayParams = getGatewayVariables('rivonpay');
if (empty($gatewayParams['type'])) {
    rivonpix_fail(503, 'Pagamento indisponível', 'O gateway RivonPay não está ativado.');
}

// rivonpay_charge() monta a postbackUrl a partir daqui.
if (empty($gatewayParams['systemurl'])) {
    $gatewayParams['systemurl'] = (string) Capsule::table('tblconfiguration')
        ->where('setting', 'SystemURL')->value('value');
}

$invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
if (!$invoice) {
    rivonpix_fail(404, 'Fatura não encontrada', 'Confira o link recebido.');
}

$client = Capsule::table('tblclients')->where('id', $invoice->userid)->first();
if (!$client) {
    rivonpix_fail(404, 'Cliente não encontrado', 'Confira o link recebido.');
}

// ------------------------------------------------------------- autorizacao

$authorized = false;

// 1) Sessao do cliente logado.
$sessionUserId = 0;
if (class_exists('\WHMCS\Authentication\CurrentUser')) {
    try {
        $currentUser   = new \WHMCS\Authentication\CurrentUser();
        $currentClient = $currentUser->client();
        if ($currentClient) {
            $sessionUserId = (int) $currentClient->id;
        }
    } catch (Exception $e) {
        // cai para o $_SESSION
    }
}
if (!$sessionUserId && !empty($_SESSION['uid'])) {
    $sessionUserId = (int) $_SESSION['uid'];
}
if ($sessionUserId && $sessionUserId === (int) $invoice->userid) {
    $authorized = true;
}

// 2) Token do link. Ja foi validado contra o banco ao resolver a fatura:
// acertar 256 bits aleatorios nao e algo que se adivinhe.
if (!$authorized && $byToken) {
    $authorized = true;
}

// 3) Admin logado no WHMCS - para suporte e para gerar o link assinado.
if (!$authorized && !empty($_SESSION['adminid'])) {
    $authorized = true;
}

if (!$authorized) {
    rivonpix_fail(403, 'Acesso negado', 'Entre na sua conta para ver esta fatura, ou use o link completo que você recebeu.');
}

// ------------------------------------- gerador de link assinado (so admin)
//
// Aberto por um admin logado no WHMCS:
//   /rivonpix.php?invoiceid=177084&link=1
// devolve a URL pronta para mandar ao cliente. Fica atras da sessao de admin
// porque quem consegue gerar tokens consegue abrir qualquer fatura.

if (!empty($_GET['link'])) {
    if (empty($_SESSION['adminid'])) {
        rivonpix_fail(403, 'Acesso negado', 'Entre no painel administrativo do WHMCS para gerar links de pagamento.');
    }

    $url = rivonpay_paymentUrl($invoiceId, $gatewayParams);
    if ($url === '') {
        rivonpix_fail(500, 'Não foi possível gerar o link', 'Falha ao gravar o token no banco. Veja o Gateway Log.');
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="pt-br"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Link de pagamento — Fatura #' . (int) $invoiceId . '</title></head>'
        . '<body style="margin:0;padding:40px 16px;background:#f7f8fc;'
        . 'font:15px/1.6 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1f2333;">'
        . '<div style="max-width:640px;margin:0 auto;background:#fff;border:1px solid #eceef5;'
        . 'border-radius:14px;padding:28px;">'
        . '<h1 style="margin:0 0 4px;font-size:20px;">Link de pagamento</h1>'
        . '<p style="margin:0 0 18px;color:#6b7280;">Fatura #' . (int) $invoiceId
        . ' — ' . htmlspecialchars(trim($client->firstname . ' ' . $client->lastname), ENT_QUOTES, 'UTF-8') . '</p>'
        . '<input id="u" readonly value="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" '
        . 'style="width:100%;padding:12px;border:1px solid #dfe3ee;border-radius:8px;'
        . 'font-family:monospace;font-size:13px;background:#f9fafb;">'
        . '<button onclick="var i=document.getElementById(\'u\');i.select();'
        . 'document.execCommand(\'copy\');this.textContent=\'Copiado!\';" '
        . 'style="margin-top:12px;padding:13px 20px;border:0;border-radius:8px;background:#8b1fa9;'
        . 'color:#fff;font-size:15px;font-weight:700;cursor:pointer;width:100%;">Copiar link</button>'
        . '<p style="margin:18px 0 0;color:#6b7280;font-size:13px;">'
        . 'Válido enquanto a secret key do gateway não for trocada. '
        . 'Rotacionar a chave invalida todos os links já enviados.</p>'
        . '</div></body></html>';
    exit;
}

// ------------------------------------------------------------------- estado

$paidAmount = (float) Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->sum('amountin');
$balance    = round(((float) $invoice->total) - $paidAmount, 2);
$isPaid     = ($invoice->status === 'Paid' || $balance <= 0);

$paidStatuses = rivonpay_paidStatuses();

/**
 * Reconsulta a cobranca na API e, se estiver paga, garante a baixa.
 *
 * Rede de seguranca do webhook: se o postback falhar, a tela nao pode dizer
 * "confirmado" com a fatura em aberto. A dupla baixa e evitada checando o
 * transid em tblaccounts (checkCbTransID nao serve aqui porque encerra o script).
 */
function rivonpix_syncPayment($gatewayParams, $invoiceId, array $paidStatuses)
{
    $row = Capsule::table(RIVONPAY_TABLE)
        ->where('invoiceid', $invoiceId)
        ->orderBy('id', 'desc')
        ->first();

    if (!$row || empty($row->transid)) {
        return false;
    }

    $response = rivonpay_request($gatewayParams, 'GET', '/v1/transactions/' . rawurlencode($row->transid));
    if ($response['code'] < 200 || $response['code'] >= 300 || !is_array($response['decoded'])) {
        return false;
    }

    $tx     = $response['decoded'];
    $status = isset($tx['status']) ? strtoupper((string) $tx['status']) : '';

    try {
        Capsule::table(RIVONPAY_TABLE)->where('id', $row->id)
            ->update(array('status' => $status, 'updated_at' => date('Y-m-d H:i:s')));
    } catch (Exception $e) {
        // segue
    }

    if (!in_array($status, $paidStatuses, true)) {
        return false;
    }

    $already = Capsule::table('tblaccounts')->where('transid', $row->transid)->exists();
    if (!$already) {
        addInvoicePayment(
            $invoiceId,
            $row->transid,
            isset($tx['amount']) ? ((int) $tx['amount']) / 100 : 0,
            isset($tx['fee']) ? ((int) $tx['fee']) / 100 : 0,
            'rivonpay'
        );
        logTransaction('rivonpay', array(
            'origem'        => 'rivonpix.php',
            'transactionId' => $row->transid,
            'invoiceId'     => $invoiceId,
            'status'        => $status,
        ), 'Successful');
    }

    return true;
}

// ------------------------------------------------ endpoint de polling (JSON)

if ($isCheck) {
    header('Content-Type: application/json; charset=utf-8');
    $paid = $isPaid ? true : rivonpix_syncPayment($gatewayParams, $invoiceId, $paidStatuses);
    echo json_encode(array('paid' => (bool) $paid));
    exit;
}

// --------------------------------------------------------------- a cobranca

$charge      = null;
$errorText   = '';
$secondsLeft = 0;

if (!$isPaid) {
    $currencyCode = (string) Capsule::table('tblcurrencies')->where('id', $client->currency)->value('code');
    if (strtoupper($currencyCode) !== 'BRL') {
        rivonpix_fail(400, 'Moeda não suportada', 'O Pix só aceita faturas em BRL. Esta está em ' . $currencyCode . '.');
    }

    $charge = rivonpay_charge($gatewayParams, $invoiceId, (int) round($balance * 100), array(
        'userid'      => (int) $client->id,
        'firstname'   => $client->firstname,
        'lastname'    => $client->lastname,
        'email'       => $client->email,
        'phonenumber' => $client->phonenumber,
        'tax_id'      => isset($client->tax_id) ? $client->tax_id : '',
    ));

    if (empty($charge['ok'])) {
        $errorText = $charge['error'];
    } elseif (!empty($charge['expiresAt'])) {
        $ts = strtotime($charge['expiresAt']);
        if ($ts) {
            $secondsLeft = max(0, $ts - time());
        }
    }
}

$clientName = trim($client->firstname . ' ' . $client->lastname);
$qrSrc      = '';
if ($charge && !empty($charge['ok'])) {
    $qrSrc = !empty($charge['qrCodeImage'])
        ? $charge['qrCodeImage']
        : 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&data=' . rawurlencode($charge['qrCode']);
}

function e($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

// O polling precisa voltar pela mesma credencial com que a pagina foi aberta.
$checkUrl = $byToken
    ? 'rivonpix.php?token=' . urlencode($token) . '&check=1'
    : 'rivonpix.php?invoiceid=' . (int) $invoiceId . '&check=1';

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pagamento via PIX — Fatura #<?= e($invoiceId) ?></title>
<style>
    * { box-sizing: border-box; }
    body { margin:0; padding:32px 16px; background:#f7f8fc;
           font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif; color:#1f2333; }
    .head { text-align:center; margin-bottom:28px; }
    .head h1 { margin:0 0 6px; font-size:26px; }
    .head p { margin:0; color:#6b7280; }
    .grid { display:flex; gap:20px; max-width:940px; margin:0 auto; align-items:flex-start; flex-wrap:wrap; }
    .card { background:#fff; border:1px solid #eceef5; border-radius:14px; padding:26px; flex:1 1 340px; }
    .center { text-align:center; }
    .pill { display:inline-block; padding:7px 16px; border-radius:999px; background:#fef6e0;
            color:#8a6100; font-size:14px; font-weight:600; }
    .pill::before { content:"●"; margin-right:7px; color:#e3a008; }
    .label { color:#6b7280; font-size:14px; margin:16px 0 2px; }
    .timer { font-size:30px; font-weight:700; color:#7c3aed; letter-spacing:1px; }
    .qr { width:260px; height:260px; margin:18px auto; display:block; border:1px solid #eceef5;
          border-radius:10px; padding:10px; background:#fff; }
    .emv { font-size:12px; color:#4b5563; word-break:break-all; background:#f9fafb;
           border:1px solid #eceef5; border-radius:8px; padding:10px; margin:0 0 4px; max-height:64px; overflow:auto; }
    .btn { display:block; width:100%; margin-top:14px; padding:15px; border:0; border-radius:10px;
           background:#8b1fa9; color:#fff; font-size:16px; font-weight:700; cursor:pointer; }
    .btn:hover { filter:brightness(1.08); }
    .btn.green { background:#0f9d58; text-decoration:none; text-align:center; }
    h2 { margin:0 0 20px; font-size:19px; }
    .row { display:flex; justify-content:space-between; gap:12px; padding:11px 0; }
    .sep { border-top:1px solid #eef0f6; }
    .muted { color:#6b7280; font-size:14px; }
    .total { font-size:22px; font-weight:700; color:#0f9d58; }
    .badge { display:flex; gap:12px; align-items:center; background:#f6f1fe;
             border-radius:10px; padding:14px; margin:16px 0; }
    .badge strong { display:block; }
    ul.trust { list-style:none; margin:0; padding:0; color:#4b5563; font-size:14px; }
    ul.trust li { padding:5px 0; }
    .ok-circle { width:74px; height:74px; border-radius:50%; background:#e6f6ec; color:#0f9d58;
                 font-size:38px; line-height:74px; margin:0 auto 16px; }
    @media (max-width:760px) { .grid { flex-direction:column; } .card { width:100%; } }
</style>
</head>
<body>

<?php if ($isPaid): ?>

    <div class="card center" style="max-width:430px;margin:40px auto;">
        <div class="ok-circle">✓</div>
        <h1 style="margin:0 0 8px;font-size:23px;">Pagamento Confirmado!</h1>
        <p class="muted" style="margin:0 0 20px;">
            Seu pagamento foi processado com sucesso.<br>Você receberá um e-mail de confirmação em breve.
        </p>
        <div style="background:#f7f8fc;border-radius:10px;padding:14px;margin-bottom:20px;">
            <div class="muted">Fatura</div>
            <strong style="font-size:17px;">#<?= e($invoiceId) ?></strong>
        </div>
        <a class="btn green" href="clientarea.php?action=invoices">Finalizar</a>
    </div>

<?php else: ?>

    <div class="head">
        <h1>Pagamento via PIX</h1>
        <p>Escaneie o QR Code ou copie o código PIX</p>
    </div>

    <div class="grid">
        <div class="card center">
            <?php if ($errorText !== ''): ?>
                <p style="color:#b3261e;margin:0;"><?= e($errorText) ?></p>
                <button class="btn" onclick="location.reload()">Tentar novamente</button>
            <?php else: ?>
                <span class="pill" id="rp-status">Aguardando pagamento</span>

                <div class="label">Tempo para pagamento</div>
                <div class="timer" id="rp-timer">--:--</div>

                <img class="qr" src="<?= e($qrSrc) ?>" alt="QR Code PIX">

                <div class="label" style="margin-top:0;">Código de pagamento</div>
                <div class="emv" id="rp-emv"><?= e($charge['qrCode']) ?></div>

                <button class="btn" id="rp-copy">Copiar Código PIX</button>
                <p class="muted" style="margin:12px 0 0;">Escaneie com o app do seu banco</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Resumo da compra</h2>

            <div class="muted">Cliente</div>
            <strong><?= e($clientName) ?></strong>
            <div class="muted"><?= e($client->email) ?></div>

            <div class="sep" style="margin-top:16px;padding-top:16px;">
                <div class="muted">Fatura</div>
                <strong>#<?= e($invoiceId) ?></strong>
                <div class="muted">Data: <?= e(date('d/m/Y', strtotime($invoice->date))) ?></div>
            </div>

            <div class="sep" style="margin-top:16px;">
                <div class="row"><span class="muted">Subtotal</span>
                    <span>R$ <?= e(number_format((float) $invoice->subtotal, 2, ',', '.')) ?></span></div>
                <div class="row sep"><span><strong>Total a pagar</strong></span>
                    <span class="total">R$ <?= e(number_format($balance, 2, ',', '.')) ?></span></div>
            </div>

            <div class="badge">
                <span style="font-size:22px;">▣</span>
                <span><strong>PIX</strong><span class="muted">Aprovação instantânea</span></span>
            </div>

            <ul class="trust">
                <li>🛡️ Transação segura e criptografada</li>
                <li>🕐 Confirmação automática em segundos</li>
                <li>🎧 Suporte disponível 24/7</li>
            </ul>
        </div>
    </div>

    <script>
    (function () {
        var emv   = document.getElementById("rp-emv");
        var copy  = document.getElementById("rp-copy");
        var timer = document.getElementById("rp-timer");
        if (!emv || !copy) { return; }

        copy.addEventListener("click", function () {
            var texto = emv.textContent.trim();
            var feito = function () {
                var antes = copy.textContent;
                copy.textContent = "Código copiado!";
                setTimeout(function () { copy.textContent = antes; }, 2000);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(texto).then(feito, feito);
            } else {
                var t = document.createElement("textarea");
                t.value = texto; document.body.appendChild(t); t.select();
                document.execCommand("copy"); document.body.removeChild(t); feito();
            }
        });

        // Contagem a partir de segundos calculados no servidor: o relogio do
        // visitante pode estar errado e mostraria vencimento incorreto.
        var restam = <?= (int) $secondsLeft ?>;
        var vivo   = restam > 0;

        function pinta() {
            if (restam <= 0) {
                timer.textContent = "Expirado";
                timer.style.color = "#b3261e";
                document.getElementById("rp-status").textContent = "Código expirado";
                copy.textContent = "Gerar novo código";
                copy.onclick = function () { location.reload(); };
                vivo = false;
                return;
            }
            var m = Math.floor(restam / 60), s = restam % 60;
            timer.textContent = (m < 10 ? "0" : "") + m + ":" + (s < 10 ? "0" : "") + s;
        }

        pinta();
        setInterval(function () { if (vivo) { restam--; pinta(); } }, 1000);

        // Confirmacao automatica. Para junto com o vencimento - continuar
        // consultando sem cobranca valida so gastaria rate limit.
        setInterval(function () {
            if (!vivo) { return; }
            fetch("<?= e($checkUrl) ?>", { credentials: "same-origin" })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (d && d.paid) { location.reload(); } })
                .catch(function () {});
        }, 6000);
    })();
    </script>

<?php endif; ?>

</body>
</html>
