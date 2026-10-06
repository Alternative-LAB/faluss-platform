# B3c1 fermé — snapshots complets par membre, version 2.0

Recette du 6 octobre 2026 : WordPress 7.1.2, PHP 8.5.4, MariaDB 11.8.6 et
WP-CLI 2.12.0. Racine jetable 0700, bail physique H3 0600, socket SQL privé
primaire, dépendances du verrou Composer exact. Achats, liens et clés fictifs ;
aucun site, serveur cible ou admission de production.

```sh
python3 tests/TokenEngine/recipe/run.py --b3-snapshots \
  --source "$SOURCE" --core "$WP_CORE" --cli "$WP_CLI" \
  --output "$PRIVATE_OUTPUT/hub-pf-b3-snapshots-checks.json"
```

Le [rapport expurgé](wordpress-checks.json) contient uniquement les noms,
le nombre et le périmètre des scénarios B3c1. Aucune identité, preuve d'achat,
clé, requête ou page privée publiée. La base et l'enclave sont supprimées.

**41 scénarios B3c1 satisfaits**, après 186 historiques H0–H3, 37
barrières B3b1 et 56 consommations B3b2 : **320 au total, zéro échec**.
Service historique inchangé, enclave supprimée.

Couverture : génération vide, clé stable sous concurrence, 101 attributions
réelles du protocole fermé et pages 100/2, contexte/date/ordre d'origine,
multi-lots, net H4 corrigé, annulation totale et litige/résolution. Correction
en fragments inachevée ou consommation entre génération et fence : refus ;
les pages anciennes restent immuables. Fermeture de session : aucun fait
déjà confirmé supprimé. Ancien fait 0.2 : contexte explicitement `null`, sans
catégorie/session rétroactive.

Pour les 101 attributions, l'horloge **de la connexion SQL de recette** avance
dans des fenêtres de sept secondes. La limite historique de dix réservations
par minute reste appliquée ; aucun horodatage stocké, reçu ou ledger réécrit
pour contourner ce contrôle. Cette accélération fictive ne mesure pas un débit
de production et ne produit aucun score public.

Pannes d'insertion et processus tués avant/après COMMIT MariaDB ; perte
d'acquittement injectée dans wpdb, lookup/rejeu par la même clé, une génération
et aucune consommation supplémentaire. Filiation, pages/empreintes/chaîne,
époques et compteurs contradictoires refusés, sans réparation automatique.
Ces injections ne constituent ni une panne réseau réelle ni une restauration
complète d'infrastructure.

175 tests PF / 307 assertions ; suite complète 552 tests / 6 560 assertions,
zéro erreur/échec. PHPStan complet avec cible d'analyse PHP 8.3, lints PHP,
compilation Python, liens, secrets et `git diff --check`. Runtime local PHP
8.5.4 ; preuve runtime PHP 8.3 apportée séparément par la CI. Deux dépréciations
PHPUnit historiques distinctes des erreurs.

Limites : transport HTTP B3, réception durable Fans, inventaire exhaustif de
l'origine et fraîcheur continue restent des lots suivants. Un snapshot membre
n'est pas un corpus global. Aucun vrai SSO, producteur réel, score/rang public,
flag, migration de site ou politique de conservation #150 ajouté.
Voir le [contrat B3](../../modules/HUB-PF-B3-CLOSED.md).
