# Recette locale — références privées d’images

Le 26 septembre 2026, **54 contrôles HTTP WordPress/MariaDB réels réussis** sur
`feat/fans-publication-images`, distincts des tests PHPUnit simulés.
WordPress 7.1.2, MariaDB 11.8.6 InnoDB, PHP 8.5.4 avec GD/Fileinfo ; quatre workers
PHP sous UID `www-data`, cookies/nonces WordPress et identités synthétiques.

## Exécution

`python3 tests/Fans/Publications/recipe/image-association-test.py` sous Linux root,
**uniquement sur l’instance locale jetable** préparée par la recette Images.
Le script vérifie DB_NAME=`faluss_text_publications_recipe`, la cible du symlink
plugin, le port loopback libre et le MU-plugin offline. Les chemins dédiés de
stockage et temporaires doivent être absents. Il vérifie la migration v2 → v3
(conservation du nombre de textes et de la somme des révisions), puis réinitialise
uniquement les six tables synthétiques texte/images nommées dans le script.
Il conserve les tables SSO/profils ; aucun export ou accès à une base de production.

Le serveur écoute `127.0.0.1:8113` seulement ; HTTP loopback, **pas TLS**. Les
redirections sont refusées et le MU-plugin local bloque les HTTP sortants. Le
compte MariaDB socket temporaire `www-data` est limité à la base jetable et supprimé
dans `finally`. Cookies et nonces restent hors Git et racine web, mode 0600.

Activation temporaire locale des flags texte et Images pour cette recette, puis
**les deux flags à false**, configuration DB/racine privée/attestation restaurée,
processus HTTP et workers arrêtés, dossiers dédiés supprimés lorsqu’ils sont vides.
Le port 8113 ne présentait plus d’écoute après exécution. Aucune activation sur
`fans.faluss.me`, Me ou Hub, aucun déploiement, paiement, release ou diffusion.

## Cas observés

- Schéma d’association InnoDB et conservation des textes/révisions lors de migration.
- Création réelle des PNG privés et approbation via REST avant association.
- Refus anonyme, non lié, profil pending, admin sans propriété, autre créateur,
  nonce absent, image étrangère/pending/inconnue et révision d’image périmée.
- JSON forgé, chemin, propriétaire client et détachement incohérent refusés.
- Ajout et remplacement remettant le texte en attente de modération.
- GET public et liste avec exactement les cinq champs texte ; aucune donnée
  d’image. Texte approuvé sans accès aux octets pour anonyme/créateur/autre membre.
- Courses HTTP : association/association, association/édition textuelle,
  association/approbation, association/retrait du texte : 200 et 409.
- Trigger de panne du journal : rollback de la référence et révision du texte,
  puis nouvelle tentative réussie ; trigger supprimé en finally.
- Course association/retrait d’image : retrait réussi, association 200 ou 409 selon
  l’ordre ; jamais de référence utilisable vers l’image retirée à la lecture suivante.
- Suspension : référence null, octets admin 404, nouvelle association refusée,
  retrait du texte encore possible ; réactivation sans restauration du texte retiré.
- Retrait des images restantes par REST : fichiers privés supprimés. Fermeture des
  deux flags : routes Images puis association texte absentes.

## Limites

Les courses utilisent une barrière client et plusieurs workers ; elles n’énumèrent
pas tous les ordonnancements possibles. Les invariants de révision/transaction sont
également vérifiés par les tests ciblés. Les quotas d’association sont couverts par
PHPUnit ; cette recette ne répète pas les 96 contrôles de quotas du lot #75.
Migration vérifiée par comptes/révisions, pas par export complet. Aucun nouvel
échange SSO avec Me, navigateur physique, TLS, CDN, alias de production, charge
prolongée ou bascule SQL au commit. Aucun test de diffusion d’image : ce moteur
n’existe pas dans ce lot. La revue humaine ne garantit pas la détection de tout
contenu interdit ; hébergement et rétention restent soumis aux contrats existants.
