# B3b3c — nonces de barrières sur MariaDB jetable

7 octobre 2026 ; arbre de runtime immuable
`77ecc5b5efcb9a0a770e82e79c17d60e2078d889`, copie LF, dépendances au verrou exact.
WordPress 7.1.2 / MariaDB 11.8.6 / PHP 8.5.4. Aucun site consulté.

```sh
python3 tests/TokenEngine/recipe/run.py --b3-barriers \
  --source "$SOURCE_ISOLEE" --core "$CORE_JETABLE" --cli "$CLI_JETABLE" \
  --output /var/tmp/fans-pf-b3-barrier-admission-final-checks.json
```

**272 contrôles satisfaits, dont 21 nouveaux** après les 251 précédents.
[Rapport expurgé](wordpress-checks.json) : noms et comptes uniquement,
aucune identité, clé, enveloppe, donnée privée ou export de base.
Fixture et base supprimées en fin d'exécution.

Huit admissions simultanées donnent un seul succès et sept refus de rejeu.
Même nonce et autre action restent refusés ; une nouvelle enveloppe peut
conserver la même action et clé métier. Permissions dédiées, pair exact,
lookup, installation explicite, schéma partiel/MyISAM et insertion défaillante
sont contrôlés. L'acquittement perdu après COMMIT reste inconnu, puis le même
nonce est refusé comme déjà consommé. Expiration après insertion : rollback.
Pour l'attente du mutex, le contrôleur attend un marqueur du worker avant
l'expiration ; le résultat n'est pas attribué à un simple démarrage tardif.
Le ledger officiel reste byte-identique.

Une première relance de cet arbre a expiré pendant `mariadb-install-db`
(après 90 secondes), avant tout scénario. Aucun délai ou contrôle n'a été
assoupli ; la relance complète du même arbre a réussi. Cet incident de
préparation de l'environnement est distinct d'un échec du code testé.

Suite PHP complète **655 tests / 7 000 assertions**, zéro échec et deux
dépréciations historiques ; HoF 51 / 105. PHPStan complet cible PHP 8.3,
cinq PHP lintés, deux Python compilés, sept sources comparées à l'arbre
exécuté. Liens, scan ciblé et diff check avant commit.

Cette preuve SQL suppose le message déjà authentifié. Elle ne prouve pas
le gateway HTTP, le SSO réel, le traitement Fans opening/closing, une capacité
de production ou une politique de conservation. Aucune route, activation,
migration de site, opération économique ou purge réelle ajoutée.
Voir le [contrat](../../modules/HUB-PF-B3-BARRIER-TRANSPORT.md).
