<?php
/**
 * Classe pour interagir avec l'API GeniusPay
 *
 * @package GeniusPay_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe GeniusPay_API
 */
class GeniusPay_API {

    /**
     * URL de base de l'API
     */
    const API_BASE_URL = 'https://pay.genius.ci/api/v1/merchant';

    /**
     * Clé API publique
     */
    private $api_key;

    /**
     * Clé API secrète
     */
    private $api_secret;

    /**
     * Mode sandbox
     */
    private $sandbox_mode;

    /**
     * Logger
     */
    private $logger;

    /**
     * Constructeur
     */
    public function __construct($api_key = '', $api_secret = '', $sandbox_mode = true) {
        $this->api_key = $api_key;
        $this->api_secret = $api_secret;
        $this->sandbox_mode = $sandbox_mode;
        $this->logger = new GeniusPay_Logger();
    }

    /**
     * Définit les clés API
     */
    public function set_credentials($api_key, $api_secret) {
        $this->api_key = $api_key;
        $this->api_secret = $api_secret;
    }

    /**
     * Crée un paiement
     *
     * @param array $data Données du paiement
     * @return array|WP_Error
     */
    public function create_payment($data) {
        return $this->request('POST', '/payments', $data);
    }

    /**
     * Récupère un paiement par sa référence
     *
     * @param string $reference Référence du paiement
     * @return array|WP_Error
     */
    public function get_payment($reference) {
        return $this->request('GET', '/payments/' . $reference);
    }

    /**
     * Liste les paiements
     *
     * @param array $params Paramètres de filtrage
     * @return array|WP_Error
     */
    public function list_payments($params = array()) {
        return $this->request('GET', '/payments', $params);
    }

    /**
     * Vérifie le statut d'un paiement
     *
     * @param string $reference Référence du paiement
     * @return array|WP_Error
     */
    public function check_payment_status($reference) {
        $response = $this->get_payment($reference);
        
        if (is_wp_error($response)) {
            return $response;
        }

        return isset($response['data']['status']) ? $response['data']['status'] : 'unknown';
    }

    /**
     * Effectue une requête à l'API
     *
     * @param string $method Méthode HTTP
     * @param string $endpoint Endpoint de l'API
     * @param array $data Données à envoyer
     * @return array|WP_Error
     */
    private function request($method, $endpoint, $data = array()) {
        $url = self::API_BASE_URL . $endpoint;

        // Ajouter les paramètres GET à l'URL
        if ($method === 'GET' && !empty($data)) {
            $url = add_query_arg($data, $url);
        }

        $args = array(
            'method' => $method,
            'timeout' => 30,
            'headers' => array(
                'X-API-Key' => $this->api_key,
                'X-API-Secret' => $this->api_secret,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'GeniusPay-WooCommerce/' . GENIUSPAY_WC_VERSION,
            ),
        );

        // Ajouter le corps pour les requêtes POST/PUT
        if (in_array($method, array('POST', 'PUT', 'PATCH')) && !empty($data)) {
            $args['body'] = wp_json_encode($data);
        }

        // Log de la requête
        $this->logger->log('API Request: ' . $method . ' ' . $url, 'info');
        if (!empty($data) && $method !== 'GET') {
            $this->logger->log('Request Data: ' . wp_json_encode($this->mask_sensitive_data($data)), 'debug');
        }

        // Effectuer la requête
        $response = wp_remote_request($url, $args);

        // Vérifier les erreurs de connexion
        if (is_wp_error($response)) {
            $this->logger->log('API Connection Error: ' . $response->get_error_message(), 'error');
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        // Log de la réponse (toujours logger en cas d'erreur)
        if ($response_code >= 400) {
            $this->logger->log('API Response (' . $response_code . '): ' . $response_body, 'error');
        } else {
            $this->logger->log('API Response (' . $response_code . '): ' . $response_body, 'debug');
        }

        // Gérer les erreurs HTTP
        if ($response_code >= 400) {
            // Essayer de récupérer le message d'erreur de différentes structures de réponse
            $error_message = __('Erreur API GeniusPay', 'geniuspay-for-woocommerce');
            $error_code = 'api_error';
            
            if (isset($response_data['error']['message'])) {
                $error_message = $response_data['error']['message'];
            } elseif (isset($response_data['message'])) {
                $error_message = $response_data['message'];
            }
            
            if (isset($response_data['error']['code'])) {
                $error_code = $response_data['error']['code'];
            } elseif (isset($response_data['error_code'])) {
                $error_code = $response_data['error_code'];
            }

            $this->logger->log('API Error: ' . $error_message . ' (Code: ' . $error_code . ')', 'error');

            return new WP_Error($error_code, $error_message, array(
                'status' => $response_code,
                'response' => $response_data,
            ));
        }

        return $response_data;
    }

    /**
     * Masque les données sensibles pour le logging
     *
     * @param array $data Données à masquer
     * @return array
     */
    private function mask_sensitive_data($data) {
        $sensitive_keys = array('api_key', 'api_secret', 'card_number', 'cvv', 'password');
        
        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), $sensitive_keys)) {
                $data[$key] = '***MASKED***';
            } elseif (is_array($value)) {
                $data[$key] = $this->mask_sensitive_data($value);
            }
        }

        return $data;
    }

    /**
     * Vérifie si les credentials sont configurés
     *
     * @return bool
     */
    public function has_credentials() {
        return !empty($this->api_key) && !empty($this->api_secret);
    }

    /**
     * Teste la connexion à l'API
     *
     * @return bool|WP_Error
     */
    public function test_connection() {
        if (!$this->has_credentials()) {
            return new WP_Error('missing_credentials', __('Clés API non configurées', 'geniuspay-for-woocommerce'));
        }

        // Essayer de lister les paiements (endpoint le plus simple)
        $response = $this->list_payments(array('per_page' => 1));

        if (is_wp_error($response)) {
            return $response;
        }

        return true;
    }
}
