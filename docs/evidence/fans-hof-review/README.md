# B2c — modération de session et recours privés

Recette du 6 octobre 2026 sur WordPress **7.1.2**, PHP **8.5.4** et MariaDB
**11.8.6** jetables ; socket, base, comptes et fichiers privés propres à la
recette. Aucun site ou serveur existant consulté.

[Rapport nettoyé](wordpress-checks.json) : **40 vérifications réussies**.

- Aucun schéma installé par l'activation normale ; permissions propriétaires,
  candidats et administrateurs distinctes, aucune auto-approbation.
- Révisions et empreintes exactes, décisions concurrentes, édition invalidant
  l'approbation, refus/recours, historique privé complet sans identifiant de
  modérateur exposé aux membres.
- Participation : recours privé réservé au candidat, décision favorable pour
  l'avenir seulement, refus des doubles dossiers et de l'admission après fermeture.
- Erreur du journal : examen, dossier, admission et session annulent ensemble
  leurs écritures ; reprise avec la même révision après rollback.
- Révocation : `closing` ferme les nouveaux choix, sans prétendre à une fermeture
  Hub acquittée. Annulation non réouvrable ; suspension effective injectée pour
  tester seulement la précondition de réadmission versionnée.
- Contrôle éditorial hors transaction refusé ; retrait concurrent bloqué jusqu'à
  la fin du verrou d'admission, puis décision suivante refusée. La transaction
  de l'appelant reste ouverte jusqu'à sa propre fin.
- Journaux complets et refus d'un moteur MyISAM divergent ; aucun ledger ou score.

Lint : **25 fichiers PHP** ; PHPStan ciblé et complet sans erreur.
Règles/calculs HoF : **51 tests / 105 assertions**. Suite complète isolée :
**490 tests / 6 315 assertions**, zéro échec/erreur, deux dépréciations préexistantes.
Régressions en base : B1 **50**, B2a **42** et B2b **42** contrôles, après ajout
explicite de la modération des seules ouvertures positives de leurs fixtures.

Les liens SSO sont injectés localement : pas de preuve du véritable SSO central.
La suspension effective est une précondition SQL **signalée dans le test**,
pas une preuve réseau Hub. B3 doit tester l'acquittement primaire et les pannes.
Les critères et dossiers sont fictifs ; aucune politique réelle de conservation
n'est attestée. #150 reste distincte.

La CI rejoue `--hof-review`, avec le rapport dans `hub-pf-runtime-checks`.
Le head exact et tous ses contrôles restent le gate de fusion. Aucun changement
visuel dans ce lot ; les interfaces et captures B6 restent à réaliser.
