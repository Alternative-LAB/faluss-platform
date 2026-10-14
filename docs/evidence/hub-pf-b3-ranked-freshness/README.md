# B3c3b1 — fraîcheur dans la transaction propriétaire

Recette du 7 octobre 2026 sur arbre Git immuable
`b07a045cb3183ca4224bd298217dfff44949ff18`, copie LF avec dépendances
du verrou exact : WordPress 7.1.2, PHP 8.5.4 et MariaDB 11.8.6 jetables.
Identités, achats et clés fictifs ; aucun site ou SSO central utilisé.

```sh
python3 tests/TokenEngine/recipe/run.py --b3-ranked \
  --source "$SOURCE_ISOLEE" --core "$CORE_JETABLE" --cli "$CLI_JETABLE" \
  --output /var/tmp/fans-pf-b3-ranked-freshness-checks.json
```

**301 contrôles satisfaits**, dont **22 nouveaux** de fraîcheur, après les
279 scénarios propriétaires inchangés H0/H1/H2/H3 et B3b. Le rapport expurgé
[ci-joint](wordpress-checks.json) ne publie ni requête, identité ni clé.
L'enclave et sa base sont détruites à la fin.

- Les quatre opérations refusent une délégation expirée sans modifier les
  faits propriétaires ; une délégation fraîche retrouve la même clé.
- Expiration pendant l'attente du mutex global sur une connexion concurrente,
  puis derrière les lignes compteur/barrière : refus fermé sans débit.
- Expiration pendant l'ultime écriture de clé, après staging de la réservation
  ou du débit/reçu/journal : rollback de tous les effets. Une demande fraîche
  sous la même clé peut reprendre après ce rollback certain.
- Perte d'acquittement injectée après un vrai COMMIT : résultat inconnu,
  lookup primaire et rejeu sous la même clé retrouvent un seul reçu/ordre.
- Les claims historiques et le ledger officiel restent inchangés hors des
  opérations fictives attendues. Aucun schéma, ledger parallèle ou flag ajouté.

Les arrêts contrôlés du worker utilisent de vrais verrous et transactions
MariaDB. Ils ne simulent pas une preuve de transport HTTP 0.3, de vrai SSO,
de TLS, d'infrastructure de production ou de restauration de sauvegarde.
Le garde est exécuté avant la décision COMMIT ; il ne prétend pas mesurer
la latence interne de persistance du serveur après cette décision.

Suite PHP complète : **650 tests / 6 954 assertions**, aucun échec, deux
dépréciations historiques. PHPStan complet cible PHP 8.3 ; syntaxe des sept
PHP et des deux Python modifiés, comparaison à la copie immuable, scan ciblé,
liens relatifs et diff check. La CI PHP 8.3 du head final reste requise.

Le callback de fraîcheur est optionnel pour conserver les compositions
historiques ; le futur gateway 0.3 devra toujours le fournir depuis la
délégation signée. Aucune route n'est ouverte par ce lot. Retour arrière :
revert sans migration ; aucune donnée de site à supprimer.
Voir le [contrat B3](../../modules/HUB-PF-B3-CLOSED.md).
