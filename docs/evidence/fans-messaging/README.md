# Messagerie Fans — preuves isolées, 30 septembre 2026

Base du raccordement UI : `d91f1427b07c298012f1a8cac2a625771c3c67ac`, après
[#110](https://github.com/Alternative-LAB/faluss-platform/pull/110) (moteur privé)
et [#111](https://github.com/Alternative-LAB/faluss-platform/pull/111) (signalements).
Version de départ publiée : 0.8.0. Ces captures ne prouvent aucune activation.

Le manifeste `runtime-sha256.json` scelle 38 fichiers PHP/CSS/JS effectivement
servis par le runtime des captures, comparés à l’arbre de travail (CRLF normalisés
en LF). Le code, les scripts et les images sont livrés dans la même PR.

## Environnement et portée

- PHP 8.5.4, MariaDB jetable sur socket Unix, `--skip-networking`, répertoire
  `/var/tmp/fans-editorial-*` réservé au runner. Dépendances Composer physiques
  vérifiées sur le lock, sans utiliser l’autoload de la jonction Windows.
- Services, SQL InnoDB, transactions, REST dispatch, formulaires et rendu PHP du
  plugin réels. HTTP sur `127.0.0.1:8768` seulement. Sessions, nonces et primitives
  WordPress sont des **adaptateurs de test**. Aucun WordPress/thème/Elementor réel,
  site, serveur de production ni échange Identity Me consulté.
- Fixtures synthétiques seulement. La présentation « Atelier de recette » est
  soumise et approuvée via les vrais services ; les messages proviennent des
  formulaires, jamais d’une API simulée ou de données produit inventées.
- Chromium 154.0.8037.58 et WebKit Windows 26.5 ; 1440 × 1000 et 390 × 844.
  Requêtes navigateur extérieures au loopback bloquées. WebKit Windows ne prouve
  ni Safari iOS/macOS ni la typographie d’un téléphone physique.

## Contrôles

- Suite complète : 299 tests / 4 775 assertions ; deux dépréciations préexistantes.
  PHPStan : zéro erreur. Lint des PHP modifiés, syntaxe JS, diff-check et scan
  ciblé des secrets. CI GitHub requise avant fusion.
- InnoDB et HTTP : demande → acceptation → échange ; refus ; tiers, invité,
  administrateur ordinaire et compte non lié refusés ; nonce et champs forgés ;
  suspension ; quotas ; CAS et rejeux concurrents ; erreurs SQL et rollback.
- Blocage après purge, déblocage indépendant, durée calendaire à la borne exacte,
  purge ordinaire indépendante des preuves, minimalité du signalement, accès
  dédié du modérateur, recours, finalisation, restrictions multiples et litiges.
- Formulaires natifs du panel et des pages ; envois fermés mais recours/lecture
  conservés ; cron sans session ni admission, diagnostic sans contenu privé.
- Navigateur : profil → demande, invité → retour SSO préparé, huit accès Créateur,
  échange bilatéral, signalement → modération → recours → décision définitive,
  blocage et déblocage, aucun UUID visible ni débordement horizontal, effacement
  du DOM privé au départ, envoi avec JavaScript désactivé. Résultats par moteur
  dans `chromium/result.json` et `webkit/result.json`.

Reproduction :

```text
python3 tests/Fans/Profiles/recipe/run.py --http --publications --messages
python3 tests/Fans/Profiles/recipe/run.py --messages --web
FANS_UI_BROWSER=chromium FANS_UI_OUTPUT=<dossier> node tests/Fans/Messaging/recipe/browser.cjs
# Arrêter le serveur jetable et repartir d’une nouvelle fixture pour WebKit.
FANS_UI_BROWSER=webkit FANS_UI_OUTPUT=<dossier> node tests/Fans/Messaging/recipe/browser.cjs
```

## Captures examinées

| Parcours | Ordinateur | Mobile |
| --- | --- | --- |
| Nouvelle demande Fan | [Demande](chromium/fan-request-desktop.png) | [Attente](chromium/fan-pending-mobile.png) |
| Réception Créateur | [Demande reçue](chromium/creator-request-desktop.png) | [Demande reçue](chromium/creator-request-mobile.png) |
| Conversation Créateur | [Échange](chromium/creator-conversation-desktop.png) | [Échange](chromium/creator-conversation-mobile.png) |
| Conversation Fan | [Échange](chromium/fan-conversation-desktop.png) | [Échange](chromium/fan-conversation-mobile.png) |
| Modération privée | [Preuve minimale](chromium/moderation-proof-desktop.png) | [Preuve minimale](chromium/moderation-proof-mobile.png) |
| Recours / blocage | Sans objet | [Recours](chromium/fan-appeal-mobile.png), [blocage](chromium/blocked-conversation-mobile.png) |

Les mêmes vues existent dans `webkit/`. Les captures pleine page conservent la
barre mobile fixe à la position du viewport au moment de la prise ; elle accompagne
le défilement dans le navigateur. La liste latérale est remplacée par un retour
« Vos échanges » lorsqu’un fil est choisi sur mobile. La densité des planches n’est
pas remplie par de faux contacts, portraits, pièces jointes, abonnements ou statuts.

## Limites avant ouverture

Le parcours **ordinaire après acceptation est fonctionnel et testé en isolation**.
L’ouverture directe Max/abonné Créateur reste **service absent**, faute de preuve
serveur propriétaire consommable. Le retour SSO est testé comme contrat et lien,
pas comme échange réseau avec Me. Les traitements, personnes habilitées,
notifications, voies/délais de recours, cron fiable, sauvegardes et recette cible
doivent être validés par l’exploitant avant l’attestation et l’ouverture des flags.
[Politique complète](../../modules/FANS-MESSAGING.md). Aucun site ni flag de
production touché, aucun SMTP ou second passwordless, aucun paiement.
