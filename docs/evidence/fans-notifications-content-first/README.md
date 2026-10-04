# Notifications Fans — contenu dès l’entrée

Base : `00f1e462a5a5fb7870f50160a2f4b36c48b2f384` (v0.12.5).

## Correction et périmètre

Sur les seules routes Notifications Fan/Créateur : suppression du rendu de la
barre Notifications/Déconnexion, de l’en-tête visuel et du pied de page explicatif.
Un `h1` Notifications reste exposé aux lecteurs d’écran sans place dans le flux.
Les filtres Toutes / Non lues commencent en haut à gauche. La liste utilise la
largeur disponible (marges de contenu 16 px, 8 px sur mobile), sans carte ni zone
de défilement imbriquée. La hauteur suit le contenu et la navigation mobile garde
son espace réservé. Aucun changement de sidebar ni de barre sur les autres vues.

Le lien inférieur « Revenir au début » est retiré. « Page suivante » reste une
navigation native au curseur serveur, disponible seulement s’il existe une page
suivante ; le filtre Toutes revient à la première page, également sans JavaScript.

La source des attributs d’actualisation est maintenant le centre lui-même, et la
cloche demeure optionnelle pour le script commun. Nonce, permissions, rythmes de
lecture, backoff, sérialisation et POST d’ouverture conservés. À l’expiration,
le compteur du filtre est également neutralisé, avec la liste et la pagination.
Aucune modification des API, des données, de la rétention, des flags ou de Me.

## Recette et preuves

WordPress 7.1.2 / PHP 8.5.4 / MariaDB 11.8.6, Twenty Twenty-Five, application Fans
autonome. Installation jetable sur loopback, mail et HTTP sortant bloqués.
Deux membres SSO distincts de recette, sessions WordPress et écritures métier
réelles ; aucune connexion au SSO central ou à un site cible. Chrome 155.0.8059.26.

- `browser.json` : **112 contrôles**. Fan et Créateur, largeurs 320, 390, 430, 834
  et 1440 px. Filtres en haut, largeur utile, h1 accessible hors flux, absence des
  éléments retirés, aucun débordement, lignes simples 56 px. Actualisation réelle
  des demandes/messages, lu/non lu, focus et position, pagination >20, filtre,
  pause masquée/hors ligne et reprise ; ouverture au clavier par POST, nonce,
  destinataire étranger, objet expiré et invalidation de session. Page suivante
  et retour via Toutes testés sans JavaScript. Barre de l’autre écran conservée.
- `transport.json` : **5 contrôles** du backoff (24 puis 48 s), sérialisation,
  récupération et actualisation de la cloche sur Explorer.
- `expiration.json` : **2 contrôles** après expiration des vrais jetons WordPress
  locaux : liste supprimée et compteur neutralisé.
- PHPUnit : **318 tests / 5 362 assertions**, 2 dépréciations PHP 8.5 préexistantes.
  PHPStan : aucune erreur ; lint PHP, syntaxe JavaScript et `git diff --check` verts.

Captures examinées après le rendu des polices :

| Vue | Ordinateur 1440×1000 | Tablette 834×1000 | Mobile 390×844 |
|---|---|---|---|
| Fan | [Capture](fan-loaded-1440.png) | [Capture](fan-loaded-834.png) | [Capture](fan-loaded-390.png) |
| Créateur | [Capture](creator-loaded-1440.png) | [Capture](creator-loaded-834.png) | [Capture](creator-loaded-390.png) |

[État d’objet devenu inaccessible](creator-inaccessible-1440.png).
Comparaison avant : [captures v0.12.5](../fans-notifications-compact-live/README.md).

## Reproduction et limites

Utiliser `admission-wordpress.py --backoffice --keep` puis `layout-prepare.py`
comme décrit dans la [recette précédente](../fans-notifications-compact-live/README.md).
Définir `ROOT`, `BASE`, `OUT` et exécuter successivement les recettes navigateur
`notifications-wordpress.cjs`, `notifications-transport-wordpress.cjs`, puis
`notifications-expiration-wordpress.cjs`. Les comptes et cookies restent dans
la fixture privée ; les captures ne montrent que des libellés génériques.

Les événements navigateur caché/hors ligne et les pannes de transport sont
pilotés dans Playwright ; les réponses métier positives ne sont pas simulées.
Pas de recette de site cible, Elementor de production ou Safari matériel revendiquée.
Aucun accès aux sites ni modification de flag de production.
