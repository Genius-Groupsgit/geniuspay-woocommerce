# GeniusPay pour WooCommerce

Extension WordPress pour accepter les paiements via GeniusPay sur votre boutique WooCommerce.

![Version](https://img.shields.io/badge/version-1.0.0-blue)
![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-blue)
![WooCommerce](https://img.shields.io/badge/WooCommerce-5.0%2B-purple)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple)

## 🚀 Fonctionnalités

- **Paiements Mobile Money** : Wave, Orange Money, MTN Money, Moov Money
- **Paiements par carte** : Visa, Mastercard via Paystack
- **Mode Sandbox** : Testez sans transactions réelles
- **Webhooks automatiques** : Mise à jour des commandes en temps réel
- **Compatible HPOS** : Support du stockage haute performance WooCommerce
- **Multi-devises** : XOF, XAF, EUR, USD

## 📋 Prérequis

- WordPress 5.8 ou supérieur
- WooCommerce 5.0 ou supérieur
- PHP 7.4 ou supérieur
- Un compte marchand GeniusPay ([Créer un compte](https://pay.genius.ci))

## 📦 Installation

### Installation manuelle

1. Téléchargez le plugin
2. Décompressez l'archive dans `/wp-content/plugins/geniuspay-woocommerce/`
3. Activez le plugin dans WordPress > Extensions

### Installation via WordPress

1. Allez dans Extensions > Ajouter
2. Recherchez "GeniusPay"
3. Cliquez sur "Installer" puis "Activer"

## ⚙️ Configuration

### 1. Récupérer vos clés API

1. Connectez-vous à votre [tableau de bord GeniusPay](https://pay.genius.ci/dashboard)
2. Allez dans **Paramètres > API**
3. Copiez vos clés Sandbox et/ou Production

### 2. Configurer le plugin

1. Dans WordPress, allez dans **WooCommerce > Paramètres > Paiements**
2. Cliquez sur **GeniusPay**
3. Configurez les paramètres :

| Paramètre | Description |
|-----------|-------------|
| **Activer** | Activer/désactiver la passerelle |
| **Titre** | Texte affiché au client |
| **Mode Sandbox** | Activer pour les tests |
| **Clé API Sandbox** | `pk_sandbox_...` |
| **Secret API Sandbox** | `sk_sandbox_...` |
| **Clé API Production** | `pk_live_...` |
| **Secret API Production** | `sk_live_...` |
| **Méthodes de paiement** | Sélectionner les moyens de paiement |

### 3. Configurer le Webhook

1. Copiez l'URL du webhook affichée dans les paramètres
2. Dans votre tableau de bord GeniusPay, allez dans **Paramètres > Webhooks**
3. Ajoutez l'URL : `https://votresite.com/?wc-api=geniuspay_webhook`
4. Sélectionnez les événements à recevoir

## 🔗 Flux de paiement

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   Client    │────▶│ WooCommerce │────▶│  GeniusPay  │────▶│   Gateway   │
│  (Checkout) │     │   (Plugin)  │     │    (API)    │     │ (Wave/etc)  │
└─────────────┘     └─────────────┘     └─────────────┘     └─────────────┘
      │                   │                   │                   │
      │  Passe commande   │                   │                   │
      │──────────────────▶│                   │                   │
      │                   │  POST /payments   │                   │
      │                   │──────────────────▶│                   │
      │                   │                   │  Init paiement    │
      │                   │                   │──────────────────▶│
      │                   │◀──────────────────│                   │
      │   Redirect vers   │   payment_url     │                   │
      │   page paiement   │                   │                   │
      │◀──────────────────│                   │                   │
      │                   │                   │                   │
      │         Paiement mobile / carte       │                   │
      │──────────────────────────────────────────────────────────▶│
      │                   │                   │                   │
      │                   │     Webhook       │                   │
      │                   │◀──────────────────│                   │
      │                   │  (completed)      │                   │
      │   Email de        │                   │                   │
      │   confirmation    │                   │                   │
      │◀──────────────────│                   │                   │
```

## 🧪 Mode Test

En mode sandbox :
- Aucune transaction réelle n'est effectuée
- Utilisez les clés `pk_sandbox_...` et `sk_sandbox_...`
- Un badge "Mode Test" s'affiche au checkout

### Cartes de test

| Numéro | Résultat |
|--------|----------|
| 4084 0841 1111 1111 | Succès |
| 4084 0841 2222 2222 | Échec |

## 🔧 Hooks & Filtres

### Actions disponibles

```php
// Après un paiement réussi
add_action('geniuspay_payment_completed', function($order, $data) {
    // Votre code
}, 10, 2);

// Après un paiement échoué
add_action('geniuspay_payment_failed', function($order, $data) {
    // Votre code
}, 10, 2);

// Après un paiement annulé
add_action('geniuspay_payment_cancelled', function($order, $data) {
    // Votre code
}, 10, 2);

// Après un remboursement
add_action('geniuspay_refund_completed', function($order, $data, $refund) {
    // Votre code
}, 10, 3);
```

### Filtres disponibles

```php
// Modifier les données envoyées à l'API
add_filter('geniuspay_payment_data', function($data, $order) {
    $data['metadata']['custom_field'] = 'value';
    return $data;
}, 10, 2);
```

## 📊 Métadonnées de commande

Le plugin stocke ces métadonnées sur chaque commande :

| Clé | Description |
|-----|-------------|
| `_geniuspay_reference` | Référence de transaction GeniusPay |
| `_geniuspay_gateway_reference` | Référence du gateway (Wave, etc.) |
| `_geniuspay_payment_method` | Méthode utilisée |
| `_geniuspay_environment` | sandbox ou live |
| `_geniuspay_fees` | Frais de transaction |
| `_geniuspay_net_amount` | Montant net reçu |

## 🐛 Débogage

1. Activez le **Mode Debug** dans les paramètres
2. Les logs sont accessibles dans **WooCommerce > État > Logs**
3. Filtrez par source : `geniuspay`

## ❓ FAQ

### Le paiement échoue avec "Clés API non configurées"

Vérifiez que vous avez bien renseigné les clés API correspondant au mode actif (sandbox ou production).

### Les webhooks ne fonctionnent pas

1. Vérifiez que l'URL du webhook est correctement configurée
2. Assurez-vous que votre serveur est accessible publiquement
3. Vérifiez les logs pour les erreurs de signature

### La devise n'est pas supportée

GeniusPay supporte : XOF, XAF, EUR, USD. Changez la devise dans WooCommerce > Paramètres > Général.

## 📞 Support

- **Documentation** : [pay.genius.ci/doc](https://pay.genius.ci/doc)
- **Email** : pay@genius.ci
- **Site web** : [pay.genius.ci](https://pay.genius.ci)

## 📄 Licence

Ce plugin est distribué sous licence GPL-2.0+. Voir le fichier [LICENSE](LICENSE) pour plus de détails.

## 🔄 Changelog

### 1.0.0 (2025-12-14)
- Version initiale
- Support Wave, Orange Money, MTN Money, Moov Money
- Support cartes via Paystack
- Support des devises XOF, XAF, EUR, USD
- Gestion des webhooks
- Compatible HPOS WooCommerce
