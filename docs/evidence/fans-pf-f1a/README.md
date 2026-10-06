# Preuve locale F1a fermé — 6 octobre 2026

Base `main` : `d00f41faa94dc8bc2542873c7434485baa7a5f96` (0.12.6).
[Contrat](../../modules/FANS-PF-F1A-CLOSED.md) et
[recette reproductible](../../../tests/TokenEngine/recipe/README.md#f1a--projections-privées-reconstruisibles).

## Résultats

- [Rapport sans données privées](checks.json) : **388/388**, zéro échec,
  **349 contrôles H0–H4 inchangés + 39 F1a**. Les fixtures sont supprimées.
- Deux WordPress 7.1.2 distincts, MariaDB 11.8.6, PHP 8.5.4 ; bases et socket
  privés jetables, sources/dependencies physiques normalisées LF. Liens SSO,
  identités, clés, pairs et preuves d'achat **fictifs**.
- Ancien workflow/recettes H0–H4 de la base exécutés contre les nouvelles sources
  avant extension CI : 349/349. La nouvelle étape ne remplace aucun ancien gate.
- PHPUnit : **439 tests / 6 039 assertions**, dont 8 tests / 39 assertions F1a.
  Deux dépréciations Reflection préexistantes sur PHP 8.5 ; CI PHP 8.3 séparée.
- PHPStan niveau 7 / cible 8.3 : aucune erreur. Sept PHP lintés, syntaxe Python
  valide, 34 scripts de contrats historiques valides, scan de secrets et diff propres.

## Preuves et limites

Les cache/projections sont réellement persistés et reconstruits depuis le dernier
jeu complet H4 : attributions multi-lots uniques, dimensions Fan/Créateur séparées,
pack/bonus/claims exclus, corrections partielles/totales, litige et résolution,
rejeu ancien refusé, cache corrompu/supprimé et reconstruction concurrente.
INSERT échoué, COMMIT exécuté avec acquittement perdu et SIGKILL avant/après COMMIT
vérifient la reprise sans état partiel ni restauration de points annulés.

Les attentes de verrou source sont constatées dans la section active de l'état
natif InnoDB, pour mise à jour d'un membre et insertion effective d'un nouveau
snapshot complet. Le contenu SQL de cet état reste privé en mémoire ; seul le
résultat du contrôle apparaît dans le rapport. L'insertion est exercée via la
fin H4 attestée, pas par une simple préparation qui n'insère aucune source.

Le hash LF du service propriétaire historique est inchangé :
`17bcf34819cd7de662ec61fb706fd3691ca9d2f608093d4961259a0f175d5281`.
Les lignes PF/ALB sont comparées ; F1a n'ajoute aucune consommation ou ledger.

Il s'agit du corpus privé **`reconciled_members`**, au vecteur d'attestations H4
accepté. Aucun inventaire global, fraîcheur continue, vrai SSO/TLS, achat réel,
classement/période/session public, restauration cohérente d'exploitation ou durée
réelle #150 n'est prouvé. Aucun site, flag, migration réelle ou release.
Les preuves CI du head exact sont attachées à la PR, distinctes de ce rapport local.
