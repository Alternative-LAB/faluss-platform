# B3b2 fermé — consommation ordonnée sur le ledger officiel

Recette du 6 octobre 2026 : WordPress 7.1.2, PHP 8.5.4, MariaDB 11.8.6 et
WP-CLI 2.12.0. Racine jetable 0700, socket SQL privé primaire, bail H3 0600,
dépendances du verrou Composer exact ; achats, liens et clés entièrement fictifs.
Aucun site visité, producteur réel ou admission réseau.

```sh
python3 tests/TokenEngine/recipe/run.py --b3-ranked \
  --source "$SOURCE" --core "$WP_CORE" --cli "$WP_CLI" \
  --output "$PRIVATE_OUTPUT/hub-pf-b3-ranked-checks.json"
```

Le [rapport expurgé](wordpress-checks.json) ne contient que les noms des contrôles
B3b2, leur nombre et leur périmètre. Aucune identité, clé, preuve d'achat ou
requête privée publiée. La base et l'enclave sont supprimées à la fin.

**56 scénarios B3b2 satisfaits**, après 186 scénarios H0–H3 inchangés et 37
barrières B3b1 : **279 au total, zéro échec**. Empreinte LF du service historique
`17bcf34819cd7de662ec61fb706fd3691ca9d2f608093d4961259a0f175d5281` inchangée.

Contrôles : schéma additif explicite, impossibilité de rattacher un contexte à
un ancien fait, permissions/propriétaire, downgrade fermé, huit confirmations
concurrentes avec un seul débit, deux membres au même instant avec ordres uniques,
plusieurs lots et sessions, signature/journaux atomiques, fermeture concurrente,
expiration et régression d'horloge primaire. Comparaison des anciens PF/ALB/reçus
avant/après, sans modification des claims historiques.

Processus réellement tués avant/après COMMIT MariaDB, erreur d'acquittement
injectée dans wpdb : lookup primaire et rejeu identique retrouvent le même ordre
et ne doublent pas le débit. Ce n'est pas une panne réseau réelle. Corrections
H4 partielles, totales et litige/résolution : seul le net change, le fait d'origine
et ses signatures restent stables ; ancien reçu incapable de restaurer les points.
Époque étrangère dans un reçu antérieur et trou d'ordre masqué par le nombre de
reçus : nouveau débit refusé, sans réparation automatique.

Lint PHP, compilation Python, PHPStan complet avec cible d'analyse PHP 8.3 et
suite PHPUnit complète sont exécutés en copie LF isolée avec le verrou exact.
Le runtime local est PHP 8.5.4 ; la CI fournit la preuve runtime PHP 8.3.
Les deux dépréciations PHPUnit préexistantes sont distinguées des erreurs.

168 tests PF / 276 assertions ; suite complète **545 tests / 6 502 assertions**,
zéro erreur/échec. Analyse complète PHPStan cible PHP 8.3 sans erreur, PHP lint
et compilation Python satisfaits, scan de secrets et `git diff --check` verts.

Limites : pas de snapshot 2.0 ou HTTP classé Hub/Fans (B3c), vrai SSO, panne
réseau/replica, sauvegarde complète restaurée, score/rang public, activation
ou durée de conservation réelle. Le refus contrôlé d'états SQL contradictoires
ne constitue pas une recette de restauration d'infrastructure.
Voir le [contrat et ses frontières](../../modules/HUB-PF-B3-CLOSED.md).
