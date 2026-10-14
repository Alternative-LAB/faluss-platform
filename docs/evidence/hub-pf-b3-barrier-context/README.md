# B3b3b — contexte propriétaire et fraîcheur des barrières

7 octobre 2026, arbre Git immuable
`9158e581050397ba36dc48eb9b1ae2ee95a05690`, copie LF et dépendances au verrou
exact. WordPress 7.1.2, PHP 8.5.4 et MariaDB 11.8.6 jetables ; aucun site.

```sh
python3 tests/TokenEngine/recipe/run.py --b3-barriers \
  --source "$SOURCE_ISOLEE" --core "$CORE_JETABLE" --cli "$CLI_JETABLE" \
  --output /var/tmp/fans-pf-b3-barrier-context-checks.json
```

**251 contrôles réussis**, dont **28 nouveaux**, après les 223 contrôles
historiques H0/H1/H2/H3 et propriétaire de barrières. Le
[rapport expurgé](wordpress-checks.json) contient les noms et les comptes,
jamais une identité, clé, requête privée ou contenu métier. Enclave supprimée.

Les scénarios utilisent les vraies transactions MariaDB : quatre opérations et
cibles, clés/action liées, six rejeux concurrents pour un événement, lookup,
origine/politique étrangères, attente du mutex propriétaire puis expiration,
expiration après insertion d'événement et rollback intégral. La même action
reprend après un rollback certain ; le ledger reste byte-identique.

Le délai contrôlé après insertion est une injection dans le worker de test,
à l'intérieur de la vraie transaction. Il ne représente pas une panne de disque
ou la durée physique de COMMIT. Le transport HTTP et l'inbox Fans ne sont pas
testés par ce sous-lot. Les tests de signature restent ceux du codec #182 ;
aucun véritable SSO, site, ouverture économique ou score public n'est attesté.

Suite complète **655 tests / 6 976 assertions**, zéro échec et deux
dépréciations historiques. HoF 51 / 105 ; PHPStan complet cible PHP 8.3,
lint des quatre PHP et compilation des deux Python. Liens, comparaison aux
fichiers de l'arbre exécuté, scan ciblé et diff check requis avant commit.
La CI du head exact reste obligatoire avant fusion.

Voir le [contrat et le raccordement restant](../../modules/HUB-PF-B3-BARRIER-TRANSPORT.md).
