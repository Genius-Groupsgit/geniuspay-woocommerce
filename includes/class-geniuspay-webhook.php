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
     * Secret webhook
     */
    private $webhook_secret;

    /**
     * Constructeur
     */
    public function __construct() {
        $this->logger = new GeniusPay_Logger();

        $gateway_settings     = get_option('woocommerce_geniuspay_settings', array());
        $this->webhook_secret = isset($gateway_settings['webhook_secret']) ? $gateway_settings['webhook_secret'] : '';

        add_action('woocommerce_api_geniuspay_webhook', array($this, 'process'));
    }

    /**
     * Traite le webhook entrant
     */
    public function process() {
        $this->logger->log('Webhook received', 'info');

        // Récupérer le corps de la requête
        $payload = file_get_contents('php://input');
        
        if (empty($payload)) {
            $this->logger->log('Empty webhook payload', 'error');
            $this->send_response(400, 'Empty payload');
            return;
        }

        // Décoder le JSON
        $data = json_decode($payload, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->log('Invalid JSON payload: ' . json_last_error_msg(), 'error');
            $this->send_response(400, 'Invalid JSON');
            return;
        }

        $this->logger->log('Webhook data: ' . wp_json_encode($data), 'debug');

        // Vérifier la signature si configurée
        if (!empty($this->webhook_secret)) {
            $signature = isset($_SERVER['HTTP_X_GENIUSPAY_SIGNATURE']) 
                ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_GENIUSPAY_SIGNATURE'])) 
                : '';

            if (!$this->verify_signature($payload, $signature)) {
                $this->logger->log('Invalid webhook signature', 'error');
                $this->send_response(401, 'Invalid signature');
                return;
            }
        }

        // Traiter l'événement
        $event_type = isset($data['event']) ? sanitize_text_field($data['event']) : '';
        $transaction_data = isset($data['data']) ? $data['data'] : array();

        switch ($event_type) {
            case 'payment.completed':
            case 'transaction.completed':
                $this->handle_payment_completed($transaction_data);
                break;

            case 'payment.failed':
            case 'transaction.failed':
                $this->handle_payment_failed($transaction_data);
                break;

            case 'payment.cancelled':
            case 'transaction.cancelled':
                $this->handle_payment_cancelled($transaction_data);
                break;

            case 'payment.pending':
            case 'transaction.pending':
                $this->handle_payment_pending($transaction_data);
                break;

            case 'refund.completed':
                $this->handle_refund_completed($transaction_data);
                break;

            default:
                $this->logger->log('Unknown webhook event: ' . $event_type, 'warning');
                $this->send_response(200, 'Event type not handled');
                return;
        }

        $this->send_response(200, 'Webhook processed');
    }

    /**
     * Vérifie la signature du webhook
     */
    private function verify_signature($payload, $signature) {
        if (empty($signature) || empty($this->webhook_secret)) {
            return false;
        }

        $expected_signature = hash_hmac('sha256', $payload, $this->webhook_secret);
        $clean_signature    = str_replace('sha256=', '', $signature);

        return hash_equals($expected_signature, $clean_signature);
    }

    /**
     * Gère un paiement complété
     */
    private function handle_payment_completed($data) {
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
        if (isset($data['fees'])) {
            $order->update_meta_data('_geniuspay_fees', floatval($data['fees']));
        }
        if (isset($data['net_amount'])) {
            $order->update_meta_data('_geniuspay_net_amount', floatval($data['net_amount']));
        }

        // Marquer la commande comme payée
        $order->payment_complete(isset($data['reference']) ? $data['reference'] : '');
        
        // Ajouter une note
        $note = sprintf(
            /* translators: %s: Payment reference number */
            __('Paiement GeniusPay reçu. Référence: %s', 'geniuspay-for-woocommerce'),
            isset($data['reference']) ? $data['reference'] : 'N/A'
        );
        $order->add_order_note($note);

        $order->save();

        $this->logger->log('Order #' . $order->get_id() . ' marked as paid', 'info');

        // Action pour les développeurs
        do_action('geniuspay_payment_completed', $order, $data);
    }

    /**
     * Gère un paiement échoué
     */
    private function handle_payment_failed($data) {
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

        // Mettre à jour le statut
        $order->update_status('failed', sprintf(
            /* translators: %s: Failure reason */
            __('Paiement GeniusPay échoué. Raison: %s', 'geniuspay-for-woocommerce'),
            isset($data['status_message']) ? $data['status_message'] : __('Non spécifiée', 'geniuspay-for-woocommerce')
        ));

        $order->save();

        $this->logger->log('Order #' . $order->get_id() . ' marked as failed', 'info');

        // Action pour les développeurs
        do_action('geniuspay_payment_failed', $order, $data);
    }

    /**
     * Gère un paiement annulé
     */
    private function handle_payment_cancelled($data) {
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

        // Mettre à jour le statut
        $order->update_status('cancelled', __('Paiement GeniusPay annulé par le client.', 'geniuspay-for-woocommerce'));
        $order->save();

        $this->logger->log('Order #' . $order->get_id() . ' marked as cancelled', 'info');

        // Action pour les développeurs
        do_action('geniuspay_payment_cancelled', $order, $data);
    }

    /**
     * Gère un paiement en attente
     */
    private function handle_payment_pending($data) {
        $order = $this->get_order_from_webhook($data);
        
        if (!$order) {
            $this->logger->log('Order not found for pending payment', 'error');
            return;
        }

        // Mettre à jour le statut si nécessaire
        if ($order->get_status() === 'pending') {
            $order->update_status('on-hold', __('Paiement GeniusPay en cours de traitement.', 'geniuspay-for-woocommerce'));
            $order->save();
        }

        $this->logger->log('Order #' . $order->get_id() . ' pending payment notification received', 'info');

        // Action pour les développeurs
        do_action('geniuspay_payment_pending', $order, $data);
    }

    /**
     * Gère un remboursement complété
     */
    private function handle_refund_completed($data) {
        $order = $this->get_order_from_webhook($data);
        
        if (!$order) {
            $this->logger->log('Order not found for refund', 'error');
            return;
        }

        $refund_amount = isset($data['refund_amount']) ? floatval($data['refund_amount']) : $order->get_total();
        
        // Créer le remboursement WooCommerce
        $refund = wc_create_refund(array(
            'amount' => $refund_amount,
            'reason' => isset($data['refund_reason']) ? sanitize_text_field($data['refund_reason']) : __('Remboursement GeniusPay', 'geniuspay-for-woocommerce'),
            'order_id' => $order->get_id(),
            'refund_payment' => false, // Le remboursement est déjà fait côté GeniusPay
        ));

        if (is_wp_error($refund)) {
            $this->logger->log('Failed to create refund for order #' . $order->get_id() . ': ' . $refund->get_error_message(), 'error');
            return;
        }

        $order->add_order_note(sprintf(
            /* translators: %s: Refund amount */
            __('Remboursement GeniusPay de %s reçu.', 'geniuspay-for-woocommerce'),
            wc_price($refund_amount)
        ));

        $this->logger->log('Refund created for order #' . $order->get_id(), 'info');

        // Action pour les développeurs
        do_action('geniuspay_refund_completed', $order, $data, $refund);
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
     * Envoie une réponse HTTP
     */
    private function send_response($status_code, $message) {
        status_header($status_code);
        header('Content-Type: application/json');
        
        echo wp_json_encode(array(
            'success' => $status_code === 200,
            'message' => $message,
        ));
        
        exit;
    }
}

