<?php
/**
 * Gestionnaire des webhooks GeniusPay
 *
 * @package GeniusPay_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe GeniusPay_Webhook
 */
class GeniusPay_Webhook {

    /**
     * Logger
     */
    private $logger;

    /**
     * Secret webhook Live
     */
    private $webhook_secret;

    /**
     * Secret webhook Sandbox
     */
    private $sandbox_webhook_secret;

    /**
     * Gateway
     */
    private $gateway;

    /**
     * Constructeur
     */
    public function __construct() {
        $this->logger = new GeniusPay_Logger();
        
        $this->gateway = new GeniusPay_Gateway();
        $this->webhook_secret = $this->gateway->get_option('webhook_secret');
        $this->sandbox_webhook_secret = $this->gateway->get_option('sandbox_webhook_secret');

        // Enregistrer le endpoint webhook
        add_action('woocommerce_api_geniuspay_webhook', array($this, 'process'));
    }

    /**
     * Traite le webhook entrant
     */
    public function process() {
        $this->logger->log('Webhook received', 'info');

        // 1. Récupérer les headers requis
        $signature = $this->get_header('X-Webhook-Signature');
        $timestamp = $this->get_header('X-Webhook-Timestamp');
        $event = $this->get_header('X-Webhook-Event');
        $environment = $this->get_header('X-Webhook-Environment'); // sandbox ou live
        
        // 2. Vérifier que tous les headers requis sont présents
        if (empty($signature) || empty($timestamp) || empty($event)) {
            $this->logger->log('Missing required headers', 'error', array(
                'signature' => !empty($signature),
                'timestamp' => !empty($timestamp),
                'event' => !empty($event)
            ));
            $this->send_error_response(400, 'Bad Request', 'Required headers are missing (X-Webhook-Signature, X-Webhook-Timestamp, X-Webhook-Event)');
            return;
        }

        // 3. Récupérer le corps de la requête
        $payload = file_get_contents('php://input');
        
        if (empty($payload)) {
            $this->logger->log('Empty webhook payload', 'error');
            $this->send_error_response(400, 'Bad Request', 'Empty payload');
            return;
        }

        // 4. Décoder le JSON
        $data = json_decode($payload, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->log('Invalid JSON payload: ' . json_last_error_msg(), 'error');
            $this->send_error_response(400, 'Bad Request', 'Invalid JSON: ' . json_last_error_msg());
            return;
        }

        // Détecter le mode (sandbox ou live)
        $is_sandbox = $this->detect_sandbox_mode($environment, $data);
        
        $this->logger->log('Webhook data received', 'debug', array(
            'event' => $event,
            'timestamp' => $timestamp,
            'environment' => $environment,
            'is_sandbox' => $is_sandbox,
            'payload_size' => strlen($payload)
        ));

        // Logger le payload brut pour le débogage
        $this->logger->log('Webhook raw payload: ' . $payload, 'debug');

        // 5. Vérifier la signature si configurée
        $secret = $is_sandbox ? $this->sandbox_webhook_secret : $this->webhook_secret;
        
        if (!empty($secret)) {
            if (!$this->verify_signature($payload, $signature, $timestamp, $secret)) {
                // Logger tous les headers reçus pour le diagnostic
                $all_headers = array();
                if (function_exists('getallheaders')) {
                    foreach (getallheaders() as $k => $v) {
                        if (stripos($k, 'webhook') !== false || stripos($k, 'signature') !== false || stripos($k, 'geniuspay') !== false || stripos($k, 'x-') === 0) {
                            $all_headers[$k] = $v;
                        }
                    }
                }
                $this->logger->log('Invalid webhook signature', 'error', array(
                    'mode' => $is_sandbox ? 'sandbox' : 'live',
                    'received_signature' => substr($signature, 0, 10) . '...',
                    'received_timestamp' => $timestamp,
                    'payload_length' => strlen($payload),
                    'secret_length' => strlen($secret),
                    'headers' => $all_headers,
                ));
                $this->send_error_response(401, 'Unauthorized', 'Invalid webhook signature');
                return;
            }
            $this->logger->log('Webhook signature verified successfully', 'info');
        } else {
            $this->logger->log('Webhook secret not configured for ' . ($is_sandbox ? 'sandbox' : 'live') . ' mode', 'warning');
        }

        // 6. Vérifier le timestamp (protection replay attack - max 5 minutes)
        $current_time = time();
        $time_diff = abs($current_time - intval($timestamp));
        
        if ($time_diff > 300) {
            $this->logger->log('Webhook timestamp too old', 'error', array(
                'timestamp' => $timestamp,
                'current_time' => $current_time,
                'diff_seconds' => $time_diff
            ));
            $this->send_error_response(400, 'Bad Request', 'Webhook timestamp is too old (max 5 minutes)');
            return;
        }

        // 7. Traiter l'événement
        try {
            $transaction_data = isset($data['data']) ? $data['data'] : array();
            
            // Ajouter l'info du mode sandbox aux données
            $transaction_data['_is_sandbox'] = $is_sandbox;

            switch ($event) {
                case 'payment.success':
                    $this->handle_payment_completed($transaction_data, $is_sandbox);
                    break;

                case 'payment.failed':
                    $this->handle_payment_failed($transaction_data, $is_sandbox);
                    break;

                case 'payment.cancelled':
                    $this->handle_payment_cancelled($transaction_data, $is_sandbox);
                    break;

                case 'payment.initiated':
                case 'payment.pending':
                    $this->handle_payment_pending($transaction_data, $is_sandbox);
                    break;

                case 'payment.refunded':
                    $this->handle_refund_completed($transaction_data, $is_sandbox);
                    break;

                case 'webhook.test':
                    $mode_label = $is_sandbox ? 'Sandbox' : 'Live';
                    $this->logger->log('Webhook test received (' . $mode_label . ')', 'info');
                    $this->send_success_response('Webhook test received successfully (' . $mode_label . ')');
                    return;

                default:
                    $this->logger->log('Unknown webhook event: ' . $event, 'warning');
                    $this->send_success_response('Event type not handled: ' . $event);
                    return;
            }

            $this->send_success_response('Webhook processed successfully');
            
        } catch (Exception $e) {
            $this->logger->log('Error processing webhook: ' . $e->getMessage(), 'error');
            $this->send_error_response(500, 'Internal Server Error', 'Failed to process webhook: ' . $e->getMessage());
        }
    }

    /**
     * Détecte si le webhook provient du mode Sandbox
     */
    private function detect_sandbox_mode($environment_header, $data) {
        // 1. Vérifier le header X-Webhook-Environment
        if (!empty($environment_header)) {
            return strtolower($environment_header) === 'sandbox';
        }
        
        // 2. Vérifier le champ environment dans les données
        if (isset($data['data']['environment'])) {
            return strtolower($data['data']['environment']) === 'sandbox';
        }
        
        // 3. Vérifier si la référence commence par SANDBOX_
        if (isset($data['data']['reference']) && strpos($data['data']['reference'], 'SANDBOX_') === 0) {
            return true;
        }
        
        // Par défaut, considérer comme Live
        return false;
    }

    /**
     * Récupère un header HTTP (compatible avec différents formats)
     */
    private function get_header($name) {
        // Format standard
        if (isset($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))])) {
            return sanitize_text_field(wp_unslash($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))]));
        }
        
        // Fallback pour getallheaders()
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $key => $value) {
                if (strcasecmp($key, $name) === 0) {
                    return sanitize_text_field($value);
                }
            }
        }
        
        return '';
    }

    /**
     * Vérifie la signature du webhook (compatible v2 et v3)
     * 
     * Formats supportés:
     * - v2: HMAC-SHA256(timestamp + "." + payload, secret) en hex
     * - v3a: HMAC-SHA256(payload, secret) en hex (sans timestamp)
     * - v3b: base64(HMAC-SHA256(timestamp + "." + payload, secret))
     * - v3c: base64(HMAC-SHA256(payload, secret)) (sans timestamp)
     *
     * @param string $payload Corps brut de la requête
     * @param string $signature Signature reçue dans le header
     * @param string $timestamp Timestamp reçu dans le header
     * @param string $secret Secret webhook
     * @return bool
     */
    private function verify_signature($payload, $signature, $timestamp, $secret) {
        if (empty($signature) || empty($secret)) {
            return false;
        }

        // Nettoyer la signature reçue (retirer espaces et préfixes éventuels)
        $signature = trim($signature);
        // Retirer un éventuel préfixe comme "sha256="
        if (strpos($signature, '=') !== false) {
            $parts = explode('=', $signature, 2);
            $signature = $parts[1];
        }

        // Format 1 (v2): HMAC-SHA256(timestamp.payload, secret) en hex
        $data_with_ts = $timestamp . '.' . $payload;
        $expected_hex_with_ts = hash_hmac('sha256', $data_with_ts, $secret);
        
        if (hash_equals($expected_hex_with_ts, $signature)) {
            $this->logger->log('Signature matched: v2 format (hex, timestamp.payload)', 'debug');
            return true;
        }

        // Format 2 (v3): HMAC-SHA256(payload, secret) en hex (sans timestamp)
        $expected_hex_no_ts = hash_hmac('sha256', $payload, $secret);
        
        if (hash_equals($expected_hex_no_ts, $signature)) {
            $this->logger->log('Signature matched: v3 format (hex, payload only)', 'debug');
            return true;
        }

        // Format 3: base64(HMAC-SHA256(timestamp.payload, secret))
        $expected_b64_with_ts = base64_encode(hash_hmac('sha256', $data_with_ts, $secret, true));
        
        if (hash_equals($expected_b64_with_ts, $signature)) {
            $this->logger->log('Signature matched: v3 format (base64, timestamp.payload)', 'debug');
            return true;
        }

        // Format 4: base64(HMAC-SHA256(payload, secret)) (sans timestamp)
        $expected_b64_no_ts = base64_encode(hash_hmac('sha256', $payload, $secret, true));
        
        if (hash_equals($expected_b64_no_ts, $signature)) {
            $this->logger->log('Signature matched: v3 format (base64, payload only)', 'debug');
            return true;
        }

        // Format 5: HMAC-SHA256(payload.timestamp, secret) en hex (ordre inversé)
        $data_reversed = $payload . '.' . $timestamp;
        $expected_hex_reversed = hash_hmac('sha256', $data_reversed, $secret);
        
        if (hash_equals($expected_hex_reversed, $signature)) {
            $this->logger->log('Signature matched: v3 format (hex, payload.timestamp reversed)', 'debug');
            return true;
        }

        // Aucun format n'a matché - logger pour diagnostic
        $this->logger->log('Signature verification failed - tried 5 formats', 'debug', array(
            'received_sig_preview' => substr($signature, 0, 16),
            'expected_v2_hex_preview' => substr($expected_hex_with_ts, 0, 16),
            'expected_v3_hex_preview' => substr($expected_hex_no_ts, 0, 16),
            'expected_v3_b64_ts_preview' => substr($expected_b64_with_ts, 0, 16),
            'expected_v3_b64_no_ts_preview' => substr($expected_b64_no_ts, 0, 16),
            'timestamp' => $timestamp,
            'payload_preview' => substr($payload, 0, 100),
        ));

        return false;
    }

    /**
     * Gère un paiement complété
     */
    private function handle_payment_completed($data, $is_sandbox = false) {
        $order = $this->get_order_from_webhook($data);
        
        if (!$order) {
            $this->logger->log('Order not found for completed payment', 'error');
            return;
        }

        // Vérifier que la commande n'est pas déjà traitée
        if ($order->is_paid()) {
            $this->logger->log('Order #' . $order->get_id() . ' already paid', 'info');
            return;
        }

        // Mettre à jour les métadonnées
        if (isset($data['reference'])) {
            $order->update_meta_data('_geniuspay_reference', sanitize_text_field($data['reference']));
        }
        if (isset($data['gateway_reference'])) {
            $order->update_meta_data('_geniuspay_gateway_reference', sanitize_text_field($data['gateway_reference']));
        }
        if (isset($data['provider'])) {
            $order->update_meta_data('_geniuspay_provider', sanitize_text_field($data['provider']));
        }
        if (isset($data['payment_method'])) {
            $order->update_meta_data('_geniuspay_payment_method', sanitize_text_field($data['payment_method']));
        }
        if (isset($data['fees'])) {
            $order->update_meta_data('_geniuspay_fees', floatval($data['fees']));
        }
        if (isset($data['net_amount'])) {
            $order->update_meta_data('_geniuspay_net_amount', floatval($data['net_amount']));
        }
        
        // Enregistrer le mode (sandbox ou live)
        $order->update_meta_data('_geniuspay_environment', $is_sandbox ? 'sandbox' : 'live');

        // Marquer la commande comme payée
        $order->payment_complete(isset($data['reference']) ? $data['reference'] : '');
        
        // Ajouter une note
        $mode_label = $is_sandbox ? __('Sandbox', 'geniuspay-for-woocommerce') : __('Live', 'geniuspay-for-woocommerce');
        $note = sprintf(
            /* translators: %1$s: Environment mode, %2$s: Payment reference number */
            __('Paiement GeniusPay reçu (%1$s). Référence: %2$s', 'geniuspay-for-woocommerce'),
            $mode_label,
            isset($data['reference']) ? $data['reference'] : 'N/A'
        );
        $order->add_order_note($note);

        $order->save();

        $this->logger->log('Order #' . $order->get_id() . ' marked as paid (' . $mode_label . ')', 'info');

        // Action pour les développeurs
        do_action('geniuspay_payment_completed', $order, $data, $is_sandbox);
    }

    /**
     * Gère un paiement échoué
     */
    private function handle_payment_failed($data, $is_sandbox = false) {
        $order = $this->get_order_from_webhook($data);
        
        if (!$order) {
            $this->logger->log('Order not found for failed payment', 'error');
            return;
        }

        // Ne pas traiter si déjà complétée
        if ($order->is_paid()) {
            $this->logger->log('Order #' . $order->get_id() . ' already paid, ignoring failed webhook', 'warning');
            return;
        }

        // Enregistrer le mode
        $order->update_meta_data('_geniuspay_environment', $is_sandbox ? 'sandbox' : 'live');
        
        // Mettre à jour le statut
        $mode_label = $is_sandbox ? __('Sandbox', 'geniuspay-for-woocommerce') : __('Live', 'geniuspay-for-woocommerce');
        $order->update_status('failed', sprintf(
            /* translators: %1$s: Environment mode, %2$s: Failure reason */
            __('Paiement GeniusPay échoué (%1$s). Raison: %2$s', 'geniuspay-for-woocommerce'),
            $mode_label,
            isset($data['status_message']) ? $data['status_message'] : __('Non spécifiée', 'geniuspay-for-woocommerce')
        ));

        $order->save();

        $this->logger->log('Order #' . $order->get_id() . ' marked as failed (' . $mode_label . ')', 'info');

        // Action pour les développeurs
        do_action('geniuspay_payment_failed', $order, $data, $is_sandbox);
    }

    /**
     * Gère un paiement annulé
     */
    private function handle_payment_cancelled($data, $is_sandbox = false) {
        $order = $this->get_order_from_webhook($data);
        
        if (!$order) {
            $this->logger->log('Order not found for cancelled payment', 'error');
            return;
        }

        // Ne pas traiter si déjà complétée
        if ($order->is_paid()) {
            $this->logger->log('Order #' . $order->get_id() . ' already paid, ignoring cancelled webhook', 'warning');
            return;
        }

        // Enregistrer le mode
        $order->update_meta_data('_geniuspay_environment', $is_sandbox ? 'sandbox' : 'live');
        
        // Mettre à jour le statut
        $mode_label = $is_sandbox ? __('Sandbox', 'geniuspay-for-woocommerce') : __('Live', 'geniuspay-for-woocommerce');
        $order->update_status('cancelled', sprintf(
            /* translators: %s: Environment mode */
            __('Paiement GeniusPay annulé par le client (%s).', 'geniuspay-for-woocommerce'),
            $mode_label
        ));
        $order->save();

        $this->logger->log('Order #' . $order->get_id() . ' marked as cancelled (' . $mode_label . ')', 'info');

        // Action pour les développeurs
        do_action('geniuspay_payment_cancelled', $order, $data, $is_sandbox);
    }

    /**
     * Gère un paiement en attente
     */
    private function handle_payment_pending($data, $is_sandbox = false) {
        $order = $this->get_order_from_webhook($data);
        
        if (!$order) {
            $this->logger->log('Order not found for pending payment', 'error');
            return;
        }

        // Enregistrer le mode
        $order->update_meta_data('_geniuspay_environment', $is_sandbox ? 'sandbox' : 'live');
        
        // Mettre à jour le statut si nécessaire
        $mode_label = $is_sandbox ? __('Sandbox', 'geniuspay-for-woocommerce') : __('Live', 'geniuspay-for-woocommerce');
        if ($order->get_status() === 'pending') {
            $order->update_status('on-hold', sprintf(
                /* translators: %s: Environment mode */
                __('Paiement GeniusPay en cours de traitement (%s).', 'geniuspay-for-woocommerce'),
                $mode_label
            ));
            $order->save();
        }

        $this->logger->log('Order #' . $order->get_id() . ' pending payment notification received (' . $mode_label . ')', 'info');

        // Action pour les développeurs
        do_action('geniuspay_payment_pending', $order, $data, $is_sandbox);
    }

    /**
     * Gère un remboursement complété
     */
    private function handle_refund_completed($data, $is_sandbox = false) {
        $order = $this->get_order_from_webhook($data);
        
        if (!$order) {
            $this->logger->log('Order not found for refund', 'error');
            return;
        }

        $refund_amount = isset($data['refund_amount']) ? floatval($data['refund_amount']) : $order->get_total();
        
        // Créer le remboursement WooCommerce
        $mode_label = $is_sandbox ? __('Sandbox', 'geniuspay-for-woocommerce') : __('Live', 'geniuspay-for-woocommerce');
        $refund = wc_create_refund(array(
            'amount' => $refund_amount,
            'reason' => isset($data['refund_reason']) ? sanitize_text_field($data['refund_reason']) : sprintf(
                /* translators: %s: Environment mode */
                __('Remboursement GeniusPay (%s)', 'geniuspay-for-woocommerce'),
                $mode_label
            ),
            'order_id' => $order->get_id(),
            'refund_payment' => false, // Le remboursement est déjà fait côté GeniusPay
        ));

        if (is_wp_error($refund)) {
            $this->logger->log('Failed to create refund for order #' . $order->get_id() . ': ' . $refund->get_error_message(), 'error');
            return;
        }

        $order->add_order_note(sprintf(
            /* translators: %1$s: Refund amount, %2$s: Environment mode */
            __('Remboursement GeniusPay de %1$s reçu (%2$s).', 'geniuspay-for-woocommerce'),
            wc_price($refund_amount),
            $mode_label
        ));

        $this->logger->log('Refund created for order #' . $order->get_id() . ' (' . $mode_label . ')', 'info');

        // Action pour les développeurs
        do_action('geniuspay_refund_completed', $order, $data, $refund, $is_sandbox);
    }

    /**
     * Récupère la commande à partir des données du webhook
     */
    private function get_order_from_webhook($data) {
        $order = null;

        // Essayer avec l'order_id des métadonnées
        if (isset($data['metadata']['order_id'])) {
            $order = wc_get_order(absint($data['metadata']['order_id']));
        }

        // Essayer avec la référence GeniusPay
        if (!$order && isset($data['reference'])) {
            $orders = wc_get_orders(array(
                'meta_key' => '_geniuspay_reference', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_value' => sanitize_text_field($data['reference']), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'limit' => 1,
            ));

            if (!empty($orders)) {
                $order = $orders[0];
            }
        }

        // Vérifier l'order_key si fourni
        if ($order && isset($data['metadata']['order_key'])) {
            if ($order->get_order_key() !== $data['metadata']['order_key']) {
                $this->logger->log('Order key mismatch', 'warning');
                return null;
            }
        }

        return $order;
    }

    /**
     * Envoie une réponse de succès
     */
    private function send_success_response($message) {
        status_header(200);
        header('Content-Type: application/json');
        
        echo wp_json_encode(array(
            'success' => true,
            'message' => $message
        ));
        
        exit;
    }

    /**
     * Envoie une réponse d'erreur (format RFC 7807)
     */
    private function send_error_response($status_code, $title, $detail) {
        status_header($status_code);
        header('Content-Type: application/problem+json');
        
        echo wp_json_encode(array(
            'type' => 'about:blank',
            'title' => $title,
            'status' => $status_code,
            'detail' => $detail,
            'instance' => isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/'
        ));
        
        exit;
    }
}

// Initialiser le webhook handler
new GeniusPay_Webhook();
