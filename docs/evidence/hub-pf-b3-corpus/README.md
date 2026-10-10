# B3c2b2 fermé — générations exhaustives et fence primaire

Recette du 6 octobre 2026 : WordPress 7.1.2, PHP 8.5.4, MariaDB 11.8.6,
WP-CLI 2.12.0. Source LF immuable et dépendances du verrou Composer exact ;
racine jetable 0700, bail physique H3 0600 et socket SQL privé primaire.
Toutes les preuves, identités et clés sont fictives ; aucun site ni vrai SSO.

```sh
python3 tests/TokenEngine/recipe/run.py --b3-corpus \
  --source "$SOURCE" --core "$WP_CORE" --cli "$WP_CLI" \
  --output "$PRIVATE_OUTPUT/hub-pf-b3-corpus-checks.json"
```

**51 scénarios supplémentaires satisfaits, 409/409 au total, zéro échec.**
Le [rapport expurgé](wordpress-checks.json) publie uniquement leurs noms,
périmètre et mesures ; aucune identité, clé, preuve privée ou requête.
Enclave et base supprimées ; service PF historique LF inchangé :
`17bcf34819cd7de662ec61fb706fd3691ca9d2f608093d4961259a0f175d5281`.

Couverture : corpus vide, 101 faits sur deux pages, nouveau membre,
manifestes/curseurs immuables et génération unique sous six appels concurrents.
Même clé après COMMIT incertain ou décès du processus avant/après COMMIT,
avec lookup primaire ; aucun débit ajouté par matérialisation, lecture ou fence.
Corrections H4 partielles/totales, litige/résolution et modification du vecteur
de preuve sans changement du net : génération obsolète. Un rapprochement
H4 incomplet ferme la lecture exacte de tout le périmètre ; les pages
historiques restent figées et ne prétendent pas être courantes.

Matérialisation concurrente avec consommation, puis fence concurrente avec
correction : même mutex propriétaire réel, sans inversion vers un verrou membre.
Une consommation postérieure ou un fragment en cours impose une nouvelle
génération, sans tronquer le corpus ni restaurer d'anciens points annulés.
Pages manquantes/altérées, curseurs contradictoires, moteur non transactionnel,
époque restaurée et compteurs divergents refusés sans réparation automatique.
Tous les anciens reçus signés et faits PF/ALB restent inchangés.

### Mesures et limites

| Opération | Faits | Détention du mutex global |
| --- | --- | --- |
| materialize | 102 | 2779.042 ms |
| finish | 102 | 2980.048 ms |

Durée mesurée sur les vrais `GET_LOCK`/`RELEASE_LOCK` de cette recette,
incluant les vérifications propriétaires et le COMMIT. Elle ne valide pas un
dimensionnement de production : sérialisation bloquante, coûts croissants,
aucune extrapolation à 1 000 faits, aucune mesure d'infrastructure cible.
Bornes prudentes fermées : 1 000 reçus globaux, 32 MiB de faits canoniques et
4 MiB par page ; dépassement = refus entier, jamais résultat partiel.
Le test du dépassement utilise des métadonnées synthétiques restaurées
ensuite ; il ne représente pas 1 001 consommations vérifiées. Un compteur
seul contradictoire est refusé séparément avant l'évaluation de capacité.
L'horloge SQL fictive avance pour respecter le quota historique ; aucun reçu,
horodatage stocké ou ledger n'est réécrit pour contourner celui-ci.

587 tests / 6 721 assertions, deux dépréciations PHPUnit historiques ;
PHPStan complet avec cible PHP 8.3, lints PHP, compilation Python,
liens documentaires, scan de secrets ciblé et `git diff --check` réussis.
Le runtime local PHP 8.5.4 est distinct du runtime PHP 8.3 de la CI.

La fence atteste son instant primaire UTC6, pas une absence de correction future.
Transport signé, remplacement atomique Fans et fraîcheur continue restent le
sous-lot suivant. Aucun achat réel, classement public, flag, migration sur site,
ledger parallèle ou politique de conservation #150. Voir le
[contrat approuvé](../../modules/HUB-PF-B3-RANKING-CORPUS.md).
