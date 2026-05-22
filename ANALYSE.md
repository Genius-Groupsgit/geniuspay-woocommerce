# Analyse du plugin GeniusPay for WooCommerce

## Bugs identifiés

### 1. Calcul du montant incorrect
**Fichier :** `includes/class-geniuspay-gateway.php` — ligne 289

**Code actuel :**
```php
'amount' => (int) ($order->get_total() * 100) / 100,
```

**Problème :** Cette expression multiplie par 100 puis divise par 100, ce qui revient à ne rien faire. En plus, le cast `(int)` est appliqué avant la division, ce qui peut provoquer des erreurs d'arrondi.

**Correction recommandée :**
```php
// Pour XOF / XAF (pas de décimales) :
'amount' => (int) $order->get_total(),

// Pour EUR / USD (avec centimes) :
'amount' => (int) round($order->get_total() * 100),
```

---

### 2. Crash potentiel si aucune méthode de paiement n'est sélectionnée
**Fichier :** `includes/class-geniuspay-gateway.php` — ligne 241

**Code actuel :**
```php
echo '<input type="hidden" name="geniuspay_payment_method" value="' . esc_attr($this->enabled_methods[0]) . '">';
```

**Problème :** Si l'administrateur n'a sélectionné aucune méthode de paiement dans les paramètres, `$this->enabled_methods` est un tableau vide et l'accès à l'index `[0]` provoque une erreur PHP fatale.

**Correction recommandée :**
```php
if (!empty($this->enabled_methods)) {
    echo '<input type="hidden" name="geniuspay_payment_method" value="' . esc_attr($this->enabled_methods[0]) . '">';
} else {
    wc_add_notice(__('Aucune méthode de paiement configurée.', 'geniuspay-for-woocommerce'), 'error');
}
```

---

### 3. Moov Money absent des options de la passerelle
**Fichier :** `includes/class-geniuspay-gateway.php` — ligne 154

**Problème :** Le README et la description du plugin mentionnent **Moov Money** comme moyen de paiement supporté, mais il n'apparaît pas dans les options de sélection de la passerelle.

**Correction recommandée :**
```php
'options' => array(
    'wave'         => __('Wave', 'geniuspay-for-woocommerce'),
    'orange_money' => __('Orange Money', 'geniuspay-for-woocommerce'),
    'mtn_money'    => __('MTN Money', 'geniuspay-for-woocommerce'),
    'moov_money'   => __('Moov Money', 'geniuspay-for-woocommerce'), // manquant
    'card'         => __('Carte bancaire', 'geniuspay-for-woocommerce'),
),
```

---

### 4. Double initialisation de la classe Webhook
**Fichiers :**
- `includes/class-geniuspay-webhook.php` — ligne 352
- `includes/class-geniuspay-gateway.php` — ligne 374

**Problème :** La classe `GeniusPay_Webhook` est instanciée une première fois automatiquement en bas du fichier `class-geniuspay-webhook.php`, ce qui enregistre le hook `woocommerce_api_geniuspay_webhook`. Ensuite, la méthode `handle_callback()` de la gateway crée une **deuxième instance** et appelle `process()` manuellement. Cela peut entraîner un traitement en double des webhooks.

**Code problématique dans `class-geniuspay-webhook.php` :**
```php
// Ligne 352 — instanciation globale à éviter
new GeniusPay_Webhook();
```

**Code problématique dans `class-geniuspay-gateway.php` :**
```php
public function handle_callback() {
    $webhook = new GeniusPay_Webhook(); // deuxième instanciation
    $webhook->process();
}
```

**Correction recommandée :** Supprimer l'instanciation globale en bas du fichier webhook et laisser uniquement la gestion via le hook WordPress dans la gateway.

---

## Améliorations recommandées

### 5. Remboursements : déclaration et implémentation incohérentes
**Fichier :** `includes/class-geniuspay-gateway.php` — lignes 36 et 530

**Problème :** La gateway déclare supporter les remboursements :
```php
$this->supports = array(
    'products',
    'refunds', // déclaré
);
```
Mais `process_refund()` retourne systématiquement une erreur :
```php
public function process_refund($order_id, $amount = null, $reason = '') {
    return new WP_Error('refund_not_supported', __('Les remboursements automatiques ne sont pas encore disponibles...'));
}
```

**Options :**
- Retirer `'refunds'` de `$this->supports` pour ne pas induire WooCommerce en erreur.
- Ou implémenter un endpoint de remboursement dans `GeniusPay_API` et compléter `process_refund()`.

---

### 6. URL du webhook non transmise à l'API lors de la création du paiement
**Fichier :** `includes/class-geniuspay-gateway.php` — ligne 288

**Problème :** Le payload envoyé à l'API GeniusPay ne contient pas l'URL du webhook. Si le marchand ne l'a pas configurée manuellement dans le tableau de bord GeniusPay, les événements de paiement (complété, échoué, etc.) ne seront jamais reçus.

**Amélioration recommandée :**
```php
$payment_data = array(
    // ... données existantes ...
    'webhook_url' => $this->get_webhook_url(), // à ajouter
);
```

---

### 7. Signature webhook : préfixe `sha256=` non géré
**Fichier :** `includes/class-geniuspay-webhook.php` — ligne 127

**Problème :** Certaines implémentations d'API (inspirées de Stripe ou GitHub) envoient la signature avec un préfixe `sha256=`. Le vérificateur actuel compare directement le hash brut, ce qui ferait échouer la vérification si GeniusPay adopte ce format.

**Code actuel :**
```php
$expected_signature = hash_hmac('sha256', $payload, $this->webhook_secret);
return hash_equals($expected_signature, $signature);
```

**Amélioration recommandée :**
```php
$clean_signature = str_replace('sha256=', '', $signature);
return hash_equals($expected_signature, $clean_signature);
```

---

## Résumé

| # | Type | Fichier | Gravité |
|---|------|---------|---------|
| 1 | Bug | `class-geniuspay-gateway.php:289` | Haute |
| 2 | Bug | `class-geniuspay-gateway.php:241` | Haute |
| 3 | Bug | `class-geniuspay-gateway.php:154` | Moyenne |
| 4 | Bug | `class-geniuspay-webhook.php:352` | Moyenne |
| 5 | Amélioration | `class-geniuspay-gateway.php:36,530` | Moyenne |
| 6 | Amélioration | `class-geniuspay-gateway.php:288` | Haute |
| 7 | Amélioration | `class-geniuspay-webhook.php:127` | Faible |
