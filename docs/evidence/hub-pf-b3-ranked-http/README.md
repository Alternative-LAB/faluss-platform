# B3c3b2 — gateway 0.3 en HTTP privé

7 octobre 2026, arbre Git immuable
`8568dc842a2b40cc67678c4e3baf369e9820f9c4`, copie LF et dépendances au verrou
exact. WordPress 7.1.2, PHP 8.5.4, MariaDB 11.8.6 ; deux bases et deux clés
distinctes, exclusivement fictives. Aucun site consulté.

```sh
python3 tests/TokenEngine/recipe/run.py --b3-ranked-http \
  --source "$SOURCE_ISOLEE" --core "$CORE_JETABLE" --cli "$CLI_JETABLE" \
  --output /var/tmp/fans-pf-b3-ranked-http-checks.json
```

**339 contrôles satisfaits, dont 38 nouveaux**, après les 301 historiques,
B3 propriétaires et de fraîcheur. Le [rapport expurgé](wordpress-checks.json)
publie seulement les noms, comptes et versions ; aucune identité, preuve,
requête, clé ou réponse privée. Les deux WordPress et leur base sont supprimés.

- Les quatre opérations privées passent par HTTP signé sur loopback.
- GET, mauvais type, JSON invalide et corps JSON valide trop grand sont refusés
  avant mutation. WordPress traite le JSON invalide en 400, le gateway les
  violations de type/taille en 403 ; ces chemins sont testés séparément.
- Signature altérée, mauvaises audiences/domaines, clés inconnues/révoquées,
  permission historique insuffisante, délégation manquante, temps ou liaison
  de clé/opération incorrects : refus avant nonce ou débit.
- Huit requêtes concurrentes avec le même nonce : une seule admission et un
  seul débit. Une enveloppe fraîche retrouve la même opération et le même reçu.
- Une autre identité Créateur ne remplace pas une attribution existante.
- Réponse étrangère, mauvais nonce/digest/signature/audience ou clé Hub révoquée
  ne produisent pas de preuve acceptable côté vérificateur Fans.
- Reçu stocké temporairement corrompu après commit : résultat inconnu, jamais
  refus signifiant rollback. Après restauration de la preuve fictive, lookup
  sous la même clé retrouve la consommation.
- Corps HTTP perdu après un vrai COMMIT : lookup primaire et rejeu gardent
  le même reçu, sans second débit. Expiration derrière le mutex propriétaire :
  refus sans débit, même si le nonce réseau avait déjà été admis.

Les enveloppes sont signées et vérifiées dans les deux WordPress, puis
transmises par le contrôleur Python. Cette recette prouve le gateway HTTP et
le protocole, **pas encore le client durable Fans ni la résolution d'une session
SSO réelle**. Elle ne prouve ni TLS, panne d'infrastructure, réplica/restauration,
dimensionnement ou admission de production. Aucun score ni achat réel.

Suite PHP complète **650 tests / 6 965 assertions**, zéro échec et deux
dépréciations historiques. PHPStan complet cible PHP 8.3, HoF 51 / 105 ; cinq
PHP lintés, trois Python compilés. Comparaison avec l'arbre exécuté, liens,
scan ciblé et diff check vérifiés avant commit. La nouvelle étape CI additive
n'est pas utilisée comme seule validation de sa propre modification.

La route existe seulement dans un loader de tests exclu du ZIP. Le test unitaire
vérifie qu'un WordPress ordinaire n'enregistre aucune route ni requête SQL, même
avec des constantes de recette copiées. Voir le [périmètre fermé](../../modules/HUB-PF-B3-CLOSED.md).
