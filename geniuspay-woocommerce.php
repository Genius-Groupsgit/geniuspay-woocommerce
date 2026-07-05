<?php
/**
 * Plugin Name: GeniusPay for WooCommerce
 * Plugin URI: https://geniuspay.ci
 * Description: Acceptez les paiements Mobile Money (Wave, Orange Money, MTN Money, etc...) et carte bancaire via GeniusPay
 * Version: 1.0.4
 * Author: GeniusPay
 * Author URI: https://genius.ci
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: geniuspay-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.4
 */

if (!defined('ABSPATH')) {
    exit;
}

// Définir les constantes du plugin
define('GENIUSPAY_WC_VERSION', '1.0.4');
define('GENIUSPAY_WC_PLUGIN_FILE', __FILE__);
define('GENIUSPAY_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GENIUSPAY_WC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('GENIUSPAY_WC_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Classe principale du plugin GeniusPay WooCommerce
 */
final class GeniusPay_WooCommerce
{

    /**
     * Instance unique du plugin
     */
    private static $instance = null;

    /**
     * Récupère l'instance unique du plugin
     */
    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructeur
     */
    private function __construct()
    {
        $this->init_hooks();
    }

    /**
     * Initialise les hooks WordPress
     */
    private function init_hooks()
    {
        add_action('plugins_loaded', array($this, 'init'));
        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));

        // Liens dans la page des plugins
        add_filter('plugin_action_links_' . GENIUSPAY_WC_PLUGIN_BASENAME, array($this, 'plugin_action_links'));

        // Déclaration de compatibilité HPOS
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
    }

    /**
     * Initialise le plugin
     */
    public function init()
    {
        // Vérifier si WooCommerce est actif
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        // Charger les classes
        $this->includes();
    }

    /**
     * Inclut les fichiers nécessaires
     */
    private function includes()
    {
        // Logger doit être chargé en premier car utilisé par les autres classes
        require_once GENIUSPAY_WC_PLUGIN_DIR . 'includes/class-geniuspay-logger.php';
        require_once GENIUSPAY_WC_PLUGIN_DIR . 'includes/class-geniuspay-api.php';
        require_once GENIUSPAY_WC_PLUGIN_DIR . 'includes/class-geniuspay-gateway.php';
        require_once GENIUSPAY_WC_PLUGIN_DIR . 'includes/class-geniuspay-webhook.php';
    }

    /**
     * Ajoute la passerelle GeniusPay à WooCommerce
     */
    public function add_gateway($gateways)
    {
        $gateways[] = 'GeniusPay_Gateway';
        return $gateways;
    }

    /**
     * Enqueue les scripts frontend
     */
    public function enqueue_scripts()
    {
        if (is_checkout()) {
            wp_enqueue_style(
                'geniuspay-checkout',
                GENIUSPAY_WC_PLUGIN_URL . 'assets/css/checkout.css',
                array(),
                GENIUSPAY_WC_VERSION
            );

            wp_enqueue_script(
                'geniuspay-checkout',
                GENIUSPAY_WC_PLUGIN_URL . 'assets/js/checkout.js',
                array('jquery'),
                GENIUSPAY_WC_VERSION,
                true
            );

            wp_localize_script('geniuspay-checkout', 'geniuspay_params', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('geniuspay_checkout'),
            ));
        }
    }

    /**
     * Enqueue les scripts admin
     */
    public function admin_enqueue_scripts($hook)
    {
        if ('woocommerce_page_wc-settings' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'geniuspay-admin',
            GENIUSPAY_WC_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            GENIUSPAY_WC_VERSION
        );
    }

    /**
     * Ajoute les liens d'action du plugin
     */
    public function plugin_action_links($links)
    {
        $plugin_links = array(
            '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=geniuspay') . '">' .
            __('Paramètres', 'geniuspay-for-woocommerce') . '</a>',
            '<a href="https://geniuspay.ci" target="_blank">' .
            __('Documentation', 'geniuspay-for-woocommerce') . '</a>',
        );
        return array_merge($plugin_links, $links);
    }

    /**
     * Affiche une notice si WooCommerce n'est pas installé
     */
    public function woocommerce_missing_notice()
    {
        ?>
        <div class="error">
            <p>
                <strong><?php esc_html_e('GeniusPay pour WooCommerce', 'geniuspay-for-woocommerce'); ?></strong>
                <?php esc_html_e('nécessite WooCommerce pour fonctionner. Veuillez installer et activer WooCommerce.', 'geniuspay-for-woocommerce'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Déclare la compatibilité HPOS (High-Performance Order Storage)
     */
    public function declare_hpos_compatibility()
    {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', GENIUSPAY_WC_PLUGIN_FILE, true);
        }
    }
}

/**
 * Fonction pour accéder à l'instance du plugin
 */
function geniuspay_wc()
{
    return GeniusPay_WooCommerce::instance();
}

// Initialiser le plugin
geniuspay_wc();
