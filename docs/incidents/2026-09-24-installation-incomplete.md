# Installation incomplète de Faluss Platform 0.3.0

## Constat

Le 24 septembre 2026, les deux WordPress annonçaient `0.3.0` et chargeaient
Faluss Platform, mais leurs dossiers installés ne contenaient pas `vendor/`.
Plugin Update Checker ne pouvait donc pas se charger : aucune nouvelle version
privée ne pouvait être détectée. L'interface Faluss affichait pourtant une
licence configurée. Les dossiers avaient été déposés avec UID/GID `1000:1000`
et des droits `777/666`, contrairement aux autres plugins appartenant à
`www-data`. L'archive officielle `0.3.0` publiée par GitHub Actions contient
bien `vendor/autoload.php`, Plugin Update Checker et Stripe PHP.

Ces éléments prouvent qu'une copie incomplète du code a remplacé l'archive de
production. Ils ne permettent pas d'identifier l'outil exact qui a effectué
cette copie.

## Réparation

Une sauvegarde restaurable des deux dossiers plugin et des deux bases a été
créée avant toute écriture. Le dossier `vendor/` a été extrait de l'archive
officielle `0.3.0` puis ajouté aux deux installations. Les dossiers plugin ont
été remis sous `www-data:www-data` avec permissions `755` pour les répertoires
et `644` pour les fichiers. Les deux sites ont ensuite chargé Plugin Update
Checker sans erreur, avec Faluss Platform `0.3.0` toujours actif.

Le tableau de bord Faluss signale désormais explicitement l'absence du client
de mise à jour. Le workflow de publication vérifie la présence des fichiers
obligatoires dans le ZIP avant son envoi.

## Erreur `upgrade-temp-backup`

Le message WordPress « Impossible de déplacer l'ancienne version vers le
répertoire upgrade-temp-backup » correspond à `fs_temp_backup_move`.
WordPress masque à cet endroit l'erreur interne de `move_dir()`. L'ancien
résultat détaillé et l'erreur système ne figurent dans aucun journal disponible.

Sur les deux volumes, l'espace, les inodes, les dossiers parents et l'écriture
par `www-data` sont corrects. Un renommage de répertoire possédé par UID 1000
vers `upgrade-temp-backup/plugins` a réussi sur chaque site. L'upgrader natif
a également remplacé le plugin sur la préproduction avec la copie de dossier
et l'archive `0.3.0`. Il serait donc incorrect d'attribuer rétrospectivement
ce message à la seule propriété du dossier. Toute nouvelle erreur doit être
capturée avec son code interne et l'erreur système avant une seconde tentative.

## Conditions de validation d'une nouvelle version

1. Construire et publier exclusivement l'archive de production par la PR de
   release et GitHub Actions.
2. Vérifier la disponibilité du client de mise à jour, la licence et la version
   annoncée avant l'installation.
3. Sauvegarder les dossiers plugin et les bases, puis vérifier les archives.
4. Valider le remplacement sur une copie représentative et faire un essai
   distinct par site.
5. Après chaque essai, vérifier la version, l'état actif, le chargement du
   client et les modules, les options et tables, puis les parcours HTTP.
6. En cas d'échec, restaurer la sauvegarde externe et conserver les traces
   avant de relancer le processus.

Une invocation directe de `Plugin_Upgrader::upgrade()` depuis PHP requiert une
réactivation explicite dans un nouveau processus. L'incident de désactivation
est documenté séparément dans
`docs/incidents/2026-09-24-scripted-update-deactivation.md`.
