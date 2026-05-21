# Changelog

Toutes les modifications notables de ce projet sont documentées dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/),
et ce projet suit la [Gestion Sémantique de Version](https://semver.org/lang/fr/).

## [1.1.0] - 2026-05-21

### Corrigé
- Calcul du montant selon la devise : XOF/XAF en entier, EUR/USD en centimes
- Crash PHP si aucune méthode de paiement n'est sélectionnée par l'admin
- Double initialisation de la classe `GeniusPay_Webhook` au chargement du plugin
- Déclaration du support `refunds` alors que les remboursements ne sont pas implémentés

### Ajouté
- Moov Money dans les options de méthodes de paiement
- `webhook_url` transmise automatiquement lors de la création du paiement
- Gestion du préfixe `sha256=` dans la vérification de signature webhook

## [1.0.0] - 2025-12-14

### Ajouté
- Version initiale du plugin
- Support Wave, Orange Money, MTN Money, Moov Money
- Support cartes bancaires Visa / Mastercard via Paystack
- Mode sandbox pour les tests sans transaction réelle
- Gestion automatique des webhooks (paiement complété, échoué, annulé, remboursé)
- Compatible WooCommerce HPOS (High-Performance Order Storage)
- Multi-devises : XOF, XAF, EUR, USD
- Logs de débogage dans WooCommerce > État > Logs
