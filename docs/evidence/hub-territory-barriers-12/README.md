# Transport territorial Hub1.2 — preuves isolées

Accord explicite ALB-Origine selon [#147/6239393497](https://github.com/Alternative-LAB/faluss-platform/issues/147#issuecomment-6239393497), [validation consignée](https://github.com/Alternative-LAB/faluss-platform/issues/147#issuecomment-6239460605). [Contrat et dépendances](../../modules/HUB-PF-TERRITORY-BARRIERS-12.md).

## Exécutions distinctes

| Preuve | Source | Résultat |
|---|---|---|
| [Transport final](physical-wire.json) | a2c6f1d, arbre3b2f0d9 |383 contrôles, zéro échec, dont24 B3bt ; nettoyage confirmé |
| [Concurrence avec consommation officielle](physical-confirmation.json) | b08cc66, arbre54b057f |356 contrôles, zéro échec, dont6 B3btr ; nettoyage confirmé |
| [Socle conjoint session/participation](physical-consolidated-baseline.json) | ea1b990, arbre2790828 |564 contrôles, zéro échec, dont22 B3bt ; nettoyage confirmé |
| [Qualité](quality-proof.json) | code final a2c6f1d |813 tests/8178 assertions, HoF90/323, PHPStan0 ciblant80300 ; deux dépréciations historiques |

WordPress7.1.2/PHP8.5.4/MariaDB11.8.6 local, preuves/achats/identités/clés fictifs. Baselines communes : ne pas additionner ces totaux. [Sources exactes et empreintes](source-proof.json). Les octets de production de b08cc66 à a2c6f1d sont identiques ; l'ajout final concerne deux tests de la restriction d'échéance1.2. Les tests de consommation ne sont pas présentés comme preuve réseau d'une confirmation économique.

Arguments existants, sans modification de CI :

- Transport final : `--b3-barrier-completion-http`.
- Concurrence : `--b3-ranked`.
- Socle : `--b3-barrier-http --b3-barrier-completion-recovery --b3-barrier-acknowledgement --b1-origin-ack --b3-session-lifecycle`.

## Garanties observées

- 1.0/1.1 refusent le nouveau motif ; signatures, audiences, permissions et contenu exact restent contrôlés. Les mauvais types et admissions internationales sont refusés par Hub et Fans.
- Une perte de réponse après COMMIT local ou Hub conserve version/action/clé. Le lookup primaire termine une seule fermeture, hors transaction Fans. Une réapprobation synthétique ne crée qu'une version future ; l'ancien ACK ne la ferme pas.
- 1.2 conserve la restriction de session_completed : refus avant l'échéance primaire figée, reprise de la même clé à cette échéance.
- Fermeture territoriale avant confirmation : aucun débit. Confirmation réellement retenue par les verrous du ledger officiel : un débit acquis, puis fermeture dont l'instant est postérieur. Lookup/rejeu sans second débit, anciens claims et octets du ledger conservés.
- Expiration derrière le mutex propriétaire : fermeture refusée sans opération persistée ; une attribution toujours admissible peut ensuite être confirmée.

La première recette a échoué sur une hypothèse erronée de version initiale dans le nouveau test ; le test utilise maintenant la version réelle du descripteur. L'erreur initiale est conservée dans les notes de recette. Aucun test désactivé, aucun délai ou préparateur modifié.

## Limites et suite

Ces preuves ne couvrent pas encore le checkpoint transactionnel TerritoryService, sa collecte exhaustive par curseur, la reprise entre fragments, les suspensions d'organisateurs et la réadmission territoriale de l'UI. Ce lot livre le transport uniquement. Les contrôles du head documentaire final et du main intégré restent à vérifier séparément. Aucun site, véritable SSO Me, pair réel, achat réel, flag, migration de production ou publication. #150 et RustFS #161 distinctes.
