# B6 — participation après suspension et réadmission de session

Lot fermé, recette isolée acquise. Il utilise exclusivement `ranking-barriers/1.0.0` et le
motif `session_suspended` déjà approuvé ; aucun nouveau contrat, permission,
schéma, ledger ou opération économique Hub n'est introduit.

## Règles conservées

La participation reste individuelle et volontaire. La réadmission conserve les
règles figées et leur consentement initial : elle ne crée pas de participant et
ne transforme pas une demande/refus/retrait en admission. Seul un rôle déjà
admis, lié à son identité vérifiée et à l'ancienne version, peut être requalifié.
Les services B2 doivent avoir réellement réadmis la session avec une nouvelle
version et obtenu son acquittement primaire. Un état local, une date ou un
paramètre navigateur ne constituent pas cette preuve.

## Reprise durable

1. Vérifier le rôle, sa révision, les règles figées et le nouvel acquittement de
   session sous les mutex et transaction primaires existants.
2. Retrouver la fermeture de l'ancien parent **déjà appliquée** par le service
   propriétaire, avec empreinte, action, version et motif exacts. Une annulation
   ou une fermeture en attente ne vaut pas suspension confirmée.
3. Préparer la fermeture de l'ancienne admission avec `session_suspended` ;
   conserver la même action et clé lors des reprises. Aucun HTTP sous transaction.
4. Après preuve primaire de cette fermeture seulement, changer atomiquement la
   révision et la date d'admission future, son journal et le binding de version
   suivante. L'identité, les règles et les anciens descripteurs restent inchangés.
5. Reprendre l'enregistrement de la nouvelle admission. Un COMMIT local incertain
   se résout par relecture de la version déjà créée, sans en produire une autre.

Le début admissible devient le maximum du début figé et de cette nouvelle date
serveur. Aucun rattachement rétroactif, seconde consommation ou avantage
d'ancienneté n'est créé. Les contributions anciennes restent corrigibles.

Le retrait volontaire peut gagner la course : il ferme immédiatement les futurs
choix. Si la fermeture de l'ancien parent est déjà attestée, son acquittement
permet de terminer ce retrait sans deuxième fermeture Hub ni nouvelle admission.
Un ancien ACK ne peut retirer ou réouvrir la version suivante.

## Portée et vérification

API locale fermée `ClosedParticipationLifecycle::requalify`, pas de route ou
factory de production, cron ou migration automatique. L'interface sera raccordée
après les preuves physiques et l'intégration du formulaire de participation.
Les recettes couvrent acteurs exclus, autre motif/version, rejeux, rollback de
version, réponse perdue après COMMIT Hub/local, retrait concurrent, deux reprises
concurrentes et immutabilité des descripteurs antérieurs.

[Preuves physiques acquises](../evidence/fans-hof-b6-participation-requalification/README.md) :
542 contrôles sans échec, dont15exécutions du cycle croisé ; fixture supprimée.
Les contrôles du head final et du futur main consolidé restent distincts. Aucun achat, score réel, pair de
production, site, flag ou publication n'est autorisé par ce document. Conservation
#150 et RustFS #161 restent distinctes.
