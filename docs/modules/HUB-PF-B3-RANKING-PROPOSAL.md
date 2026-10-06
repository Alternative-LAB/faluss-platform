# B3 — proposition Hub : ordre et contexte de classement attestés

**Statut : contrat nouveau non validé.** Propriétaire habilité : ALB-Origine.
Les règles produit [F1b R2](FANS-PF-F1B-PROPOSAL.md) sont approuvées, pas cette
extension. Aucun code Hub, opération économique ou format H3/H4 changé par #163.
Périmètre proposé : Hub/Fans jetables, identités, achats, pairs et clés fictifs ;
aucune admission sur site ou source d'achat réelle.

## Constat vérifié

`PrivateReceipt` et `SnapshotDocument` attestent `confirmed_at`, attribution,
consommation, ledger UUID et filiation des allocations. Ils n'attestent ni ordre
propriétaire des consommations, ni origine/catégorie/session/admission F1b.
`ledger_entry_uuid` n'est pas une chronologie. Le tri H4 par lot/allocation est
un ordre de transport du snapshot, pas celui de consommation. F1a ne remplace
pas ces autorités par une date réseau ou une séquence locale.

## Versions et compatibilité proposées

- Conserver sans changement `fans.hub-purchased-pf/0.2.0` et
  `hub.purchased-pf.snapshot/1.0.0`, leurs signatures/domaines, DTO, claims et tests.
- Ajouter explicitement `fans.hub-purchased-pf/0.3.0` pour contexte/reçu et
  `hub.purchased-pf.snapshot/2.0.0` pour les faits rapprochés contenant les nouvelles
  attestations. Sélection de version explicite entre pairs ; aucun ajout de champs
  accepté silencieusement par un validateur ancien.
- Les anciennes preuves restent utilisables par H1–H4/F1a selon leurs contrats.
  Elles n'acquièrent pas rétroactivement de contexte ou d'ordre F1b. Sans preuve
  nouvelle complète, B4/B5 refusent une place publique.

## Ordre propriétaire proposé

| Champ | Type et autorité |
| --- | --- |
| `ordering_epoch` | UUID immuable de l'origine Hub de cet ordre ; défini par Hub, conservé à la sauvegarde/reprise |
| `consumption_order` | Entier positif canonique encodé en chaîne décimale, au plus 9007199254740991 ; unique dans l'époque, attribué par Hub |
| `confirmed_at` | Instant UTC à six décimales, horloge primaire Hub, immuable pour le fait consommé |

Recommandation : compteur transactionnel **de métadonnées**, sans quantité PF,
protégé par une ligne InnoDB verrouillée. Dans la même transaction que débit
officiel, consommation, reçu et journal : attribuer l'ordre, enregistrer le fait
et signer les attestations. Le verrou est conservé jusqu'au COMMIT. Rollback =
aucun fait ni consommation ; réponse perdue après COMMIT = même ordre retrouvé
par lookup primaire, jamais ordre nouveau. La contrainte SQL interdit les doublons.

Cet ordre est total entre les membres d'une même origine Hub, pas un compteur
Fans ou un `AUTO_INCREMENT` interprété comme ordre de commit. Le point de
linéarisation est propriétaire. Attester l'époque dans le manifeste et l'ordre
dans chaque ligne d'allocation ; les allocations du même fait portent le même
triplet. Reçus, lookup et snapshots concordent exactement. Une correction ne
modifie jamais ce triplet ; une restauration conserve ordre/époque et ne produit
pas un second fait. Époques contradictoires ou source restaurée incomplète :
projection indisponible, pas d'ordre arbitraire entre autorités.

Coût : sérialisation courte de la confirmation à l'intérieur de la transaction.
Une architecture distribuée ou plusieurs autorités d'ordre demande un contrat
ultérieur, pas une fusion d'ordres par Fans.

## Contexte de dimensions proposé

L'intention versionnée reçoit un objet canonique `ranking_context`, fourni par
le serveur Fans autorisé, jamais directement par le navigateur. Il comprend :

| Champ | Contenu |
| --- | --- |
| `origin_id`, `policy_version` | Origine admise et version immuable des règles F1b |
| `creator_category`, `category_revision` | Catégorie approuvée et décision/version qui la fixe |
| `country_policy_revision` | Référence de la décision Faluss sur les pays autorisés, sans inventer de pays admis |
| `sessions` | Liste canonique sans doublon, de zéro à dix rattachements explicitement choisis |
| Par session | ID, version des règles, version d'admission du Créateur, bornes UTC, référence territoriale/admission pertinente |
| `context_sha256` | Empreinte JCS du contexte, liée à l'intention, clé, consommation, reçu, journal et snapshot |

Une catégorie/session ne change pas par relivraison ou nouveau snapshot. Les
corrections gardent la filiation du contexte et remplacent seulement le net
admissible selon H4. Plusieurs dimensions = un débit et une contribution par
dimension, pas plusieurs opérations de consommation.

Fans est propriétaire des règles, admissions, consentements et identités locales.
Hub atteste ce qu'il a validé/lié à **son** consommation, sans héberger le profil,
les médias, les scores ou un second ledger. Admission du pair et permission
dédiée demeurent obligatoires ; `wallet.read` ou les claims ne donnent aucun droit.

## Fermeture et retrait concurrents proposés

Une simple délégation valable 60 secondes ne garantit pas un retrait instantané
entre deux bases. Recommandation : barrière propriétaire de consommation Hub,
pilotée par l'autorité Fans, avec uniquement ID/version/état d'admission et bornes,
sans contenu de session ou identité éditoriale.

- Enregistrement explicite des contextes admis ; ouverture Fans seulement après
  acquittement primaire Hub. Aucun contexte connu implicitement.
- Avant suspension/annulation/retrait : fermer les nouveaux choix dans Fans,
  envoyer la barrière de fermeture, puis n'annoncer la fermeture effective qu'après
  lookup/acquittement Hub. En cas de résultat incertain, état privé « fermeture
  en cours », pas de nouvelles attributions côté Fans, reprise avec la même clé.
- Confirmation : verrouiller les barrières sélectionnées en ordre canonique,
  vérifier versions, admission, état et bornes contre l'horloge Hub ; tout choix
  invalide refuse **avant débit**, aucun retrait silencieux de la sélection.
- Confirmation et fermeture concurrentes ont un seul ordre sur le primaire Hub :
  fait confirmé avant fermeture conservé ; confirmation postérieure refusée.
- Réadmission explicite et versionnée, sans restaurer un contexte révoqué. Les
  corrections restent applicables aux faits antérieurs dans tous les états.

Permissions nouvelles proposées : `pf.ranking.context.register` et
`pf.ranking.context.close`, accordées explicitement au seul pair Fans propriétaire,
distinctes des permissions historiques et de `pf.confirm`. Registre de barrières
= contrôle de consommation, pas catalogue commercial ni ledger PF parallèle.
Verrous : ordre existant membre/lot/ledger préservé ; acquisitions additionnelles
en ordre stable ; aucune opération de fermeture ne prend un verrou membre en
ordre inverse. Les tests de deadlock/reprise doivent valider l'ordre exact.

Effet produit à approuver avec ce contrat : une fermeture peut rester « en cours »
pendant une panne réseau, mais aucun nouveau choix/envoi Fans n'est ouvert ; la
date effective attestée distingue les faits déjà confirmés des refus ultérieurs.

## Preuves requises avant intégration

1. Deux membres, même horodatage, confirmations concurrentes : ordre Hub unique
   et stable, même ordre dans reçu/lookup/snapshot, aucune séquence inventée Fans.
2. Rollback avant commit, acquittement perdu et réponse HTTP perdue après commit :
   même intention/clé/ordre, un seul débit ; indisponibilité primaire non assimilée
   à une absence.
3. Rejet des champs altérés, contexte non admis, pair sans permission, origine
   inconnue, versions contradictoires, liste dupliquée/plus de dix et pays absent.
4. Courses fermeture/retrait/expiration/confirmation ; panne entre envois de
   barrière et acquittement ; reprise après restauration sans contexte réactivé.
5. Attribution multi-lots/multi-dimensions, corrections partielles/totales,
   litiges/résolutions, événements désordonnés et snapshot incomplet : net exact,
   mêmes date/ordre originaux, aucune ancienne attestation restauratrice.
6. Claims et protocoles historiques inchangés ; ancien wire et tests rejoués,
   ZIP excluant loaders de recette, barrières physiques d'isolation conservées.

## Accord demandé

Autoriser séparément B3 fermé sur : versions/champs, compteur atomique propriétaire,
contexte canonique et barrières de fermeture, permissions dédiées et effet « en
cours » après résultat incertain. Chaque sous-lot sera une PR bornée, après accord.
Aucune durée de conservation nouvelle, purge réelle, achat, admission de production,
flag ou déploiement. #150 et RustFS #161 demeurent distincts.
