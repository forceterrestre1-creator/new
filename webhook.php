# 718Digital — Backend PHP (SiteBuilder + Agent Accueil WhatsApp)

Backend PHP sécurisé pour 718Digital : génération de sites web par IA (Mistral)
et Agent Accueil autonome sur WhatsApp Business.

## 📁 Structure du dépôt

```
.
├── .env.example          # Modèle de variables d'environnement (à copier en .env)
├── .gitignore             # Exclut .env et storage/ du dépôt
├── .htaccess              # Bloque l'accès direct à .env et storage/
├── config.php             # Charge la clé Mistral depuis .env
├── whatsapp-config.php    # Charge les identifiants WhatsApp depuis .env
├── generate-site.php      # Endpoint : génère un site web via Mistral
├── webhook.php            # Endpoint : Agent Accueil WhatsApp autonome
└── storage/                # Logs et historiques de conversation (généré automatiquement)
```

⚠️ **Le fichier `.env` n'est jamais inclus dans ce dépôt** (voir `.gitignore`).
Il contient tes clés secrètes et doit être créé manuellement sur le serveur.

---

# 718digi SiteBuilder — Proxy PHP sécurisé

Ce dossier protège ta clé Mistral en la gardant côté serveur. Le navigateur du client n'y a jamais accès.

## Installation (Laragon ou hébergement classique)

1. Copier ces 5 fichiers dans ton dossier serveur (ex: `www/718digital/api/` sous Laragon)
2. Renommer `.env.example` en `.env`
3. Ouvrir `.env` et remplacer `votre_cle_mistral_ici` par ta vraie clé Mistral
4. Vérifier que `ALLOWED_ORIGIN` correspond au domaine de ton site (ex: `https://718digital.ci`).
   En local sur Laragon, mets `http://localhost` ou `*` pour tester.
5. C'est prêt. Le dossier `storage/` se crée automatiquement au premier appel.

## Comment l'appeler depuis ton SiteBuilder (JS)

Dans l'artifact React, remplace l'appel direct à Mistral par un appel à ton PHP :

```javascript
const response = await fetch("https://718digital.ci/api/generate-site.php", {
  method: "POST",
  headers: { "Content-Type": "application/json" },
  body: JSON.stringify({
    companyName: companyName,
    siteType: selectedType.label,
    style: selectedStyle.label,
    lang: lang,
    colors: colors,
    description: description,
  }),
});

const data = await response.json();
if (data.success) {
  setGeneratedCode(data.html);
} else {
  setError(data.error);
}
```

Plus besoin de demander la clé Mistral au client — elle ne quitte jamais ton serveur.

## Sécurité incluse

- Clé API jamais visible côté client
- `.htaccess` bloque l'accès direct à `.env` et au dossier `storage/`
- Limite de 10 requêtes/heure par adresse IP (anti-abus, anti-facture surprise)
- Toutes les entrées utilisateur sont nettoyées (anti-injection)
- Log simple de chaque génération dans `storage/generation_log.txt`

## Test rapide en local

```bash
curl -X POST http://localhost/718digital/api/generate-site.php \
  -H "Content-Type: application/json" \
  -d '{"companyName":"Test","siteType":"Restaurant","description":"Un restaurant ivoirien moderne à Abidjan"}'
```

Si tout fonctionne, tu reçois un JSON avec `"success": true` et le code HTML généré.

---

# 📱 Agent Accueil — Webhook WhatsApp Business

Le fichier `webhook.php` rend l'Agent Accueil autonome : il répond automatiquement
aux clients sur WhatsApp 24h/24, qualifie le besoin, et garde l'historique de
conversation par numéro de téléphone.

## Pré-requis

Tu as déjà ton App ID, ton Token d'accès et ton Phone Number ID Meta. Il te faut
en plus compléter `whatsapp-config.php` via `.env` :

```
WHATSAPP_TOKEN=ton_token_d_acces_permanent
WHATSAPP_PHONE_NUMBER_ID=ton_phone_number_id
WHATSAPP_VERIFY_TOKEN=718digital_verify_2026   (choisis n'importe quelle chaîne, tu la réutilises côté Meta)
WHATSAPP_APP_SECRET=ton_app_secret              (optionnel mais recommandé)
```

⚠️ Utilise un **token permanent** (System User Token), pas le token temporaire de
24h donné par défaut dans le tableau de test Meta — sinon le webhook arrête de
fonctionner chaque jour.

## Configuration côté Meta Developer Dashboard

1. Va dans ton App > **WhatsApp** > **Configuration**
2. Dans **Webhook**, clique "Modifier"
3. **URL de rappel** : `https://tondomaine.ci/api/webhook.php`
4. **Token de vérification** : la même valeur que `WHATSAPP_VERIFY_TOKEN`
5. Clique "Vérifier et enregistrer" — Meta va appeler ton webhook en GET pour
   confirmer (le fichier gère ça automatiquement)
6. Dans **Champs Webhook**, abonne-toi au champ **messages**

## Test

Envoie un message WhatsApp au numéro associé à ton Phone Number ID. L'Agent
Accueil doit répondre en quelques secondes. Tu peux suivre les échanges dans
`storage/whatsapp_log.txt` et l'historique complet par client dans
`storage/conversations/{numero}.json`.

## Sécurité incluse

- Vérification de signature Meta (`X-Hub-Signature-256`) si `WHATSAPP_APP_SECRET` est renseigné
- Réponse immédiate à Meta (200 OK) avant traitement, pour éviter les retentatives en doublon
- Historique limité à 16 messages par client (coût et contexte maîtrisés)
- Tous les échanges sont loggés pour audit

## Prochaine étape

Une fois ce premier agent autonome validé, on branche **Make.com** pour les
déclenchements automatiques par horaire (rapport du matin, relances clients,
veille hebdomadaire) — puis on connecte les agents restants un par un.

