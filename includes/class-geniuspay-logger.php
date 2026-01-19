<?php
/**
 * Logger pour GeniusPay
 *
 * @package GeniusPay_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe GeniusPay_Logger
 */
class GeniusPay_Logger {

    /**
     * Source du log
     */
    const LOG_SOURCE = 'geniuspay';

    /**
     * Instance du logger WooCommerce
     */
    private $wc_logger;

    /**
     * Mode debug activé
     */
    private $debug_mode;

    /**
     * Constructeur
     */
    public function __construct() {
        $this->debug_mode = $this->is_debug_mode();
        
        if (function_exists('wc_get_logger')) {
            $this->wc_logger = wc_get_logger();
        }
    }

    /**
     * Vérifie si le mode debug est activé
     */
    private function is_debug_mode() {
        $gateway_settings = get_option('woocommerce_geniuspay_settings', array());
        return isset($gateway_settings['debug_mode']) && $gateway_settings['debug_mode'] === 'yes';
    }

    /**
     * Enregistre un message
     *
     * @param string $message Message à logger
     * @param string $level Niveau du log (debug, info, warning, error)
     */
    public function log($message, $level = 'info') {
        // Ne pas logger les messages debug si le mode debug n'est pas activé
        if ($level === 'debug' && !$this->debug_mode) {
            return;
        }

        // Ne pas logger si pas en mode debug et pas une erreur/warning
        if (!$this->debug_mode && !in_array($level, array('error', 'warning'))) {
            return;
        }

        if ($this->wc_logger) {
            $context = array('source' => self::LOG_SOURCE);
            
            switch ($level) {
                case 'debug':
                    $this->wc_logger->debug($message, $context);
                    break;
                case 'info':
                    $this->wc_logger->info($message, $context);
                    break;
                case 'warning':
                    $this->wc_logger->warning($message, $context);
                    break;
                case 'error':
                    $this->wc_logger->error($message, $context);
                    break;
                default:
                    $this->wc_logger->info($message, $context);
            }
        }

        // Aussi écrire dans error_log en mode debug
        if ($this->debug_mode && WP_DEBUG) {
            error_log('[GeniusPay][' . strtoupper($level) . '] ' . $message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }

    /**
     * Log de debug
     */
    public function debug($message) {
        $this->log($message, 'debug');
    }

    /**
     * Log d'info
     */
    public function info($message) {
        $this->log($message, 'info');
    }

    /**
     * Log de warning
     */
    public function warning($message) {
        $this->log($message, 'warning');
    }

    /**
     * Log d'erreur
     */
    public function error($message) {
        $this->log($message, 'error');
    }
}
