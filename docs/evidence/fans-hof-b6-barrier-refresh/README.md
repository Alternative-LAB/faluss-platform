# B6 — relecture primaire des barrières en isolation

14 octobre 2026, tree de recette
`7d0ce892296c017ba43a9c590ac7c17ff1a518ae`, depuis la branche #208.
Deux WordPress **7.1.2**, PHP **8.5.4**, MariaDB **11.8.6**, clés/identités et
données fictives, baux POSIX privés et HTTP loopback.

Commande : `tests/TokenEngine/recipe/run.py --b6-barrier-refresh --source
<export> --core <wordpress> --cli <wp-cli.phar> --output <rapport>`.
[Rapport](wordpress-checks.json) : **426/426**, zéro échec,
**27 B6br**, `fixture_removed=true`. Checkpoints avant HTTP, préparations
concurrentes, réponses désordonnées/altérées/expirées, audience, confiance,
COMMIT local incertain, réponse Hub perdue, reprise sans mutation/clé nouvelle,
rollback du callback, fermeture locale et primaire, not_found sans fallback.
Ledger officiel et claims historiques inchangés ; aucun ledger PF Fans.

Qualité au tree `fec9d9533f8cff05bdcca81f4edb0f3bf6e0bd95` : **722 tests /
7 532 assertions**, HoF **84/291**, PHPStan cible8.3 sans erreur, deux
dépréciations historiques. Runtime PHP et tests PHPUnit identiques au tree
de recette ; changements ultérieurs dans les tests Python et leurs totaux,
documentation et libellé de portée du rapport seulement. PHP modifiés lintés,
Python compilé ; diff, liens et scan ciblé à conserver avant commit.

## Défaut de harnais reproduit et corrigé

La première recette avait atteint les 21 premiers contrôles B6br, puis échoué
en relisant après le test de corruption. Le harnais recalculait l'empreinte
de restauration sur un JSON échappé par le client MariaDB. La garde refusait
cette empreinte différente, conformément à son contrat. Le test restaure
désormais l'empreinte originale lue avant corruption et **relit réellement**
le résultat ; le contrôle de restauration et la suite entière réussissent.
Aucun contrôle du plugin assoupli ni permission contournée.

## Limites

La preuve porte sur la branche, pas sur le futur main intégré, sa publication
ou les sites. Les lectures courantes testées concernent les enregistrements
1.0 ; la compatibilité générale des actions/clôtures 1.1 est rejouée dans
les contrôles préalables, sans revendiquer une UI complète de fin de session.
Pas de vrai SSO, achat réel, admission de pair, migration, dimensionnement ou
fraîcheur future continue. Le fournisseur B6 doit encore composer les barrières
de participation/Créateur/pays, le corpus/cache et l'identité/visibilité locale.
RustFS #161 et conservation #150 restent distincts. Aucune release/activation.
