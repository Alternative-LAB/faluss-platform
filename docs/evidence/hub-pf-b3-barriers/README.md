# B3b1 — barrières Hub privées en isolation

Recette du 6 octobre 2026 : WordPress 7.1.2, PHP 8.5.4, MariaDB 11.8.6,
WP-CLI 2.12.0. Racine Linux jetable 0700, socket privé sans réseau SQL,
bail H3 0600, dépendances Composer du verrou exact ; aucun site visité.

Commande depuis une copie de travail isolée normalisée LF :

```sh
python3 tests/TokenEngine/recipe/run.py --b3-barriers \
  --source "$SOURCE" --core "$WP_CORE" --cli "$WP_CLI" \
  --output "$PRIVATE_OUTPUT/hub-pf-b3-barriers-checks.json"
```

**37 scénarios B3b1 satisfaits**, après **186 scénarios H0–H3 inchangés**
(223 au total). Le [rapport expurgé](wordpress-checks.json) contient uniquement
les noms des vérifications. Configuration, clés fictives, identités et requêtes
ne sont pas publiées. L'enclave et la base ont été supprimées par la recette.

Installation explicite, moteur/indices, permission dédiée et propriétaire,
sélection exacte des cinq types, expiration primaire, huit registrations
concurrentes, fermeture concurrente attendant le verrou de sélection, versions
non réouvrables et lookup du statut courant : satisfaits. Injection avant/après
COMMIT réel et erreur de journal : état, clé et événement atomiques ; reprise
avec la même clé, aucun acquittement inventé après perte de réponse.

Tous les anciens PF officiels, consommations, journaux, reçus H3 et ALB sont
comparés avant/après et restent identiques. Aucun nouveau débit testé par B3b1.
La perte d'acquittement est injectée dans wpdb autour du vrai COMMIT MariaDB ;
ce n'est pas une panne réseau réelle, une réplica ou une restauration.

Lint des PHP modifiés et compilation Python satisfaits ; 168 tests PF ciblés /
276 assertions, suite complète 545 tests / 6 466 assertions, zéro échec/erreur,
deux dépréciations préexistantes ; PHPStan ciblé/complet sans erreur.

Limites : B3b2 doit encore joindre contexte, ordre et reçu 0.3 au débit et
journal officiels. B3c doit prouver transport Hub/Fans et fermeture « en cours ».
Ni vrai SSO, site cible, achat, admission économique, score, rétention ou
activation ne sont démontrés ici. Voir le [contrat](../../modules/HUB-PF-B3-CLOSED.md).
