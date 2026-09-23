# Schéma Identity de production refusé par Platform

## Détection

Le 23 septembre 2026, une bascule contrôlée de Faluss Identity sur
`faluss.me` a désactivé le plugin historique après vérification indépendante
de l'opt-in Platform. La première requête suivante a levé
`LogicException: Faluss Identity schema is unavailable` dans
`IdentityModule::boot()`.

Avant la bascule, Identity historique `0.4.15` déclarait le schéma prêt avec
la version `6`. Les huit tables existaient, avec notamment quatre profils,
quatre profils publics et trois clients OAuth. Aucun test de parcours membre
n'a été lancé après l'erreur.

## Mesure immédiate

`FALUSS_PLATFORM_IDENTITY` a été remis à `false` par remplacement atomique.
Une nouvelle requête a confirmé l'absence des classes Identity Platform, puis
`faluss-identity` a été réactivé. Une dernière requête indépendante a confirmé
la version `0.4.15` et l'état prêt du schéma historique.

Aucune table, option, identité, session, client OAuth, consentement ou donnée
de profil n'a été supprimé ou recopié. La sauvegarde SQL préalable reste
disponible sous `/opt/backups/faluss-platform-migration-20260923` sur l'hôte du
conteneur.

## Cause et correction

Le bootstrap Platform relançait toute la chaîne de migrations à chaque requête.
La première migration FI-02 exige `manage_options` avant son chemin rapide de
validation ; une requête publique ou Cron sans utilisateur échouait donc même
avec un schéma version `6` prêt. L'ancien plugin réserve ces migrations à
l'activation et à `admin_init` avec un administrateur.

Le module vérifie désormais uniquement la version et l'état du schéma au
démarrage. Les migrations historiques restent attachées à leurs chemins
privilégiés. L'issue GitHub #31 suit la fusion du correctif et la nouvelle
validation contrôlée.
