# Changelog - GeniusPay for WooCommerce

## Version 1.0.4 (2026-07-05)

### 🐛 Corrections Critiques

#### Webhooks non reçus / Signature invalide (v3 API)
- **Problème**: Depuis la v3 de l'API, tous les webhooks étaient rejetés avec "Invalid webhook signature"
  - Les webhooks de test arrivaient bien mais la vérification de signature échouait systématiquement
- **Cause**: 
  - Le format de signature a changé avec la v3 de l'API GeniusPay
  - L'ancien code ne supportait qu'un seul format: `HMAC-SHA256(timestamp.payload, secret)` en hex
  - Le DNS a changé de `pay.genius.ci` à `geniuspay.ci` (URL API obsolète)
  - Le champ `sandbox_webhook_secret` était référencé mais absent des paramètres
- **Solution**:
  - `verify_signature()` supporte maintenant 5 formats de signature différents:
    1. v2: `HMAC-SHA256(timestamp.payload, secret)` en hex
    2. v3: `HMAC-SHA256(payload, secret)` en hex (sans timestamp)
    3. v3: `base64(HMAC-SHA256(timestamp.payload, secret))`
    4. v3: `base64(HMAC-SHA256(payload, secret))` (sans timestamp)
    5. `HMAC-SHA256(payload.timestamp, secret)` en hex (ordre inversé)
  - Nettoyage automatique des préfixes (`sha256=...`) dans la signature reçue
  - Logs de débogage détaillés: headers reçus, payload brut, signatures attendues vs reçues
  - URL API mise à jour: `pay.genius.ci` → `geniuspay.ci`
  - Ajout du champ `sandbox_webhook_secret` dans les paramètres du gateway

**Fichiers Modifiés:**
- `geniuspay-woocommerce.php` - Version bump 1.0.3 → 1.0.4, Plugin URI mis à jour
- `includes/class-geniuspay-api.php`:
  - Ligne 20: `API_BASE_URL` mise à jour vers `geniuspay.ci`
- `includes/class-geniuspay-webhook.php`:
  - Lignes 103-134: Logs détaillés sur échec de signature (headers, payload, secret length)
  - Lignes 244-326: `verify_signature()` réécrite pour supporter 5 formats
- `includes/class-geniuspay-gateway.php`:
  - Lignes 175-188: Ajout du champ `sandbox_webhook_secret`

### ⚠️ Action Requise
Après la mise à jour:
1. **Activer le mode Debug** dans WooCommerce > Réglages > Paiements > GeniusPay
2. **Vérifier que le secret webhook** correspond à celui configuré dans le dashboard GeniusPay v3
3. **Renvoyer un webhook de test** depuis le dashboard GeniusPay
4. **Consulter les logs** WooCommerce > État > Logs > `geniuspay` pour voir quel format de signature match

---

## Version 1.0.3 (2026-03-27)

### 🐛 Corrections Critiques

#### Problème de Montant Minimum (EUR/USD)
- **Problème**: Les paiements en EUR/USD étaient rejetés avec l'erreur "validation.min.numeric"
  - Exemple: 4.5 EUR → Rejeté ❌
- **Cause**: Troncature du montant par conversion `(int)` qui transformait 4.5 en 4
- **Solution**: 
  - Suppression du casting `(int)` dans `class-geniuspay-gateway.php:303`
  - Montant envoyé comme `(float)` pour préserver les décimales
  - Backend API mis à jour avec validation par devise:
    - XOF: minimum 200 FCFA (~0.30 EUR)
    - EUR: minimum 0.50 EUR
    - USD: minimum 0.50 USD

#### Problème de Validation Pawapay (Numéros de Téléphone)
- **Problème**: Tous les formats de numéro rejetés pour Pawapay
  - `+2250101281976` → Rejeté ❌
  - `2250101281976` → Rejeté ❌
  - `0101281976` → Rejeté ❌
- **Cause**: Numéros envoyés sans code pays (format local)
- **Solution**:
  - Ajout de la fonction `normalize_phone_number()` 
  - Conversion automatique au format international avec indicatif pays
  - Support de 20+ pays africains et européens
  - Exemples:
    - `0101281976` + pays `CI` → `+2250101281976` ✅
    - `0101281976` + pays `SN` → `+2210101281976` ✅
    - `+33758418018` → `+33758418018` ✅ (déjà formaté)

### 📋 Détails Techniques

**Fichiers Modifiés:**
- `geniuspay-woocommerce.php` - Version bump 1.0.2 → 1.0.3
- `includes/class-geniuspay-gateway.php`:
  - Ligne 303: Correction calcul montant
  - Lignes 301-305: Ajout normalisation téléphone
  - Lignes 565-647: Nouvelle méthode `normalize_phone_number()`

**Backend API Modifié:**
- `app/Http/Controllers/Api/MerchantApiController.php`:
  - Lignes 34-50: Validation adaptative par devise

### ✅ Tests de Validation

**Montants EUR/USD:**
- ✅ 0.50 EUR → Accepté
- ✅ 4.50 EUR → Accepté
- ✅ 10.99 USD → Accepté
- ❌ 0.30 EUR → Rejeté (< minimum)

**Numéros Pawapay (Côte d'Ivoire):**
- ✅ `0101281976` → `+2250101281976`
- ✅ `+2250101281976` → `+2250101281976`
- ✅ `2250101281976` → `+2250101281976`
- ✅ `00 225 01 01 28 19 76` → `+2250101281976`

### 🌍 Pays Supportés pour Normalisation

**Afrique:** CI, SN, BJ, TG, BF, ML, NE, GN, CM, GA, CD, CG, RW, KE, UG, TZ, ZM, GH, NG, MA, TN, DZ, EG, ZA  
**Europe:** FR, BE, CH  
**Amérique:** CA, US

---

## Version 1.0.2 (2026-03-20)
- Stabilisation générale
- Amélioration des logs

## Version 1.0.1 (2026-03-15)
- Corrections mineures
- Support Sandbox amélioré

## Version 1.0.0 (2026-03-10)
- Version initiale
- Support Wave, Orange Money, MTN Money, Paystack, Pawapay, CinetPay
- Mode Sandbox
- Smart Routing
