<?php
/**
 * Disponibiliza {$rivonpix_link} nos e-mails de fatura do WHMCS.
 *
 * Vai em includes/hooks/rivonpix.php dentro do WHMCS.
 *
 * Com isso o cliente recebe o link de pagamento Pix direto no e-mail, ja
 * assinado, e paga sem precisar entrar na conta.
 *
 * Uso: edite o template do e-mail em Configuracoes > E-mail Templates e insira
 *
 *     <a href="{$rivonpix_link}">Pagar com Pix</a>
 *
 * A variavel vem vazia quando a fatura ja esta paga ou quando o gateway nao
 * esta ativo - envolva num {if $rivonpix_link} se quiser esconder o bloco.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

add_hook('EmailPreSend', 1, function ($vars) {
    // Somente e-mails de cobranca. Confirmacao de pagamento nao precisa de link.
    $templates = array(
        'Invoice Created',
        'Invoice Payment Reminder',
        'First Invoice Overdue Notice',
        'Second Invoice Overdue Notice',
        'Third Invoice Overdue Notice',
    );

    if (!in_array($vars['messagename'], $templates, true)) {
        return;
    }

    $invoiceId = isset($vars['relid']) ? (int) $vars['relid'] : 0;
    if ($invoiceId < 1) {
        return;
    }

    if (!function_exists('getGatewayVariables')) {
        require_once __DIR__ . '/../../includes/gatewayfunctions.php';
    }

    $params = getGatewayVariables('rivonpay');
    if (empty($params['type']) || empty($params['secretKey'])) {
        return array('mergefields' => array('{$rivonpix_link}' => ''));
    }

    // Nao faz sentido oferecer pagamento de fatura ja quitada.
    try {
        $status = Capsule::table('tblinvoices')->where('id', $invoiceId)->value('status');
        if ($status === 'Paid' || $status === 'Cancelled') {
            return array('mergefields' => array('{$rivonpix_link}' => ''));
        }
    } catch (Exception $e) {
        return array('mergefields' => array('{$rivonpix_link}' => ''));
    }

    // rivonpay_paymentUrl() mora no modulo do gateway, que pode ser incluido
    // com seguranca (o rivonpix.php nao - inclui-lo executaria a pagina).
    if (!function_exists('rivonpay_paymentUrl')) {
        $module = __DIR__ . '/../../modules/gateways/rivonpay.php';
        if (!is_readable($module)) {
            return array('mergefields' => array('{$rivonpix_link}' => ''));
        }
        require_once $module;
    }

    return array(
        'mergefields' => array(
            '{$rivonpix_link}' => rivonpay_paymentUrl($invoiceId, $params),
        ),
    );
});
