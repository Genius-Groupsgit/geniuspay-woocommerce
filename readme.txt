=== GeniusPay for WooCommerce ===
Contributors: geniuspay, geniusgroups
Donate link: https://pay.genius.ci
Tags: payment, woocommerce, wave, orange money, mobile money
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Acceptez les paiements Wave, Orange Money, MTN Money et carte bancaire via GeniusPay sur votre boutique WooCommerce.

== Description ==

GeniusPay for WooCommerce vous permet d'accepter facilement les paiements mobiles et par carte sur votre boutique en ligne.

**Méthodes de paiement supportées :**

* 🌊 **Wave** - Paiement mobile populaire au Sénégal et Côte d'Ivoire
* 🟠 **Orange Money** - Paiement mobile Orange
* 🟡 **MTN Money** - Paiement mobile MTN
* 🔵 **Moov Money** - Paiement mobile Moov
* 💳 **Carte bancaire** - Visa, Mastercard via Paystack

**Fonctionnalités :**

* ✅ Installation simple et rapide
* ✅ Mode sandbox pour les tests
* ✅ Webhooks automatiques pour mise à jour des commandes
* ✅ Compatible avec WooCommerce HPOS
* ✅ Multi-devises (XOF, XAF, EUR, USD)
* ✅ Logs de débogage
* ✅ Traduction française

**Pourquoi choisir GeniusPay ?**

GeniusPay est la solution de paiement conçue pour l'Afrique de l'Ouest. Notre plateforme vous permet d'accepter les paiements les plus populaires de la région avec des frais compétitifs et une intégration simple.

== Installation ==

= Installation automatique =

1. Dans votre tableau de bord WordPress, allez dans Extensions > Ajouter
2. Recherchez "GeniusPay"
3. Cliquez sur "Installer" puis "Activer"

= Installation manuelle =

1. Téléchargez le plugin
2. Décompressez l'archive dans `/wp-content/plugins/geniuspay-woocommerce/`
3. Activez le plugin dans le menu Extensions

= Configuration =

1. Créez un compte sur [GeniusPay](https://pay.genius.ci)
2. Récupérez vos clés API dans Paramètres > API
3. Dans WordPress, allez dans WooCommerce > Paramètres > Paiements > GeniusPay
4. Entrez vos clés API
5. Configurez le webhook dans votre dashboard GeniusPay

== Frequently Asked Questions ==

= Ai-je besoin d'un compte GeniusPay ? =

Oui, vous devez créer un compte marchand sur [pay.genius.ci](https://pay.genius.ci) pour obtenir vos clés API.

= Comment tester le plugin ? =

Activez le "Mode Sandbox" dans les paramètres et utilisez vos clés API sandbox. Aucune transaction réelle ne sera effectuée.

= Quelles devises sont supportées ? =

GeniusPay supporte XOF (Franc CFA BCEAO), XAF (Franc CFA BEAC), EUR et USD.

= Les webhooks ne fonctionnent pas, que faire ? =

1. Vérifiez que l'URL du webhook est correctement configurée dans votre dashboard GeniusPay
2. Assurez-vous que votre site est accessible publiquement (pas en localhost)
3. Activez le mode debug pour voir les logs dans WooCommerce > État > Logs

= Comment obtenir de l'aide ? =

Consultez notre [documentation](https://pay.genius.ci/docs/sdk) ou contactez-nous à pay@genius.ci

== Screenshots ==

1. Configuration du plugin
2. Sélection du moyen de paiement au checkout
3. Page de paiement Wave
4. Confirmation de commande

== Changelog ==

= 1.0.0 =
* Version initiale
* Support Wave, Orange Money, MTN Money, Moov Money
* Support cartes bancaires via Paystack
* Gestion automatique des webhooks
* Mode sandbox pour les tests
* Compatible WooCommerce HPOS

== Upgrade Notice ==

= 1.0.0 =
Version initiale du plugin GeniusPay pour WooCommerce.
