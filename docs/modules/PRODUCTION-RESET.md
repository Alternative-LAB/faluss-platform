# Retrait de Faluss Production Reset

## Décision

Faluss Production Reset `0.1.3` n’est pas absorbé dans Faluss Platform. Le
plugin historique était un outil autonome et destructif, conçu pour un unique
reset coordonné avant lancement. Aucun consommateur de ses classes, options,
actions ou route n’existe dans les autres plugins historiques ni dans
Platform.

Platform fournit donc uniquement ce dossier de retrait : aucun module runtime,
flag d’activation, façade, hook, route, écran ou copie de la classe historique
n’est ajouté. Cette décision n’active, ne désactive et ne désinstalle rien sur
une installation WordPress réelle.

L’inventaire a été établi sur le dépôt historique propre au commit
`4c84e4bbfc859f9d6c17b1d44a76c79bdbcdadb4`.

## Inventaire historique exact

| Surface | Responsabilité historique |
| --- | --- |
| Hôtes | Fonctionnement limité à `faluss.me` et `faluss.com` |
| Classe publique | `Faluss_Production_Reset` |
| Administration | Outils → Mise en production Faluss |
| Capacité | `manage_options` pour l’affichage, l’armement, le préflight et l’exécution |
| Hooks enregistrés | `admin_menu`, trois actions `admin_post_faluss_production_reset_*`, `rest_api_init` |
| Route Hub | `POST` sur `faluss-production-reset/v1/hub-member-reset`, enregistrée seulement sur le Hub armé et non verrouillé |
| Appel sortant | URL HTTPS fixe de la même route sur `faluss.com` |
| Options | armement, version d’armement, verrouillage, reçu technique et nonce consommé sous empreinte |
| Transient | prévisualisation par utilisateur, limitée à dix minutes |
| Shortcodes, widgets, Cron, assets | aucun |
| Tables créées ou migrées | aucune |

Sur `faluss.me`, le plan historique vidait onze tables Identity et Link :
profils privés/publics, challenges, limites, codes, demandes d’autorisation,
audit Identity, cards, blocks, découvertes et réglages de découverte. Sur
`faluss.com`, il vidait les liaisons Identity Client et leurs états. Le ledger
PF devait exister et être strictement vide ; le ledger ALB et le registre des
clients SSO étaient explicitement préservés.

Le plugin calculait localement les comptes non privilégiés, puis appelait
`wp_delete_user()`. Il sélectionnait les attachments par auteur candidat et
appelait `wp_delete_attachment()` avec suppression forcée, avant de vérifier
l’absence des chemins déclarés par WordPress. Il ne parcourait pas récursivement
`uploads` et ne supprimait pas directement de fichier.

## Garde-fous présents et limite de conformité

Le runtime historique exigeait un armement local versionné sur les deux sites,
une capacité `manage_options`, des nonces WordPress, deux phrases exactes, un
préflight recalculé, un secret partagé hors Git d’au moins 32 caractères, un
corps HMAC canonique, une fenêtre de cinq minutes, un nonce consommé une fois et
un verrouillage après succès ou état partiel. Le pair ne pouvait choisir ni
utilisateur, ni table, ni rôle, ni montant, ni URL.

Le reçu local ne conservait que l’identifiant d’exécution, le statut, les dates
et des compteurs. Il s’agissait d’une option écrasable, pas d’un journal d’audit
durable, append-only et rapprochable entre les deux sites. Le code ne portait
pas non plus une autorisation explicite de production indépendante de
l’armement WordPress. Il ne satisfait donc pas à lui seul les conditions
actuelles d’exposition d’une action destructive.

## Retrait opérationnel

Le retrait logiciel dans Platform est complet : le bootstrap et `src/`
n’exposent aucun symbole, action, route ou configuration Production Reset. Le
plugin historique reste un artefact séparé ; sa présence, son activation, son
état d’armement et sa suppression sur les deux sites réels n’ont pas été
contrôlés ici.

Une désactivation ou désinstallation réelle exige séparément :

1. une sauvegarde restaurable vérifiée des deux sites ;
2. l’inventaire des plugins actifs, options d’armement, verrouillages et reçus ;
3. la confirmation qu’aucune opération FPR n’est encore requise ;
4. une autorisation explicite de production ;
5. une fenêtre coordonnée et une preuve après intervention sur les deux sites.

Cette procédure n’autorise aucune suppression de données. Elle décrit les
conditions de retrait du plugin historique, pas un reset.

## Condition d’un éventuel remplacement

Un futur outil de maintenance demanderait une décision de production distincte
et un nouveau lot. Au minimum, il devrait rester désactivé hors environnement
autorisé, utiliser une capacité dédiée plus stricte que la seule présence d’un
administrateur, vérifier un nonce et une confirmation explicite, imposer un
préflight sans écriture, attester la sauvegarde, produire un journal d’audit durable
sans donnée personnelle ni secret, et refuser toute exécution sans
autorisation explicite de production. Son protocole intersites, ses échecs
partiels, son rollback réel et sa recette WordPress/MariaDB devraient être
validés séparément avant exposition.

## Artefacts audités

Les empreintes SHA-256 brutes du snapshot historique sont :

| Fichier | SHA-256 |
| --- | --- |
| `docs/FALUSS_PRODUCTION_RESET.md` | `d7cdfbf66833bde438c23500ea8a82731dcad89ba94083e94f72ad6a2e492482` |
| `plugins/faluss-production-reset/faluss-production-reset.php` | `b74bda8939ae7b96da07adef4a10fec8c8500567a11faa9c08f646b0af98633f` |
| `plugins/faluss-production-reset/includes/class-faluss-production-reset.php` | `f4559ec0b5366439e6cf49887a7a283c41553856fe07eecd5a004fa2c8ff1ad8` |
| `tests/faluss-production-reset-fpr01-contract-test.php` | `a8df92a9f6e35c145a064b065a4d2c9e5004955e23a084f6fafa82d7bda63196` |
| `tests/faluss-production-reset-fpr011-contract-test.php` | `379b84c07c6fe127d5d25f80c97813ae7dff6d08883344d2bb38d4fdb4c780bd` |
| `tests/faluss-production-reset-fpr013-contract-test.php` | `ae2a5a4d7edfbf3b71ecaa08a9779a15b138a264f4bde4a765bb584f59631772` |

Les trois contrats historiques ont été exécutés localement. Ce sont des
harnais PHP et des contrôles statiques ; ils ne prouvent aucune recette réelle,
aucun état WordPress/MariaDB, aucune sauvegarde, aucun reset et aucun retrait en
production.
