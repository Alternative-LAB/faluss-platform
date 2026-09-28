# Recette Store v2 — archivage adulte externe

## Exécution réelle du 28 septembre 2026

WordPress **7.1.2**, PHP **8.5.4**, MariaDB **11.8.6**, base InnoDB neuve
`faluss_store_archive_recipe`. Transport HTTP loopback `127.0.0.1:8118`, quatre
workers PHP, véritables sessions/cookies et nonces WordPress ; contexte WP HTTPS
simulé par le routeur, **pas de preuve TLS**. Aucun accès réseau Me/Hub/production.

- Fixture : 25 anciennes fiches adultes (visibilités hidden et visible) et 25 fiches
  hébergées ; données structurées synthétiques, aucun contenu adulte ou média.
- Migration réelle v1→v2 : panne SQL de marquage après ALTER, version conservée à 1,
  service non prêt ; retrait du trigger de panne et reprise réussie. Nouvelle
  installation idempotente. Les six champs d'origine des 50 lignes sont identiques
  avant/après, 25 marqueurs d'archive.
- **104 contrôles HTTP/base réussis** : catégorie absente, listes publiques filtrées
  avant limite (20 places hébergées malgré les archives antérieures), filtres falsifiés,
  25 détails en 404 et achats forgés en 403, contenu hébergé en 503 ; création adulte
  simultanée par quatre requêtes refusée, aucune route de remise en ligne.
- Archives privées : visiteurs/créateurs/nonces absents ou falsifiés refusés,
  pages 20 + 5 complètes sans doublon, détail accessible après suspension,
  réponses `private, no-store`, aucune clé d'idempotence exposée.
- Changement synthétique de catégorie/visibilité et réutilisation de l'ancienne
  clé : l'archive reste privée et l'achat 403. Changement hébergé→adulte : 404/403.
- Fermeture des trois flags locaux SSO/profils/catalogue vérifiée, routes 404,
  serveur et workers arrêtés, sessions révoquées et fichier de sessions supprimé.

La première exécution a atteint le nettoyage mais son assertion interprétait
incorrectement la valeur booléenne retournée par WP-CLI. Le contrôle utilise
maintenant la constante PHP ; nettoyage terminé et recette relancée avec succès.
Ce défaut de script n'est pas compté comme une recette réussie.

## Reproduction sur instance jetable uniquement

1. Créer une installation WP **neuve** dans `/var/tmp/faluss-store-archive-recipe`,
   base dédiée ci-dessus, administrateur ID 1 et aucun import de données réelles.
   Lier le plugin de cette branche. URL `https://store.local.test`, rôle `fans`,
   client SSO fictif avec secret local aléatoire base64url de 43 caractères.
   Désactiver cron et HTTP externe. Aucun identifiant dans le dépôt.
2. Activer temporairement SSO et profils sur cette seule instance, catalogue
   **false**, puis activer le plugin : les schémas SSO et profils doivent être prêts.
   Tous les autres flags restent absents/false. WP-CLI utilisé ici :
   `/var/tmp/faluss-v3-wp/wp-cli.phar` (adapter le chemin local si nécessaire).
3. Exécuter `wp eval-file <repo>/tests/Fans/Store/recipe/archive-fixture.php` :
   garde nom de base + chemin local exacts, refuse si une table catalogue existe
   déjà, ne supprime aucune table. Prépare le schéma historique, les fixtures et
   la migration avec panne. Les sessions restent hors document root dans
   `/var/tmp/faluss-store-archive-proof/sessions.json` (0600, répertoire 0700).
4. Ajouter un routeur local `router.php` fixant `HTTPS=on`, `SERVER_PORT=443`,
   `HTTP_HOST=store.local.test`, puis chargeant `index.php` ; aucun accès statique.
5. Lancer `python3 <repo>/tests/Fans/Store/recipe/archive-test.py` sous l'utilisateur
   local autorisé sur cette base. Le script ouvre les trois flags locaux, lance
   et arrête le serveur loopback, teste l'API et les mutations SQL synthétiques,
   ferme les flags dans `finally`, révoque les sessions et supprime leur fichier.
   Port 8118 libre requis. Ne pas exposer ce serveur au réseau.

Activation temporaire de test uniquement, autorisée pour cette recette. Aucune
activation sur `fans.faluss.me`, Me ou Hub ; aucun déploiement, achat ou fusion.
Conserver les sorties sans cookies, nonces ni export de base.

## Limites et historique

Pas de preuve d'hébergement, proxy/cache de production, charge prolongée ou
permissions avec extensions tierces. Les échanges SSO sont préparés localement,
pas revalidés auprès de Me. Le Store ne possède ni index de recherche ni compteur
séparé : les paramètres forgés testés passent par la liste filtrée existante.
Les futurs index devront avoir leurs propres tests. Aucun moteur commercial testé.
La rétention administrative reste à décider ; aucune purge ni restauration ici.
Retour arrière fermé décrit dans [le contrat Store](../../../../docs/modules/FANS-STORE.md).

`fans-rest-fixture.php` et `fans-rest-test.py` sont les recettes **historiques du
catalogue v1** (deux catégories publiques et création adulte hidden). Elles ne sont
pas un contrat du comportement v2 et ne doivent pas être exécutées pour le valider.
