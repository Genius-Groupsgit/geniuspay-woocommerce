<?php
/**
 * Passerelle de paiement WooCommerce GeniusPay
 *
 * @package GeniusPay_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe GeniusPay_Gateway
 */
class GeniusPay_Gateway extends WC_Payment_Gateway
{

    /**
     * Instance de l'API
     */
    private $api;

    /**
     * Logger
     */
    private $logger;

    /**
     * Constructeur
     */
    public function __construct()
    {
        $this->id = 'geniuspay';
        $this->icon = GENIUSPAY_WC_PLUGIN_URL . 'assets/images/geniuspay-logo.svg';
        $this->has_fields = true;
        $this->method_title = __('GeniusPay', 'geniuspay-woocommerce');
        $this->method_description = __('Acceptez les paiements Wave, Orange Money, MTN Money et carte bancaire via GeniusPay.', 'geniuspay-woocommerce');
        $this->supports = array(
            'products',
            'refunds',
        );

        // Charger les paramètres
        $this->init_form_fields();
        $this->init_settings();

        // Définir les variables
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->sandbox_mode = 'yes' === $this->get_option('sandbox_mode');

        // Clés API selon le mode
        if ($this->sandbox_mode) {
            $this->api_key = $this->get_option('sandbox_api_key');
            $this->api_secret = $this->get_option('sandbox_api_secret');
        } else {
            $this->api_key = $this->get_option('live_api_key');
            $this->api_secret = $this->get_option('live_api_secret');
        }

        // Méthodes de paiement activées
        $this->enabled_methods = $this->get_option('payment_methods', array('wave', 'orange_money', 'mtn_money', 'card'));

        // Mode checkout GeniusPay (optionnel)
        $this->use_checkout_page = 'yes' === $this->get_option('use_checkout_page', 'no');

        // Initialiser l'API
        $this->api = new GeniusPay_API($this->api_key, $this->api_secret, $this->sandbox_mode);
        $this->logger = new GeniusPay_Logger();

        // Hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_geniuspay_callback', array($this, 'handle_callback'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
        add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);

        // Ajouter les notices admin
        if (is_admin()) {
            add_action('admin_notices', array($this, 'admin_notices'));
        }
    }

    /**
     * Définit les champs du formulaire de configuration
     */
    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Activer/Désactiver', 'geniuspay-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Activer GeniusPay', 'geniuspay-woocommerce'),
                'default' => 'no',
            ),
            'title' => array(
                'title' => __('Titre', 'geniuspay-woocommerce'),
                'type' => 'text',
                'description' => __('Titre affiché au client lors du checkout.', 'geniuspay-woocommerce'),
                'default' => __('Paiement Mobile & Carte', 'geniuspay-woocommerce'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'geniuspay-woocommerce'),
                'type' => 'textarea',
                'description' => __('Description affichée au client lors du checkout.', 'geniuspay-woocommerce'),
                'default' => __('Payez en toute sécurité avec Wave, Orange Money, MTN Money ou votre carte bancaire.', 'geniuspay-woocommerce'),
                'desc_tip' => true,
            ),
            'sandbox_mode' => array(
                'title' => __('Mode Sandbox', 'geniuspay-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Activer le mode sandbox (test)', 'geniuspay-woocommerce'),
                'default' => 'yes',
                'description' => __('En mode sandbox, aucune transaction réelle n\'est effectuée.', 'geniuspay-woocommerce'),
            ),
            'sandbox_api_key' => array(
                'title' => __('Clé API Sandbox', 'geniuspay-woocommerce'),
                'type' => 'text',
                'description' => __('Votre clé API publique sandbox (pk_sandbox_...).', 'geniuspay-woocommerce'),
                'default' => '',
                'desc_tip' => true,
            ),
            'sandbox_api_secret' => array(
                'title' => __('Secret API Sandbox', 'geniuspay-woocommerce'),
                'type' => 'password',
                'description' => __('Votre clé API secrète sandbox (sk_sandbox_...).', 'geniuspay-woocommerce'),
                'default' => '',
                'desc_tip' => true,
            ),
            'live_api_key' => array(
                'title' => __('Clé API Production', 'geniuspay-woocommerce'),
                'type' => 'text',
                'description' => __('Votre clé API publique production (pk_live_...).', 'geniuspay-woocommerce'),
                'default' => '',
                'desc_tip' => true,
            ),
            'live_api_secret' => array(
                'title' => __('Secret API Production', 'geniuspay-woocommerce'),
                'type' => 'password',
                'description' => __('Votre clé API secrète production (sk_live_...).', 'geniuspay-woocommerce'),
                'default' => '',
                'desc_tip' => true,
            ),
            'use_checkout_page' => array(
                'title' => __('Page de Checkout GeniusPay', 'geniuspay-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Utiliser la page de checkout GeniusPay', 'geniuspay-for-woocommerce'),
                'default' => 'no',
                'description' => __('Si activé, le client choisira son moyen de paiement sur la page GeniusPay. Sinon, il le choisira directement dans WooCommerce.', 'geniuspay-for-woocommerce'),
            ),
            'payment_methods' => array(
                'title' => __('Méthodes de paiement', 'geniuspay-woocommerce'),
                'type' => 'multiselect',
                'class' => 'wc-enhanced-select',
                'description' => __('Sélectionnez les méthodes de paiement à proposer (ignoré si la page de checkout GeniusPay est activée).', 'geniuspay-woocommerce'),
                'default' => array('wave', 'orange_money', 'mtn_money', 'card'),
                'options' => array(
                    'wave' => __('Wave', 'geniuspay-woocommerce'),
                    'orange_money' => __('Orange Money', 'geniuspay-woocommerce'),
                    'mtn_money' => __('MTN Money', 'geniuspay-woocommerce'),
                    'card' => __('Carte bancaire', 'geniuspay-woocommerce'),
                ),
                'desc_tip' => true,
            ),
            'redirect_section' => array(
                'title' => __('URLs de Redirection', 'geniuspay-for-woocommerce'),
                'type' => 'title',
                'description' => __('Configurez les pages de redirection après paiement. Laissez vide pour utiliser les pages par défaut de WooCommerce (page de remerciement / page de commande).', 'geniuspay-for-woocommerce'),
            ),
            'success_url' => array(
                'title' => __('URL de Succès', 'geniuspay-for-woocommerce'),
                'type' => 'text',
                'description' => __('Page vers laquelle le client est redirigé après un paiement réussi. Laissez vide pour utiliser la page de remerciement WooCommerce par défaut.', 'geniuspay-for-woocommerce'),
                'default' => '',
                'desc_tip' => true,
                'placeholder' => home_url('/checkout/order-received/'),
            ),
            'error_url' => array(
                'title' => __('URL d\'Erreur', 'geniuspay-for-woocommerce'),
                'type' => 'text',
                'description' => __('Page vers laquelle le client est redirigé en cas d\'échec du paiement. Laissez vide pour utiliser la page de commande WooCommerce par défaut.', 'geniuspay-for-woocommerce'),
                'default' => '',
                'desc_tip' => true,
                'placeholder' => home_url('/checkout/'),
            ),
            'webhook_section' => array(
                'title' => __('Configuration Webhook', 'geniuspay-woocommerce'),
                'type' => 'title',
                'description' => sprintf(
                    /* translators: %s: Webhook URL */
                    __('Configurez cette URL dans votre tableau de bord GeniusPay : %s', 'geniuspay-for-woocommerce'),
                    '<br><code>' . esc_url($this->get_webhook_url()) . '</code>'
                ),
            ),
            'webhook_secret' => array(
                'title' => __('Secret Webhook (Live)', 'geniuspay-for-woocommerce'),
                'type' => 'password',
                'description' => __('Secret pour vérifier l\'authenticité des webhooks en mode Live.', 'geniuspay-for-woocommerce'),
                'default' => wp_generate_password(32, false),
                'desc_tip' => true,
            ),
            'sandbox_webhook_secret' => array(
                'title' => __('Secret Webhook (Sandbox)', 'geniuspay-for-woocommerce'),
                'type' => 'password',
                'description' => __('Secret pour vérifier l\'authenticité des webhooks en mode Sandbox.', 'geniuspay-for-woocommerce'),
                'default' => wp_generate_password(32, false),
                'desc_tip' => true,
            ),
            'debug_mode' => array(
                'title' => __('Mode Debug', 'geniuspay-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Activer les logs de débogage', 'geniuspay-woocommerce'),
                'default' => 'no',
                'description' => __('Les logs sont enregistrés dans WooCommerce > État > Logs.', 'geniuspay-woocommerce'),
            ),
        );
    }

    /**
     * Affiche le formulaire de paiement
     */
    public function payment_fields()
    {
        // Afficher la description
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }

        // Afficher le badge sandbox
        if ($this->sandbox_mode) {
            echo '<div class="geniuspay-sandbox-notice">';
            echo '<span class="geniuspay-badge geniuspay-badge-warning">🧪 ' . __('Mode Test', 'geniuspay-woocommerce') . '</span>';
            echo '</div>';
        }

        // Si mode checkout GeniusPay activé, pas besoin de sélectionner la méthode ici
        if ($this->use_checkout_page) {
            echo '<div class="geniuspay-checkout-notice">';
            echo '<p>' . esc_html__('Vous serez redirigé vers la page de paiement sécurisée GeniusPay pour choisir votre moyen de paiement.', 'geniuspay-for-woocommerce') . '</p>';
            echo '<div class="geniuspay-methods-preview">';
            echo '<img src="' . GENIUSPAY_WC_PLUGIN_URL . 'assets/images/logo/wave.svg" alt="Wave" title="Wave" class="geniuspay-method-logo">';
            echo '<img src="' . GENIUSPAY_WC_PLUGIN_URL . 'assets/images/logo/orange.svg" alt="Orange Money" title="Orange Money" class="geniuspay-method-logo">';
            echo '<img src="' . GENIUSPAY_WC_PLUGIN_URL . 'assets/images/logo/mtn.svg" alt="MTN Money" title="MTN Money" class="geniuspay-method-logo">';
            echo '<img src="' . GENIUSPAY_WC_PLUGIN_URL . 'assets/images/logo/visa.svg" alt="Visa" title="Carte bancaire" class="geniuspay-method-logo">';
            echo '<img src="' . GENIUSPAY_WC_PLUGIN_URL . 'assets/images/logo/mastercard.svg" alt="Mastercard" title="Carte bancaire" class="geniuspay-method-logo">';
            echo '</div>';
            echo '</div>';
            return;
        }

        // Sélection de la méthode de paiement (mode classique)
        if (count($this->enabled_methods) > 1) {
            echo '<div class="geniuspay-payment-methods">';
            echo '<p class="form-row form-row-wide">';
            echo '<label>' . esc_html__('Choisissez votre méthode de paiement', 'geniuspay-for-woocommerce') . '</label>';
            
            foreach ($this->enabled_methods as $method) {
                $method_label = $this->get_payment_method_label($method);
                $method_icon = $this->get_payment_method_icon($method);

                echo '<label class="geniuspay-method-option">';
                echo '<input type="radio" name="geniuspay_payment_method" value="' . esc_attr($method) . '" ' . checked($method, 'wave', false) . '>';
                echo '<span class="geniuspay-method-icon">' . $method_icon . '</span>';
                echo '<span class="geniuspay-method-label">' . esc_html($method_label) . '</span>';
                echo '</label>';
            }

            echo '</p>';
            echo '</div>';
        } else {
            // Une seule méthode, on la cache
            echo '<input type="hidden" name="geniuspay_payment_method" value="' . esc_attr($this->enabled_methods[0]) . '">';
        }
    }

    /**
     * Valide les champs du formulaire
     */
    public function validate_fields()
    {
        // En mode checkout GeniusPay, pas de validation de méthode nécessaire
        if ($this->use_checkout_page) {
            return true;
        }

        if (empty($_POST['geniuspay_payment_method'])) {
            wc_add_notice(__('Veuillez sélectionner une méthode de paiement.', 'geniuspay-woocommerce'), 'error');
            return false;
        }

        $method = sanitize_text_field($_POST['geniuspay_payment_method']);
        if (!in_array($method, $this->enabled_methods)) {
            wc_add_notice(__('Méthode de paiement invalide.', 'geniuspay-woocommerce'), 'error');
            return false;
        }

        return true;
    }

    /**
     * Traite le paiement
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        if (!$order) {
            return array(
                'result' => 'failure',
                'messages' => __('Commande introuvable.', 'geniuspay-woocommerce'),
            );
        }

        // Récupérer la méthode de paiement sélectionnée (null si mode checkout GeniusPay)
        $payment_method = null;
        if (!$this->use_checkout_page && isset($_POST['geniuspay_payment_method'])) {
            $payment_method = sanitize_text_field($_POST['geniuspay_payment_method']);
        }

        // Récupérer les URLs de redirection configurées (ou fallback WooCommerce)
        $custom_success_url = $this->get_option('success_url', '');
        $custom_error_url = $this->get_option('error_url', '');

        // URL de succès : custom ou page de remerciement WooCommerce
        if (!empty($custom_success_url)) {
            $genius_raw_success_url = $custom_success_url;
            // Ajouter l'order_id et la key si la URL contient des placeholders
            if (strpos($genius_raw_success_url, '{order_id}') !== false) {
                $genius_raw_success_url = str_replace('{order_id}', $order_id, $genius_raw_success_url);
            }
            if (strpos($genius_raw_success_url, '{order_key}') !== false) {
                $genius_raw_success_url = str_replace('{order_key}', $order->get_order_key(), $genius_raw_success_url);
            }
        } else {
            $genius_raw_success_url = $this->get_return_url($order);
        }

        // Si Elementor supprime le domaine (URL commençant par /), on réinjecte le domaine complet du site
        if (strpos($genius_raw_success_url, '/') === 0) {
            $genius_raw_success_url = home_url($genius_raw_success_url);
        }

        // Sécurité : Si l'URL n'a toujours pas le protocole http/https, on force l'URL absolue
        if (!preg_match('~^(?:f|ht)tps?://~i', $genius_raw_success_url)) {
            $genius_raw_success_url = site_url('/' . ltrim($genius_raw_success_url, '/'));
        }

        // URL d'erreur : custom ou page de checkout WooCommerce
        if (!empty($custom_error_url)) {
            $genius_raw_error_url = $custom_error_url;
            if (strpos($genius_raw_error_url, '{order_id}') !== false) {
                $genius_raw_error_url = str_replace('{order_id}', $order_id, $genius_raw_error_url);
            }
        } else {
            $genius_raw_error_url = wc_get_checkout_url() . '?geniuspay_error=1&order_id=' . $order_id;
        }

        if (strpos($genius_raw_error_url, '/') === 0) {
            $genius_raw_error_url = home_url($genius_raw_error_url);
        }
        if (!preg_match('~^(?:f|ht)tps?://~i', $genius_raw_error_url)) {
            $genius_raw_error_url = site_url('/' . ltrim($genius_raw_error_url, '/'));
        }

        // Normaliser le numéro de téléphone au format international
        $phone = $this->normalize_phone_number(
            $order->get_billing_phone(),
            $order->get_billing_country()
        );

        // Préparer les données du paiement
        $payment_data = array(
            'amount' => (float) $order->get_total(), // Montant avec décimales
            'currency' => $order->get_currency(),
            'description' => sprintf(__('Commande #%s', 'geniuspay-woocommerce'), $order->get_order_number()),
            'customer' => array(
                'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
                'phone' => $phone,
                'country' => $order->get_billing_country(),
            ),
            'success_url' => $genius_raw_success_url,
            'error_url' => $genius_raw_error_url,
            'metadata' => array(
                'order_id' => $order_id,
                'order_key' => $order->get_order_key(),
                'site_url' => get_site_url(),
                'woocommerce_version' => WC_VERSION,
                'plugin_version' => GENIUSPAY_WC_VERSION,
            ),
        );

        // Ajouter la méthode de paiement si spécifiée
        if ($payment_method) {
            $payment_data['payment_method'] = $payment_method;
        }

        // Créer le paiement via l'API
        $response = $this->api->create_payment($payment_data);

        if (is_wp_error($response)) {
            $this->logger->log('Payment creation failed for order #' . $order_id . ': ' . $response->get_error_message(), 'error');

            wc_add_notice(
                __('Erreur lors de la création du paiement: ', 'geniuspay-woocommerce') . $response->get_error_message(),
                'error'
            );

            return array(
                'result' => 'failure',
            );
        }

        // Vérifier la réponse (checkout_url ou payment_url selon le mode)
        $redirect_url = $response['data']['checkout_url'] ?? $response['data']['payment_url'] ?? null;

        if (!$redirect_url || !isset($response['data']['reference'])) {
            $this->logger->log('Invalid API response for order #' . $order_id, 'error');
            
            wc_add_notice(__('Réponse invalide de GeniusPay.', 'geniuspay-for-woocommerce'), 'error');

            return array(
                'result' => 'failure',
            );
        }

        // Sauvegarder la référence de transaction
        $order->update_meta_data('_geniuspay_reference', $response['data']['reference']);
        $order->update_meta_data('_geniuspay_payment_method', $payment_method);
        $order->update_meta_data('_geniuspay_environment', $this->sandbox_mode ? 'sandbox' : 'live');

        if (isset($response['data']['gateway_reference'])) {
            $order->update_meta_data('_geniuspay_gateway_reference', $response['data']['gateway_reference']);
        }

        $order->save();

        // Mettre à jour le statut de la commande
        $order->update_status('pending', __('En attente du paiement GeniusPay.', 'geniuspay-woocommerce'));

        // Vider le panier
        WC()->cart->empty_cart();

        $this->logger->log('Payment initiated for order #' . $order_id . ' - Reference: ' . $response['data']['reference'], 'info');

        // Rediriger vers la page de paiement (checkout_url ou payment_url)
        return array(
            'result' => 'success',
            'redirect' => $redirect_url,
        );
    }

    /**
     * Gère le callback de retour
     */
    public function handle_callback()
    {
        // Géré par la classe GeniusPay_Webhook
        $webhook = new GeniusPay_Webhook();
        $webhook->process();
    }

    /**
     * Page de remerciement
     */
    public function thankyou_page($order_id)
    {
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        $reference = $order->get_meta('_geniuspay_reference');
        $status = $order->get_status();

        if ($reference) {
            echo '<div class="geniuspay-thankyou">';
            echo '<p><strong>' . esc_html__('Référence de paiement:', 'geniuspay-for-woocommerce') . '</strong> ' . esc_html($reference) . '</p>';
            
            if ($status === 'pending' || $status === 'on-hold') {
                echo '<p class="geniuspay-pending-notice">';
                echo __('Votre paiement est en cours de traitement. Vous recevrez une confirmation par email.', 'geniuspay-woocommerce');
                echo '</p>';
            }

            echo '</div>';
        }
    }

    /**
     * Instructions dans l'email
     */
    public function email_instructions($order, $sent_to_admin, $plain_text = false)
    {
        if ($order->get_payment_method() !== $this->id) {
            return;
        }

        $reference = $order->get_meta('_geniuspay_reference');

        if ($reference) {
            if ($plain_text) {
                echo "\n" . __('Référence de paiement GeniusPay:', 'geniuspay-woocommerce') . ' ' . $reference . "\n";
            } else {
                echo '<p><strong>' . __('Référence de paiement GeniusPay:', 'geniuspay-woocommerce') . '</strong> ' . esc_html($reference) . '</p>';
            }
        }
    }

    /**
     * Notices admin
     */
    public function admin_notices()
    {
        if (!$this->enabled) {
            return;
        }

        // Vérifier les clés API
        if (!$this->api->has_credentials()) {
            ?>
            <div class="notice notice-warning">
                <p>
                    <strong><?php esc_html_e('GeniusPay:', 'geniuspay-for-woocommerce'); ?></strong>
                    <?php 
                    printf(
                        esc_html__('Les clés API ne sont pas configurées. %sConfigurer maintenant%s', 'geniuspay-woocommerce'),
                        '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=geniuspay') . '">',
                        '</a>'
                    );
                    ?>
                </p>
            </div>
            <?php
        }

        // Avertissement mode sandbox
        if ($this->sandbox_mode && $this->api->has_credentials()) {
            ?>
            <div class="notice notice-info">
                <p>
                    <strong><?php esc_html_e('GeniusPay:', 'geniuspay-woocommerce'); ?></strong>
                    <?php esc_html_e('Le mode sandbox est activé. Aucune transaction réelle ne sera effectuée.', 'geniuspay-woocommerce'); ?>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Retourne l'URL du webhook
     */
    public function get_webhook_url()
    {
        return add_query_arg('wc-api', 'geniuspay_webhook', home_url('/'));
    }

    /**
     * Retourne le libellé d'une méthode de paiement
     */
    private function get_payment_method_label($method)
    {
        $labels = array(
            'wave' => __('Wave', 'geniuspay-woocommerce'),
            'orange_money' => __('Orange Money', 'geniuspay-woocommerce'),
            'mtn_money' => __('MTN Money', 'geniuspay-woocommerce'),
            'card' => __('Carte bancaire', 'geniuspay-woocommerce'),
            'paystack' => __('Paystack', 'geniuspay-woocommerce'),
        );

        return isset($labels[$method]) ? $labels[$method] : $method;
    }

    /**
     * Retourne l'icône d'une méthode de paiement
     */
    private function get_payment_method_icon($method)
    {
        $logo_base_url = GENIUSPAY_WC_PLUGIN_URL . 'assets/images/logo/';

        $icons = array(
            'wave' => '<img src="' . $logo_base_url . 'wave.svg" alt="Wave" class="geniuspay-method-logo">',
            'orange_money' => '<img src="' . $logo_base_url . 'orange.svg" alt="Orange Money" class="geniuspay-method-logo">',
            'mtn_money' => '<img src="' . $logo_base_url . 'mtn.svg" alt="MTN Money" class="geniuspay-method-logo">',
            'card' => '<span class="geniuspay-card-logos"><img src="' . $logo_base_url . 'visa.svg" alt="Visa" class="geniuspay-method-logo"><img src="' . $logo_base_url . 'mastercard.svg" alt="Mastercard" class="geniuspay-method-logo"></span>',
            'paystack' => '<span class="geniuspay-card-logos"><img src="' . $logo_base_url . 'visa.svg" alt="Visa" class="geniuspay-method-logo"><img src="' . $logo_base_url . 'mastercard.svg" alt="Mastercard" class="geniuspay-method-logo"></span>',
        );

        return isset($icons[$method]) ? $icons[$method] : '<span class="geniuspay-method-emoji">💰</span>';
    }

    /**
     * Vérifie si la passerelle est disponible
     */
    public function is_available()
    {
        if (!parent::is_available()) {
            return false;
        }

        // Vérifier les clés API
        if (!$this->api->has_credentials()) {
            return false;
        }

        // Vérifier la devise (GeniusPay supporte principalement XOF)
        $currency = get_woocommerce_currency();
        $supported_currencies = array('XOF', 'XAF', 'EUR', 'USD');

        if (!in_array($currency, $supported_currencies)) {
            return false;
        }

        return true;
    }

    /**
     * Traite un remboursement
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        // Les remboursements ne sont pas encore supportés par l'API GeniusPay
        return new WP_Error('refund_not_supported', __('Les remboursements automatiques ne sont pas encore disponibles. Veuillez contacter le support GeniusPay.', 'geniuspay-woocommerce'));
    }

    /**
     * Normalise le numéro de téléphone au format international
     * 
     * @param string $phone Numéro de téléphone brut
     * @param string $country Code pays ISO 2 (ex: CI, FR, SN)
     * @return string Numéro normalisé avec indicatif pays
     */
    private function normalize_phone_number($phone, $country = '')
    {
        if (empty($phone)) {
            return '';
        }

        // Supprimer tous les caractères non numériques sauf le +
        $phone = preg_replace('/[^0-9+]/', '', $phone);

        // Si déjà au format international (+XXX ou 00XXX), retourner tel quel
        if (strpos($phone, '+') === 0) {
            return $phone; // Déjà avec +
        }

        if (strpos($phone, '00') === 0) {
            return '+' . substr($phone, 2); // Convertir 00XXX en +XXX
        }

        // Indicatifs téléphoniques par pays (principaux pays africains)
        $country_codes = array(
            'CI' => '225', // Côte d'Ivoire
            'SN' => '221', // Sénégal
            'BJ' => '229', // Bénin
            'TG' => '228', // Togo
            'BF' => '226', // Burkina Faso
            'ML' => '223', // Mali
            'NE' => '227', // Niger
            'GN' => '224', // Guinée
            'CM' => '237', // Cameroun
            'GA' => '241', // Gabon
            'CD' => '243', // RD Congo
            'CG' => '242', // Congo
            'RW' => '250', // Rwanda
            'KE' => '254', // Kenya
            'UG' => '256', // Ouganda
            'TZ' => '255', // Tanzanie
            'ZM' => '260', // Zambie
            'GH' => '233', // Ghana
            'NG' => '234', // Nigeria
            'MA' => '212', // Maroc
            'TN' => '216', // Tunisie
            'DZ' => '213', // Algérie
            'EG' => '20',  // Égypte
            'ZA' => '27',  // Afrique du Sud
            'FR' => '33',  // France
            'BE' => '32',  // Belgique
            'CH' => '41',  // Suisse
            'CA' => '1',   // Canada
            'US' => '1',   // États-Unis
        );

        // Si le numéro commence déjà par un indicatif connu, retourner avec +
        foreach ($country_codes as $code) {
            if (strpos($phone, $code) === 0) {
                return '+' . $phone;
            }
        }

        // Si le numéro commence par 0 (format local), retirer le 0 et ajouter l'indicatif
        if (strpos($phone, '0') === 0 && !empty($country) && isset($country_codes[$country])) {
            $phone = substr($phone, 1); // Retirer le 0 initial
            return '+' . $country_codes[$country] . $phone;
        }

        // Si on a un code pays mais le numéro ne commence pas par 0
        if (!empty($country) && isset($country_codes[$country])) {
            // Vérifier si le numéro ne commence pas déjà par l'indicatif
            if (strpos($phone, $country_codes[$country]) !== 0) {
                return '+' . $country_codes[$country] . $phone;
            }
            return '+' . $phone;
        }

        // Par défaut, retourner le numéro nettoyé avec + si pas d'indicatif détecté
        return '+' . $phone;
    }
}
