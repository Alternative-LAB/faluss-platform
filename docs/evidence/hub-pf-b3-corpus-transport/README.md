# B3c2c1 — codec propriétaire signé, sans admission HTTP

Copie LF immuable du 6 octobre 2026, PHP 8.5.4, dépendances Composer du
verrou exact. PHPStan complet avec cible PHP 8.3. Ce sous-lot pur n'ouvre
ni route, ni nonce SQL, ni schéma, ni réception Fans.

```sh
php vendor/bin/phpunit tests/TokenEngine/PurchasedPf/CorpusTransportTest.php
php vendor/bin/phpstan analyse --no-progress --memory-limit=1G
php vendor/bin/phpunit
```

**33 tests ciblés / 75 assertions** ; suite complète **620 tests /
6 805 assertions**, zéro erreur ou échec, deux dépréciations historiques.
Syntaxe des deux PHP, liens, scan ciblé de secrets et `git diff --check`.

Quatre lectures uniquement, origine/politique/read/clé/opération liés au
contexte signé, droits dédiés, aucun destinataire fourni par Fans. Refus de
changement de clé même avec une forme valide, autre audience/pair, contexte
futur/expiré, signature altérée, clé inconnue/révoquée ou différente, ancien
domaine PF/snapshot, opération économique et curseur d'une autre génération.
Réponse liée au nonce et SHA de la demande, manifeste/policy/origine exacts,
page attendue, fence/digest/date primaire et refus sans résultat fabriqué.

Payload signé proche de 4 MiB réellement encodé, signé et vérifié dans
l'enveloppe externe, sans augmenter les anciennes limites. Les clés de ces
exemples de codec sont fictives. La vérification avec **clés distinctes par
nœud, réseau HTTP, nonce SQL, réponse perdue et inbox atomique** appartient
au sous-lot suivant ; ces tests ne l'attestent pas et ne prouvent pas le SSO Me.
Les 409 scénarios SQL propriétaires sont la preuve distincte de #175.

Voir le [contrat et les étapes](../../modules/HUB-PF-B3-RANKING-CORPUS.md).
Aucune route de production, achat réel, activation, flag, ledger parallèle,
score public ou politique de conservation ajoutés.
