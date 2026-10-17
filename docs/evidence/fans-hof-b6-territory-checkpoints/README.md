# Checkpoints territoriaux B6 — preuves et limites

Recettes exécutées uniquement sur une paire Hub/Fans jetable avec achats,
identités, clés et données fictifs. Aucun accès aux sites, véritable SSO Me,
flag de production ou opération réelle. Le [contrat et les responsabilités](../../modules/FANS-HOF-B6-TERRITORY-CHECKPOINTS.md)
restent fermés ; ce dossier ne constitue pas une autorisation d'activation.

## Première recette complète : admissions

- [Rapport physique](admission-checks.json) : **601 contrôles, zéro échec**, dont
  **35 B6tc** ; `fixture_removed=true`.
- [Source et limites exactes](admission-source.json) : arbre exporté en LF
  `987628c980b17e7a9abdc808e06d4c0445c69ccc`, base de travail `a2c6f1d`.
- WordPress7.1.2, PHP8.5.4, MariaDB11.8.6. Le runner CI utilise d'autres versions,
  indiquées séparément dans les contrôles GitHub.

Le rapport vérifie une décision et son journal atomiques, un schéma absent ou
partiel refusé, les permissions, vingt-trois versions réellement admises par Hub,
la collecte exhaustive au-delà de vingt, le rollback d'un fragment, les réponses
perdues, les reprises sous la même clé et l'absence de deuxième fermeture des
contextes historiques. La nouvelle approbation ne crée qu'une admission future
après acquittement de l'ancienne ; le consentement et les faits restent inchangés.
Un ancien acquittement ne peut retirer la version suivante. Le ledger officiel
est comparé en fin de recette et aucun ledger parallèle n'est créé.

Cette source précède le traitement dédié des sessions de coorganisateurs et les
derniers scénarios d'échec d'application d'un élément. **Elle ne prouve pas ces
ajouts** ; leur recette finale distincte est présentée ci-dessous. Les totaux des recettes
qui partagent une baseline ne s'additionnent pas.

## Recette finale du raccordement fermé

- [Rapport final](final-checks.json) : **611 contrôles, zéro échec**, dont
  **45 B6tc** ; `fixture_removed=true`, mêmes versions WordPress/PHP/MariaDB locales.
- [Source finale](final-source.json) : code `51fe9712`, arbre de recette exact,
  égalité des sources et limites explicites. [Qualité](quality-proof.json).

Les cas supplémentaires couvrent les pertes de COMMIT avant HTTP, l'échec local
d'application d'un élément après vraie fermeture Hub, une modification de
déclaration approuvée et la perte de territoire d'un coorganisateur qui n'est
pas propriétaire de la session. Sa reprise suspend le vrai parent avec le motif
existant, sans lui donner un pouvoir général de propriétaire. Une invalidation
touchant ses deux rôles termine les deux dimensions indépendantes. Après
réexamen et réadmission, l'ancienne réponse ne peut suspendre le nouveau parent.
La fermeture d'organisateur déjà appliquée se reprend sans autre opération.

## Qualité avant et après intégration de main

Code de la recette611 sauvegardé au commit `51fe9712e672807fd6c87c7dd8101ad01d3ef660` ; mêmes
octets PHP/Python que l'arbre exporté `e34eb6351cff5f9dd6f04120df028297a9b86a32`
pour la recette finale, hors textes de changelog/documentation. Qualité locale :
**813 tests PHP / 8 217 assertions**, dont90/323 HoF, PHPStan sans erreur avec
cible PHP80300. Deux dépréciations historiques conservées. Huit fichiers PHP
lintés, deux Python compilés, dix sources comparées à l'export LF, neuf liens
relatifs vérifiés, scan de secrets ciblé et `git diff --check` réussis.

La jonction avec main `cd42356` conserve les formulaires et tests de #231 ainsi
que le transport #233. Elle corrige aussi leur checkpoint de présentation : une
approbation territoriale invalidée ou un ancien contexte fermé ne peut pas être
présenté comme une admission courante confirmée. Deux contrôles physiques ont
été ajoutés ; leur résultat sur le nouveau head reste attendu en CI.
La [qualité conjointe](integrated-quality.json) sur l'arbre3cda352 donne
**814 tests /8 294 assertions**, HoF90/323 et PHPStan0. Cette preuve de qualité
ne remplace pas la recette physique du nouveau head ou celle du futur main.

Une première exécution s'est arrêtée avant la politique territoriale parce que
le schéma métier B2 n'avait pas été installé explicitement dans cette fixture.
Le worker applicatif utilise désormais son installateur métier existant ; aucun
préparateur d'environnement ou contrôle n'a été modifié. Une deuxième exécution
s'est arrêtée sur un timeout du lot HTTP historique, puis sur l'attente d'arrêt
MariaDB. Elle n'a pas produit de rapport réussi ; ses processus se sont arrêtés
et sa seule racine temporaire a ensuite été vérifiée et supprimée. La cause de
ce timeout n'est pas présentée comme établie ou corrigée par un réglage.

## Preuves encore distinctes

- La CI du futur head et la vérification du futur main intégré ne peuvent pas
  être déduites de ces rapports locaux.
- Le parcours territorial natif, son masquage public par dimension et les choix
  d'intention Fan restent à raccorder. Aucun achat réel, score public, référentiel de production, durée de conservation
  applicable ou stockage RustFS privé n'est validé ici. #150 et #161 restent distinctes.
