# Recette locale jetable — textes Fans

**Ne jamais exécuter sur un site existant.** La fixture PHP exige WP-CLI et le nom
de base `faluss_text_publications_recipe`. Le script Python modifie cette base,
crée un trigger de panne temporaire et désactive le flag texte à la fin.
Les comptes, textes et liaisons sont synthétiques. Aucun secret n'est versionné.

## Préparation manuelle explicite

1. Créer une instance WordPress vierge dans `/var/tmp/faluss-text-publications-recipe`,
   une base locale dédiée du nom ci-dessus, un administrateur ID 1, aucune pièce jointe.
   Aucun accès ni import de données de production. Utiliser le plugin de cette branche.
2. URL locale `https://text.local.test`, client SSO fictif et secret aléatoire local
   (43 caractères base64url), rôle `fans`. Activer uniquement les flags SSO, profils,
   et `FALUSS_PLATFORM_FANS_TEXT_PUBLICATIONS` sur cette instance jetable, puis le plugin.
   Installer les permaliens `/%postname%/`. Désactiver cron et les requêtes HTTP externes.
3. Installer ou vérifier explicitement le schéma v2 dans cette instance locale avant
   la fixture : trois tables InnoDB, dont `faluss_fans_text_requests`. La migration
   v1 → v2 ajoute cette table et conserve publications et journal.
   Exécuter `wp eval-file <repo>/tests/Fans/Publications/recipe/fixture.php` sur
   cette instance. Elle utilise les contrats publics de profil et prépare uniquement
   les liaisons SSO synthétiques ; elle ne simule pas une preuve réseau Me.
   Les cookies/nonces restent dans `/var/tmp/faluss-text-publications-proof/sessions.json`,
   mode 0600, hors du document root et du dépôt. Ne jamais joindre ce fichier à la PR.
4. Servir WordPress sur `127.0.0.1:8113` avec quatre workers PHP ; router les chemins
   via `index.php`. Le routeur local fixe `HTTPS=on`, `SERVER_PORT=443` et
   `HTTP_HOST=text.local.test` pour la configuration WP. Le transport de cette recette
   reste **HTTP loopback**, pas TLS. Le client transmet explicitement les cookies
   WordPress et `X-WP-Nonce`. Ne jamais exposer ce serveur hors loopback.
5. Lancer `python3 <repo>/tests/Fans/Publications/recipe/rest-test.py` sous un utilisateur
   autorisé à joindre la base locale par socket. Le script suppose le phar WP-CLI local
   `/var/tmp/faluss-v3-wp/wp-cli.phar` ; adapter ce seul chemin sur une autre machine.
   Arrêter ensuite tous les workers et conserver les résultats sans sessions/secrets.

## Correction du plafond pending : recette réelle du 25 septembre 2026

Exécution du code **`e76e873c8843e2488ec14149bcbe5c95a4d15a78`** :
**96 contrôles WordPress/MariaDB REST réussis**, avec WordPress 7.1.2,
PHP 8.5.4 et MariaDB 11.8.6. Quatre workers PHP, sessions et nonces WordPress,
HTTP réel sur `127.0.0.1:8113`, base synthétique dédiée et HTTP sortant bloqué.
Les contrôles PHPUnit simulés ne sont pas inclus dans ces 96 résultats.

| Cas exercé | Résultat observé |
| --- | --- |
| 20/20, création concurrente avec édition d’un texte déjà pending | Création 429 `publication_pending_quota`, édition 200 ; exactement une nouvelle décision, toujours 20 places |
| 20/20, édition approved → pending | 429 ; état, révision et journal inchangés |
| 20/20, édition rejected → pending | 429 ; état, révision et journal inchangés |
| 19/20 après retrait, édition approved ou rejected → pending | 200 pending pour chacun ; retrait entre les deux essais pour libérer la place |
| 19/20, création concurrente avec édition pending | 201 et 200 ; 20 places finales |
| 19/20, quatre créations concurrentes avec clés distinctes | Une 201, trois 429 |

Les contrôles d’idempotence, de limites 30/heure et 100/24 h, de rollback SQL,
de permissions, de suspension et des trois paginations passent aussi. Les fenêtres
quotidiennes utilisent des horodatages synthétiques déplacés, sans attendre 24 h.
La pagination parcourt 32 textes publics visibles, 25 textes du propriétaire et
44 éléments de modération, avec 22 textes approuvés d’un créateur suspendu exclus.
Fin de recette : 106 publications, 306 décisions et 106 associations idempotentes.

**Activation temporaire de test uniquement** : le flag texte a été activé sur
l’instance locale jetable pour cette recette, puis désactivé. Les routes renvoient
404 après fermeture, le journal est conservé ; la relecture indépendante de la
configuration confirme `false`. Le serveur et ses workers sont arrêtés, aucun
processus n’écoute sur le port 8113. Cette activation locale ne constitue pas une
activation en production : aucune intervention sur `fans.faluss.me`, Me ou Hub,
aucun déploiement, aucune ouverture de paiement et aucune fusion.

La preuve historique ci-dessous reste distincte. Les limites de transport et de
charge décrites en fin de document restent applicables à cette nouvelle exécution.

## Résultat historique de la recette d’admission (25 septembre 2026, SHA `930c43a`)

WordPress 7.1.2, PHP 8.5.4, MariaDB 11.8.6 : **87 contrôles REST réussis**,
via quatre workers PHP et une vraie base InnoDB. Ils comprennent les 45 contrôles
initiaux de #74 (SHA `9d5f2eb`) et les nouveaux scénarios d’admission/pagination.
Les tests PHPUnit avec doubles ne sont pas comptés dans ces 87 contrôles.

- Sessions WordPress, nonces, propriété, modération, accès privés/publics, refus
  des catégories interdites et des médias, suspension et retrait.
- Migration locale v1 → v2 : une publication préexistante conservée avant remise
  à zéro des seules tables texte de cette base synthétique pour la recette.
- Quatre créations simultanées avec une même clé : une publication et une trace ;
  rejeu après réponse perdue, conflit de contenu (409), clé invalide/absente (400).
- Trigger SQL temporaire faisant échouer l’association idempotente : transaction
  annulée sans publication ni trace orpheline ; nouvelle tentative réussie.
- Limite de 20 textes pending ; quatre créations différentes pour la dernière
  place : une 201 et trois 429. Course création/édition sous le même verrou.
- Limite horaire de 30 créations et éditions cumulées : deux éditions concurrentes
  pour la dernière admission, une acceptée et une 429 ; création suivante refusée.
- Limite quotidienne de 100 admissions. Les horodatages synthétiques du journal
  sont déplacés de deux heures puis de 25 heures pour tester les fenêtres ;
  cette partie ne prétend pas avoir attendu 24 heures réelles.
- Retrait et rejet possibles à quota atteint ; rejeu sans nouvelle admission,
  y compris après retrait (texte vide, aucune résurrection).
- Pagination publique sur 32 textes visibles, 22 textes approuvés d’un créateur
  suspendu exclus ; 25 publications du propriétaire et 44 éléments de modération.
  Pages successives comparées à un oracle SQL indépendant : ordre déterministe,
  pages remplies, aucun doublon ni omission dans ces jeux inchangés.
- Trois tables InnoDB, 104 publications et 104 associations idempotentes en fin
  de recette ; aucune pièce jointe. Flag texte désactivé à la fin, routes fermées,
  journal conservé. La relecture du flag peut demander une nouvelle requête
  (attente bornée à cinq secondes), distincte du retrait transactionnel d’un texte.

La fixture réutilise les comptes synthétiques nommés et renouvelle leurs sessions.
Pour rejouer intégralement, repartir d’une base jetable vierge ou remettre à zéro
explicitement **uniquement les trois tables texte de cette base dédiée**, réactiver
le flag local, vérifier le schéma puis relancer la fixture. Les seuils de production
ne sont pas modifiés pour les tests. Les triggers sont retirés en `finally`.

Limites : pas de preuve navigateur/TLS/CDN, pas de nouvel échange SSO avec Me,
pas de recette médias/Me (inexistants), pas de test de charge, de perte de connexion
SQL au commit ni de bascule multi-serveur. Le rejeu HTTP vérifie la récupération
d’une réponse perdue après commit, pas une interruption physique au milieu du commit.
La revue humaine, la rétention/purge et la protection globale contre un abus réparti
sur plusieurs créateurs restent à définir avant ouverture. Aucun filtre ne garantit
la détection de tout contenu interdit. Aucun flag hors instance jetable n’est activé.
