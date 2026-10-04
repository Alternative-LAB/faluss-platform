# Messagerie mobile et actualisation privée

Base : `9fb67f2c9d4a8b634671d3e3d7613d7c6eb99f04` (v0.12.3).
Périmètre autorisé : messagerie mobile, navigation racine mobile et actualisation
privée des conversations. Composition ordinateur conservée. Aucun site consulté,
aucun flag de production modifié ; publication uniquement par les workflows GitHub.

## Comportement livré

- À 700 px et moins : liste et conversation sont deux écrans plein cadre.
  Retour visible et historique navigateur ; position de liste conservée dans
  `history.state` (uniquement un nombre de pixels, aucun contenu privé).
- En-tête et saisie restent dans le viewport visuel, au-dessus de la navigation.
  La zone centrale défile ; la page ne défile pas en parallèle. Safe areas,
  `visualViewport.resize/scroll` et changement d’orientation sont pris en compte.
- Notifications/Déconnexion sont masquées seulement dans Messages mobile ; elles
  restent sur les autres pages. Les libellés de saisie et limites restent accessibles
  aux lecteurs d’écran et dans « Confidentialité et aide ».
- Toutes les navigations Fans mobiles utilisent une seule rangée horizontale,
  tactile et défilante. Ordre, destinations, onglet actif et aide tactile conservés.
  Le focus clavier révèle un onglet hors champ. Aucun changement de sidebar desktop.
- Aucun média, paiement, présence, accusé de lecture ni compteur de non-lus ajouté.

## Lectures et garanties

`GET /faluss-fans/v1/message-view` est un adaptateur de rendu **privé** additif,
fermé si l’UI ou les accès privés existants sont indisponibles. Même nonce REST,
session SSO locale et contrôles d’appartenance que les lectures actuelles.
Il appelle `MessageReading::inbox/conversation` et réutilise les templates échappés,
les formulaires natifs et leurs nonces. Réponses `private, no-store`, sans cache CDN.
Le mode Créateur exige aussi le profil local, comme la route UI. Les API existantes
restent compatibles ; `last_sequence` est une indication additive de conversation.
Aucune table, migration, quota, politique de modération/rétention ou attestation changée.

- Conversation : lecture environ toutes les **6 secondes**, `after` égal à la plus
  grande séquence reçue. Pages de 50 rattrapées successivement (250 ms minimum),
  dédoublonnage par séquence. Pas de saut de séquence lors de plusieurs arrivées.
- La différence `revision - last_sequence` distingue une décision/modération des
  simples ajouts : un changement déclenche une réconciliation paginée de l’historique
  pour retirer/restaurer aussi un ancien message déjà affiché. Les décisions et
  blocages actualisent la saisie et ses commandes. Les moteurs restent autoritaires.
- Liste : **balayage complet** de toutes les pages de 20 UUID, sans plafond de 20
  conversations et sans supposer que le nouvel UUID est plus grand. Une seule page
  à la fois, au moins 250 ms entre lectures ; la conversation ouverte a priorité.
  Après une passe terminée : attente de **20 secondes**, puis reprise au début.
  Une insertion avant un curseur déjà passé est retrouvée à la passe suivante.
  Ce n’est pas un instantané transactionnel multi-requêtes ni une promesse temps réel :
  la latence dépend du nombre de pages et du réseau. Les absences ne sont réconciliées
  qu’après une passe entière réussie. Aucun nouvel index/schéma ou journal ajouté.
- Une seule requête de messagerie à la fois, y compris envoi/décision. Pause hors ligne
  ou page masquée ; reprise immédiate au retour/online ; erreurs de lecture : délais
  croissants 4, 8, 16, 32 puis 60 secondes, timeout 12 secondes. Pas de rechargement.
- Envoi : POST REST existant, clé d’idempotence conservée tant que l’envoi n’est pas
  confirmé ; aucune bulle optimiste. Le message apparaît par lecture serveur après
  confirmation. Erreur : brouillon conservé. Sans JavaScript, le POST natif protégé
  et sa pagination restent disponibles. Signalements/recours restent des formulaires.
- Ancienne lecture : position conservée ; suivi du bas uniquement si le lecteur était
  à moins de 80 px du bas. Le redimensionnement mobile conserve ce choix.
- Expiration/refus de lecture : retrait du contenu privé. Pendant une coupure réseau,
  le garde de session des seules pages Messages attend la reconnexion ; une page
  revenue d’arrière-plan reste masquée jusqu’à revalidation serveur. Échéance absolue,
  déconnexion inter-onglets et contrôle serveur restent inchangés.

## Scénarios et recette reproductible

Scénarios positifs préparés avant livraison : demande reçue, acceptation, envois
bilatéraux et rapprochés, reprise arrière-plan/réseau, curseurs >20 conversations et
>50 messages, retour avec scroll, clavier/tactile. Négatifs : tiers, non lié, admin,
nonce absent, formulaire forgé, refus/blocage, panne réseau et expiration de session.
Retrait de modération testé sur un message déjà affiché.

1. Checkout isolé physique avec le `vendor` correspondant au lock (aucune jonction).
2. `tests/Fans/Profiles/recipe/admission-wordpress.py --source <checkout> --core <WP> --cli <wp-cli.phar> --backoffice --keep`.
3. Sur cette instance jetable seulement : `tests/Fans/Messaging/recipe/layout-prepare.py --root <root> --base <loopback> --cli <wp-cli.phar>`.
4. Depuis Windows avec Playwright : `BASE=<loopback> ROOT=<root> OUT=<preuves> node tests/Fans/Messaging/recipe/mobile-live-wordpress.cjs`.
5. Arrêter via `STOP`, supprimer la fixture, ne jamais conserver ses cookies, nonces,
   configurations, messages privés ou bases dans Git.

WordPress réel 7.1.2, MariaDB 11.8.6, PHP 8.5.4 ; sessions locales SSO liées distinctes.
Pas de connexion centrale Identity ni recette du site cible. Tous les noms/messages
et le portrait géométrique sont des données synthétiques explicitement destinées à
la recette ; aucun contenu de compte réel n’a été lu ou capturé.

Le clavier est **émulé par réduction du viewport** dans Chrome, pas par un clavier
physique Safari. Le propriétaire garde cette vérification sur iPhone/Android, son
thème/Elementor et son proxy. Les captures et assertions mesurent le rendu du vrai
plugin sur WordPress isolé, sans mocks des services ni réponses de messagerie.

## Captures

- [Liste mobile 390](fan-list-390.png), [conversation mobile 390](fan-conversation-390.png).
- Largeurs [320](fan-conversation-320.png), [375](fan-conversation-375.png), [430](fan-conversation-430.png).
- [Hauteur de clavier émulée 320](keyboard-320.png).
- [Créateur : liste](creator-list-390.png), [demande](creator-pending-390.png), [échange](creator-accepted-390.png).
- [Liste vide](fan-empty-390.png).
- [Ordinateur avant](desktop-before.png), [ordinateur après](desktop-current.png),
  mêmes données, viewport 1440 × 1000 ; colonnes/sidebar mesurées identiques.
- [Résultats navigateur](browser.json).

Les captures conservent les limites du contrat : identité Fan générique lorsque
aucune présentation publique approuvée n’existe, pas d’aperçu ni de non-lus inventés.

## Résultats vérifiés

- **114 contrôles navigateur** dans `browser.json`, plus **5 contrôles de navigation et résilience**
  dans [navigation.json](navigation.json) : geste tactile horizontal réel via CDP,
  huit destinations Créateur, focus visible, retour d’une conversation inaccessible,
  seuil mobile/ordinateur 700/701 px.
- PHP : **317 tests, 5 333 assertions**, réussite ; deux dépréciations PHP 8.5 déjà
  présentes dans la suite. PHPStan : aucune erreur. `php -l` sur les quatre fichiers
  PHP concernés ; syntaxe JavaScript, scan ciblé des secrets et `git diff --check`.
- [Empreintes des neuf fichiers exécutés dans WordPress](runtime-sha256.json).
- Comparaison ordinateur : dimensions des colonnes et de la sidebar identiques ;
  [différence raster](desktop-comparison.json) nulle (zéro pixel différent).
  Les captures ont été ouvertes et examinées, pas seulement générées.
- Aucun fatal PHP dans la fixture. Aucune erreur JavaScript dans la recette.

La route privée de rendu est additive. Rollback : revenir au code précédent, sans
migration SQL ni donnée à convertir. La publication ne constitue ni une installation
ni une attestation de recette sur le site cible.
