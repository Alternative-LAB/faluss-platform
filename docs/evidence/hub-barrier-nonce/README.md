# Barrières Hub — admission concurrente des nonces

## Défaut reproduit et correction

Deux requêtes dont les signatures de requête et de contexte sont réellement
vérifiées portent deux nonces frais différents, dans le même intervalle de
l'index primaire existant. Synchronisation juste avant les deux véritables
INSERT : les deux SELECT d'absence FOR UPDATE ont pris des verrous de plage
compatibles entre eux, mais qui empêchent les insertions concurrentes.
MariaDB sous REPEATABLE READ détecte un deadlock et annule une transaction :
une seule admission/ligne, l'autre retourne h2_storage_unavailable.
Le diagnostic InnoDB a été observé dans l'instance jetable avant son nettoyage.

Source ancienne : arbre `dfd03b349b25dc6ae645d17afb4f9c68e78f5c73` ;
ClosedBarrierAdmission y est byte-identique à main9659927. Le défaut est
reproduit sur le vrai chemin d'admission, sans appel propriétaire ou PF écrit.
Ce résultat correspond à un risque du chemin de l'ancien B3beh intermittent ;
il ne prouve pas la cause de chaque ancien échec CI, dont les bases ont disparu.

Correction : le mutex pair/nonce est toujours acquis **avant** la transaction.
L'existence est lue sans FOR UPDATE, donc sans verrou de plage. Le mutex
sérialise les rejeux identiques, la clé primaire unique reste contraignante et
les nouvelles transactions commencent après les admissions déjà commises sous
ce mutex. Aucun niveau d'isolation, attente, contrat, clé métier ou helper
partagé des écritures économiques n'est changé.

## Résultats et périmètre

Source après correction : commit `43a5e59b8e243d4cfebdf18bc48aa6ab35b6d998`,
arbre `adba6735e4e75ab144ab87c3b3e30abb4b4dc965`. Les ajouts de preuve qui suivent
ne changent pas le runtime testé. [Avant/après](before-after.json) et
[rapport complet](physical-checks.json).

- Qualité : 802 tests / 8004 assertions, HoF84/291, PHPStan0, deux dépréciations historiques.
- WordPress7.1.2 / PHP8.5.4 / MariaDB11.8.6 : **490 contrôles / 0 échec**, dont
  **23 cas completion HTTP** (21 existants + deux cas d'intervalle), fixture_removed=true.
- Deux nonces frais simultanés dans le même intervalle : deux admissions commises ;
  les deux rejeux HTTP refusés et aucun événement propriétaire ajouté.
- Les huit rejeux simultanés d'un même nonce, les huit close signés, l'expiration
  après verrous/INSERT, rollback, COMMIT incertain et reprise même clé sont conservés.
- Ledger et claims historiques inchangés. Aucun nouveau schéma, producteur d'achat,
  pair réel, API Fans, migration, flag, politique de rétention ou activation.

Les deux nouveaux cas sont ajoutés au module applicatif déjà exécuté par la
recette completion HTTP. Aucun workflow, préparation d'environnement, cache,
délai ou runner n'est modifié ; les tests historiques restent présents.
Le worker CLI n'accepte que l'enclave physique, un fichier d'entrée privé de
la fixture et deux signatures valides. Pas de route supplémentaire.

Aucun site réel ou environnement de production testé. Retour arrière : revert
du correctif et des cas ajoutés ; aucune donnée ou migration à retirer. Cela
réintroduirait le risque de deadlock, sans changer les règles de rejeu.
