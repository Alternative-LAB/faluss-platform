# B6 — preuve du pont privé de participation

Source `40fda2bfaa0bfb76bcef7d004c8e1f732360c7c5`, arbre
`3724a310596c0596b078603c9d73859f5d9ec9fd`, après intégration du main de
coorganisation `1c9c5671befcb74a9f47a3ada9f7199aeb7dd891`. Les ajouts de ce
dossier ne modifient pas le runtime testé. Cette preuve de branche ne remplace
ni la CI du futur head ni la vérification séparée après intégration sur main.

## Résultats

- Qualité : **806 tests / 8 119 assertions**, HoF ciblé **87 / 309**, PHPStan
  sans erreur, deux dépréciations historiques. PHP 8.5.4, analyse ciblant 8.3.35.
- [Recette physique](physical-checks.json) : **527 contrôles / 0 échec**, dont
  **37 B6pa**, WordPress **7.1.2**, MariaDB **11.8.6**, PHP **8.5.4**,
  `fixture_removed=true`. Exécution des options existantes `--b3-barrier-http`,
  `--b3-barrier-completion-recovery`, `--b3-barrier-acknowledgement`,
  `--b1-origin-ack` et `--b3-session-lifecycle`. Préparation, budgets et runner
  inchangés ; les nouveaux cas applicatifs sont identifiés par leur préfixe.
- [Recette existante de modération/recours](existing-review.json) : **40 contrôles**
  réussis, script existant `admission-wordpress.py --test --hof-review`, contrôleur
  terminé avec code0. Serveurs arrêtés puis seule cette fixture supprimée après
  vérification de l'absence de processus ; les exports protégés sont préservés.
- Sept PHP modifiés lintés, deux Python compilés, neuf fichiers de runtime/tests
  comparés octet pour octet à l'export, liens relatifs, scan ciblé de secrets et
  `git diff --check` réussis. [Empreintes et provenance](source-proof.json).

Les 498 contrôles du prototype antérieur, sans origine B1 conjointe ni les
derniers cas de recours, restent une preuve locale de développement ; ils ne
sont pas substitués aux 527 contrôles de cette source consolidée.

## Garanties effectivement exercées

- Installation explicite de trois tables privées InnoDB ; absence d'installation
  au bootstrap et refus d'une structure partielle.
- Session réellement acquittée sur Hub avant admission ; demande volontaire,
  acteur autorisé, décision concurrente unique, révision périmée refusée.
- Faluss ID lu par le contrat SSO verrouillé, distinct de l'identifiant de profil.
  Versions d'admission continues indépendantes des révisions de rôle/session.
- Audit B2, rôle et checkpoint annulés ensemble si une insertion échoue.
- Réponse d'enregistrement perdue après COMMIT Hub, lookup primaire et reprise
  de la même action sans second événement propriétaire.
- Retrait `closing` immédiat, fermeture signée puis `withdrawn`, ancien ACK
  incapable de réouvrir ; réadmission version2 avec révision locale6.
- Reprise d'un retrait après suspension du profil, sans réexiger sa diffusion.
- Recours réel : permission modérateur, transaction/mutex exigés, rollback du
  dossier, de son journal et de l'admission si le checkpoint échoue ; décision
  favorable unique et impossibilité de réutiliser le dossier décidé.
- COMMIT local incertain et retrait avant le premier checkpoint inbox ; mort du
  processus avant/après COMMIT et reprise de l'action durable.
- Ledger officiel et claims historiques inchangés, aucun ledger parallèle Fans.

## Limites précises

Identités et liens SSO locaux fictifs, preuves et pairs fictifs, véritables
WordPress/MariaDB et signatures privées en isolation. **Pas de preuve du vrai
SSO Me**, de compte réel, du site cible, de son thème/Elementor ou de production.
Ce lot ne modifie aucune vue : captures sans objet. Les formulaires de
participation, le panel raccordé, les territoires et choix d'intention B6 restent
à terminer et à tester visuellement ensemble avant livraison officielle.

Le pont ne qualifie pas rétroactivement un rôle ancien sans binding. Une
fermeture dont l'ouverture jamais confirmée est désormais refusée par Hub garde
son état en cours ; aucune clé de remplacement ou fausse fermeture ne contourne
le contrat actuel. Toute extension Hub éventuelle exige un accord distinct.
Voir le [contrat local et la reprise](../../modules/FANS-HOF-B6-PARTICIPATION-BRIDGE.md).

Aucun contrat réseau nouveau, achat réel, PF/PC mélangés, wallet Créateur,
activation, migration de site, modification CI ou publication. Conservation150
et RustFS161 restent distincts. Retour arrière : retirer l'appelant fermé, en
conservant bindings et journaux pour la reprise ; aucune purge de preuve proposée.
