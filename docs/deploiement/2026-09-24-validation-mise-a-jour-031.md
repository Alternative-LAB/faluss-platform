# Validation de la mise à jour privée 0.3.1

Date : 24 septembre 2026. Références : PR #45, PR #46, issues #44 et #37.

## Versions et livraison

| Emplacement | Avant | Après |
|---|---:|---:|
| Dépôt `main` | 0.3.0 | 0.3.1 |
| `faluss.me` | 0.3.0 | 0.3.1 |
| `faluss.com` | 0.3.0 | 0.3.1 |

La PR #46 a généré le changelog et la version depuis les commits, puis le
workflow de publication a créé `v0.3.1` et livré le ZIP sur
`updates.faluss.com`. Le contrôle du ZIP a trouvé l'autoloader, Plugin Update
Checker et Stripe PHP. Depuis chaque WordPress, Plugin Update Checker a annoncé
`0.3.1` avec une URL de package authentifiée par la licence. Le serveur a
répondu `200` aux requêtes de métadonnées licenciées.

## Installation et conservation des données

Avant intervention, les deux installations `0.3.0` manquaient de `vendor/`.
Leur réparation et l'analyse de `fs_temp_backup_move` sont consignées dans
`docs/incidents/2026-09-24-installation-incomplete.md`. Des sauvegardes des
deux bases et des dossiers plugin, avant et après réparation, ont été vérifiées
sur l'hôte Incus sous `/root/faluss-upgrade-2026-09-24/`.

Une copie de production sur la préproduction `.me` a été remplacée par
l'upgrader natif WordPress avec le ZIP `0.3.1`. L'upgrader WordPress a ensuite
installé `0.3.1` sur `faluss.me`, puis sur `faluss.com`, depuis les packages
détectés par Plugin Update Checker. Le lanceur PHP de validation a réactivé
chaque plugin dans un processus distinct, conformément à la procédure de
l'incident de désactivation scriptée.

| Contrôle après installation | `faluss.me` | `faluss.com` |
|---|---:|---:|
| Plugin actif, client de mise à jour chargé, licence présente | oui | oui |
| Options Faluss, nombre et empreinte inchangés | 18 | 18 |
| Tables Faluss, nombre et empreinte des nombres de lignes inchangés | 22 | 27 |
| Dégradé CSS de contrôle `#294782` présent | oui | oui |
| Sauvegarde temporaire WordPress nettoyée | oui | oui |
| Pages publiques | HTTP 200 | HTTP 200 |

Les anciens répertoires inactifs `token-engine-connector` sur `.me` et
`faluss-platform-DELETE` sur `.com` ont été archivés hors des dossiers de
plugins puis retirés. Le seul dossier Faluss restant dans chaque WordPress est
`faluss-platform`.

## Fonctionnement contrôlé

- Sur `.me` : Theme, Catalog, Connector, Identity, Link, Events, Apps Registry,
  Federation et Me Studio V2 restent configurés. Le shortcode Link est
  enregistré. Le diagnostic Connector atteint Token Engine avec
  `wallet.read`, `reward.claim` et `entitlements.read`.
- Sur `.com` : Identity Client, Apps Registry, Portal, Subscriptions,
  Token Engine, Events, Analytics et Federation restent configurés. Le
  shortcode Portal est enregistré et `/mon-faluss/` répond HTTP `200`.
- Elementor et Elementor Pro restent actifs sur les deux sites. Les
  conteneurs WordPress et MariaDB sont sains.

Ces contrôles prouvent la mise à jour technique et la conservation des données
mesurées. Aucun paiement, connexion SSO complète ou parcours utilisateur
authentifié n'a été simulé pendant cette release de validation.

## Points ouverts

- L'erreur système qui a provoqué l'ancien `fs_temp_backup_move` n'est pas
  enregistrée ; les permissions anormales ne suffisent pas à l'expliquer. Les
  deux mises à jour `0.3.1` ont réussi et l'issue #37 conserve ce diagnostic
  ouvert en cas de récurrence.
- Stripe est toujours en mode TEST sans secret, webhook, prix, produit ni
  configuration de portail TEST. Les parcours de paiement restent fermés ;
  cette panne précédait la migration et requiert les paramètres TEST autorisés.
- Les flux Events et Analytics ont été validés par événement synthétique lors
  de la bascule du 23 septembre. Aucun nouvel événement intersites n'a été
  émis pendant cette mise à jour.
