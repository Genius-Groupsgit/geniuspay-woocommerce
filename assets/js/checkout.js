/**
 * GeniusPay WooCommerce - Checkout Scripts
 */

(function($) {
    'use strict';

    var GeniusPayCheckout = {
        /**
         * Initialisation
         */
        init: function() {
            this.bindEvents();
            this.initPaymentMethods();
        },

        /**
         * Liaison des événements
         */
        bindEvents: function() {
            // Sélection de méthode de paiement
            $(document.body).on('change', 'input[name="geniuspay_payment_method"]', this.onMethodChange);
            
            // Mise à jour du checkout
            $(document.body).on('updated_checkout', this.onCheckoutUpdated.bind(this));
            
            // Soumission du formulaire
            $(document.body).on('checkout_error', this.onCheckoutError.bind(this));
        },

        /**
         * Initialise les méthodes de paiement
         */
        initPaymentMethods: function() {
            // Sélectionner la première méthode par défaut si aucune n'est sélectionnée
            var $methods = $('input[name="geniuspay_payment_method"]');
            if ($methods.length > 0 && !$methods.filter(':checked').length) {
                $methods.first().prop('checked', true).trigger('change');
            }
        },

        /**
         * Changement de méthode de paiement
         */
        onMethodChange: function() {
            var $this = $(this);
            var method = $this.val();
            
            // Mettre à jour le style des options
            $('.geniuspay-method-option').removeClass('selected');
            $this.closest('.geniuspay-method-option').addClass('selected');
            
            // Déclencher un événement personnalisé
            $(document.body).trigger('geniuspay_method_changed', [method]);
        },

        /**
         * Checkout mis à jour
         */
        onCheckoutUpdated: function() {
            this.initPaymentMethods();
            
            // Vérifier si GeniusPay est sélectionné
            var $geniuspay = $('#payment_method_geniuspay');
            if ($geniuspay.is(':checked')) {
                this.showPaymentMethods();
            }
        },

        /**
         * Erreur de checkout
         */
        onCheckoutError: function() {
            // Retirer l'état de chargement
            $('.geniuspay-payment-methods').removeClass('geniuspay-loading');
        },

        /**
         * Affiche les méthodes de paiement
         */
        showPaymentMethods: function() {
            $('.geniuspay-payment-methods').slideDown(200);
        },

        /**
         * Cache les méthodes de paiement
         */
        hidePaymentMethods: function() {
            $('.geniuspay-payment-methods').slideUp(200);
        },

        /**
         * Affiche un message d'erreur
         */
        showError: function(message) {
            var $error = $('<div class="geniuspay-error"><strong>Erreur GeniusPay</strong>' + message + '</div>');
            
            // Retirer les erreurs existantes
            $('.geniuspay-error').remove();
            
            // Ajouter la nouvelle erreur
            $('.geniuspay-payment-methods').prepend($error);
            
            // Scroll vers l'erreur
            $('html, body').animate({
                scrollTop: $error.offset().top - 100
            }, 500);
        },

        /**
         * Retire les messages d'erreur
         */
        clearErrors: function() {
            $('.geniuspay-error').remove();
        }
    };

    // Initialiser quand le DOM est prêt
    $(document).ready(function() {
        GeniusPayCheckout.init();
    });

    // Exposer l'objet globalement pour les extensions
    window.GeniusPayCheckout = GeniusPayCheckout;

})(jQuery);
