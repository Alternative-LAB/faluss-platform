# B2b — examen territorial privé

Recette du 6 octobre 2026, WordPress **7.1.2**, PHP **8.5.4**, MariaDB **11.8.6**
jetables, fichiers privés, socket/base neufs, aucun accès aux sites.

[Rapport nettoyé](wordpress-checks.json) : **42 contrôles réussis**. Critères publics
fictifs, localités nommées et liens SSO locaux injectés ; aucune preuve de véritable
SSO central ni reconnaissance de pays ou territoires de production.

- Permissions propriétaire/modérateur, absence de défaut et immutabilité des critères.
- Révisions exactes, décisions concurrentes, refus, révocation et recours.
- Différence local/national/international, coorganisation, version gelée et
  admissions futures seulement.
- Retrait, absence de diffusion des recours, rollback du journal et refus de MyISAM.
- Politique corrompue refusée et rattachement interdit hors transaction de session.

Lint et PHPStan ciblé/complet ; calculs et règles HoF : **51 tests / 105 assertions**.
Suite complète isolée : **490 tests / 6 279 assertions**, zéro échec/erreur,
deux dépréciations préexistantes.
La recette est rejouée en CI avec `--hof-territory`, rapport dans l'artefact
`hub-pf-runtime-checks`. Le résultat du head exact reste le gate de fusion.

Les tables et contrôles sont fonctionnels en isolation ; les interfaces HTTP/UI,
modération de session, barrières Hub et ouvertures économiques ne sont pas
fournies par ce lot. Aucune contribution, durée de conservation réelle, purge,
score public, migration automatique, flag de production ou activation.
Sans modification visuelle, aucune capture d'écran de production ou simulée.
