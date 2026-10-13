# Changelog

Toutes les modifications notables de ce projet sont documentées dans ce fichier.

Le format s’inspire de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet utilisera une gestion sémantique des versions dès la première version publiée.

## Unreleased

### Documentation

- Consigner la validation F1b R2 : relevé Europe/Paris, classements persistants
  et sessions Créateur distincts, trois portées, plafonds et corrections après
  clôture. Formaliser les places uniques par date d'atteinte corrigée et ordre
  propriétaire Hub ; proposer l'extension additive B3, approuvée séparément
  pour la recette fermée au commit `2553e376`. B1–B6
  autorisés selon leurs dépendances ; aucun code économique ou flag activé.

### Ajouté

- Persister dans Fans jetable les intentions complètes 0.3, leurs clés stables
  avant envoi et les reçus privés signés ; tester la reprise après panne et
  réponse perdue, sans ledger PF, migration automatique ni API active.

- Raccorder le gateway propriétaire 0.3 aux signatures, nonces privés et contrôles
  transactionnels existants dans la seule enclave jetable. Recette HTTP avec
  clés distinctes, rejeux et réponse perdue après consommation ; aucune route
  distribuée ni activation sur les sites.

- Revalider la délégation 0.3 après les verrous propriétaires et avant COMMIT,
  avec rollback des effets préparés si elle expire. Recette réelle fermée
  de concurrence et reprise, sans route, schéma ou activation supplémentaire.

- Ajouter le codec signé 0.3 fermé pour réserver, confirmer, libérer et retrouver
  une attribution à contexte de classement immuable. Réponses liées à la requête
  et reçus d'origine vérifiés, sans admission HTTP ni consommation supplémentaire.

- Ajouter le lecteur B3 fermé raccordant l'inbox durable Fans au véritable
  client HTTP WordPress : étapes bornées, lookup primaire après résultat
  incertain, concurrence sans nouvelle clé et promotion signée exhaustive.
  Aucun classement public, cron, installation automatique ou activation.

- Ajouter l'inbox privée B3c2c3a : clé et demande signée persistées avant HTTP,
  staging des pages, reprise primaire après résultat incertain et remplacement
  atomique après fence du corpus exhaustif. Installation explicite sur Fans
  jetable uniquement, sans ledger, route, migration ou score public.

- Ajouter B3c2c2 fermé : lectures HTTP signées du corpus propriétaire, admission
  par nonce SQL distinct et reprise par lookup primaire après réponse perdue.
  Recette physiquement limitée à deux WordPress jetables à clés distinctes ;
  aucun endpoint de production, écriture économique ou inbox Fans ajouté.

- Lier les lectures fermées du corpus exhaustif à l'origine, la politique,
  l'opération, la clé durable et au contexte signé exact, sans paramètre Fan.
  Vérifier les pages/fences privées et les refus sous les domaines dédiés,
  sans route HTTP ni admission ajoutée par ce sous-lot de validateurs.

- Ajouter B3c2b2 fermé : générations exhaustives immuables du corpus 1.0,
  pages de 100 faits et lookup primaire par clé stable ; vérification finale
  des sources H4 actuelles et refus du corpus obsolète, sans écriture
  économique, transport ouvert ou score public.

- Ajouter B3c2b1 fermé : lecture propriétaire exhaustive de l'origine depuis
  les consommations Hub 0.3 et le net H4 complet, sous verrou global et bail
  distinct de lecture. Aucun paramètre de liste de membres, nouvelle écriture
  économique, matérialisation, transport, score ou activation.

- Formaliser B3c2a fermé : corpus privé exhaustif 1.0 approuvé, validateur de
  faits/totaux/filiation H4 et pagination, permission dédiée et domaines de
  signature séparés. Aucun stockage, transport, score ou activation ajouté.

- Ajouter B3c1 fermé : snapshots privés complets 2.0 par membre, ordre/contexte
  d'origine attestés et net H4 rapproché, pages immuables et fence primaire.
  Anciennes allocations explicitement non classables ; aucune API de site,
  migration automatique, nouvelle consommation ou activation.

- Ajouter B3b2 fermé : contexte 0.3 immuable, ordre Hub global attesté et reçu
  signé dans la transaction du débit officiel et des journaux, reprise par clé
  identique sur le primaire. Compatibilité H2/H3/H4 préservée ; aucune route,
  source d'achat, installation automatique ou activation sur les sites.

- Ajouter B3b1 fermé : barrières propriétaires versionnées d'origine, pays,
  catégorie, session et admission, permissions dédiées, journal atomique et
  lookup primaire après réponse perdue. Sélection exacte sous verrous ; aucune
  consommation nouvelle, installation automatique, API de site ou activation.

- Ajouter B3a fermé : intention/contexte/reçu 0.3 versionnés, précision UTC et
  ordre Hub obligatoires, sessions explicitement choisies, domaines de signature
  et permissions dédiées. Validateurs seulement ; anciens formats intacts,
  aucune consommation, persistance, route, migration ou activation.

- Ajouter B2c : modération distincte des règles de session, historique privé
  versionné, recours des candidats et réadmission après suspension effective.
  Décisions exactes et journaux atomiques, approbation éditoriale verrouillée
  dans la transaction ; fermeture/réadmission en attente de la preuve Hub B3.
  Aucun nouvel endpoint, schéma installé automatiquement ou score public.

- Ajouter B2b : critères territoriaux publics versionnés sans défaut, déclaration
  privée du territoire d'activité principal, examen/recours motivés et contrôle
  des ouvertures/admissions locale et nationale. Aucun pays de soutien Fan
  autorisé implicitement, contexte Hub ou ouverture économique.

- Ajouter B2a : sessions privées créées par les Créateurs, coorganisation
  explicitement acceptée, participation volontaire, programmation figée et
  plafonds vérifiés en concurrence. L'ouverture reste en attente de preuve Hub ;
  aucune route active, attribution ou score public.

- Ajouter B1b : registre privé versionné des dimensions et origine préparée,
  pseudonymes éditoriaux modérés, consentements Fan/Créateur révocables et journal
  atomique. Installation explicite vérifiée InnoDB, recette WordPress/MariaDB ;
  aucune ouverture réelle, endpoint public, migration automatique ou score.

- Ajouter B1a : calendrier Europe/Paris avec bornes UTC/été-hiver, programmation
  locale désambiguïsée et calcul pur des places uniques depuis les nets corrigés.
  Ordre propriétaire requis, anciens reçus/source incomplète refusés ; aucun
  changement Hub, endpoint, migration ou classement public. Registre B1b à suivre.

- Ajouter F1a fermé : projections persistantes de points Fan et Créateur,
  reconstruction atomique depuis les seuls faits H4 complets rapprochés,
  refus des générations périmées et reprises testées en recette jetable ; aucun
  ledger parallèle, score public, classement, session ou activation.
- Proposer F1b séparément, sans validation ni implémentation : périodes, sessions,
  égalités, visibilité, suspensions et corrections après clôture ; actualiser
  l'état fermé H1–H4 dans les contrats sans ratifier la production ou la rétention.

- Ajouter H4c fermé : transfert des snapshots complets signé, délégué et limité
  aux deux instances jetables ; staging privé et reprise durable Fans, sans
  ledger parallèle, projection de classement ou route ordinaire.
- Ajouter H4b fermé : snapshots privés complets matérialisés en pages stables,
  filiation des attributions multi-lots, fence primaire et reprise après COMMIT
  incertain ; aucun score, transfert réseau, durée de conservation ou purge réelle.
- Ajouter H4a fermé : corrections PF cumulatives par primitive propriétaire,
  litiges/résolutions et fragments durables de 100 allocations dans le ledger
  officiel ; recettes fictives uniquement, sans purge ni score public.
- Ajouter H3c fermé : transport Hub/Fans signé et délégué sur loopback de recette,
  intentions/clés stables et inbox privée vérifiée, sans API normale ni ledger Fans.
- Ajouter H3b fermé : reçus privés atomiques avec consommation/débit/journal H2,
  nonces durables et reprise primaire ; garde de recette isolée sans admission site.
- Ajouter H3a fermé : formats canoniques restreints des reçus privés signés,
  contextes délégués et permissions PF dédiées, sans route ni activation.
- Ajouter H2b fermé : confirmation avec débit PF officiel, consommation immuable
  et journal de remise atomiques ; lookup primaire et reprise à clé stable après
  réponse perdue. Preuves fictives en recette uniquement, aucune admission réseau.
- Ajouter H2a fermé : filiation des crédits fictifs au ledger PF officiel,
  réservations FIFO atomiques de 120 s sans renouvellement, libération et lookup
  primaire avec clé stable. Recette Hub isolée uniquement, aucun débit/site/API Fans.
- Ajouter le modèle fermé H1 des preuves d'achat synthétiques, lots de provenance,
  intentions et plans FIFO : validateurs stricts, schéma additif propriétaire et
  persistance atomique testable uniquement dans la recette Hub isolée. Aucun
  producteur réel, crédit/débit PF, réserve, reçu opérationnel ou API Fans.

### Tests

- Tester H3c entre deux WordPress/MariaDB jetables : permissions, signatures,
  audiences/clés/expiration/rejeux, confirmation concurrente et perte HTTP après
  consommation suivie d'un lookup sans second débit ; aucun SSO Me véritable annoncé.
- Tester H3b sur WordPress/MariaDB jetable : signature, concurrence, rollback du
  reçu, crash et COMMIT inconnu, lookup stable et conservation des ledgers existants.
- Tester H2b sur Hub WordPress/MariaDB jetable : confirmations concurrentes,
  confirmation/libération/expiration/révision source, 32 lots, COMMIT incertain et
  crash après commit sans double débit ; conservation complète des anciens claims.
- Vérifier H2a sur WordPress/MariaDB jetable : réserves/admissions concurrentes,
  expiration, quotas, reprises COMMIT, rollback et conservation des claims/lignes historiques.
- Ajouter la recette WordPress/MariaDB Hub jetable des claims PF historiques,
  de leur concurrence réelle, des pertes de réponse autour de COMMIT et des
  compensations intégrales, sans modifier les opérations économiques.
- Vérifier H1 sur WordPress/MariaDB jetable : conflits de contenu, unicités
  concurrentes, filiation, schémas divergents, rollback et COMMIT ambigu du modèle ;
  ledgers PF/ALB et claims historiques conservés.

### Documentation

- Consigner ALB-Origine comme propriétaire habilité Hub et l'accord limité D1/D2/D4
  pour H1 dans #147. D3/D5/D6 restent proposés ; l'ordre de réduction après
  remboursement (#149) et la conservation des preuves (#150) nécessitent chacun
  une décision humaine distincte avant H4 ou ouverture économique.

## [0.12.6] - 2026-10-04

### Corrigé

- Put Fans notification filters and rows first (#145) (`5dbdb87`)

## [0.12.5] - 2026-10-04

### Corrigé

- Compact Fans notifications and refresh private state safely (#143) (`43f696a`)

## [0.12.4] - 2026-10-04

### Corrigé

- Fix mobile Fans messaging and synchronize private exchanges (`44e51bd`)
- Retry private session validation after transient transport failures (`44e51bd`)

## [0.12.3] - 2026-10-04

### Documentation

- Record authorized messaging review and publication (`e26fa89`)

### Modifié

- Rebuild Fans messaging as a two-pane conversation workspace (`e26fa89`)

## [0.12.2] - 2026-10-03

### Corrigé

- Order and paginate eligible Fans creators and qualify public images (`882bb9b`)

### Modifié

- Refine Fans discovery and public creator profiles (`882bb9b`)
- Remove trailing whitespace from discovery recipes (`882bb9b`)

## [0.12.1] - 2026-10-01

### Corrigé

- Fans : cases de navigation fixes, libellés flottants au survol/focus, aide tactile et logo SVG officiel ; retrait des commentaires internes du profil et d’Explorer.
- Publication → Image → retour dans le compositeur courant avec conservation du texte, dépôt/aperçu privés et sélection des images approuvées. Galerie privée distincte, anciens formulaires et liens compatibles, scroll/focus/historique stabilisés.
- Sessions locales des Fans/Créateurs liés : huit heures fixes, cookie persistant à échéance serveur exacte, déconnexion protégée près de la cloche, invalidation serveur et retrait des vues privées des autres onglets. Administrateurs WordPress et Identity Me inchangés.
- Correctif et preuves : #135, `f209b71`.

### Documentation et compatibilité

- Aucun schéma métier, flag, secret, paiement ou site de production modifié. Les sessions déjà ouvertes gardent leur échéance initiale ; les huit heures s’appliquent aux nouvelles connexions.
- Identity ne fournit pas de sélecteur de compte : après déconnexion locale, l’aide décrit une action explicite et manuelle sur Me. Aucune déconnexion centrale silencieuse.
- Un flux SSO propre réussit en HTTPS isolé ; deux flux partageant le cookie navigateur peuvent provoquer un refus selon l’ordre des retours. Cause reproduite localement, pas diagnostic confirmé du site cible.
- 314 tests PHP, 294 assertions SQL/services, recettes réelles WordPress/MariaDB et navigateur, captures ordinateur/mobile. Voir `docs/evidence/fans-ui-navigation-fix/README.md`. Installation, Elementor cible et recette de production restent à l’exploitant.

## [0.12.0] - 2026-10-01

### Ajouté

- Entrée Fans `/app`, résolution du rôle, anciennes routes redirigées et interaction Créer contextuelle depuis chaque écran Créateur ; publication réelle, navigation clavier/mobile et aperçus commerciaux fermés (#129, `4782af2`).
- Publications courantes et archives privées paginées, cartes publiques dépliables avec dates réelles (#130, `787bc3e`).
- Contexte privé des comptes liés dans toutes les files et fiches Fans, recherche et garde contre les approbations sans liaison valide (#131, `8fbfe80`).
- Back-office privé `/app/admin` : navigation centrale, recherche transversale, comptes, décisions partagées, statistiques réelles, staff et opérations journalisées ; administration WordPress conservée (#132, `8913d30`).
- Notifications Fan/Créateur persistées dans les transactions métier : destinataires exacts, motifs communicables, compteur, lu/non lu, pagination, déduplication et liens autorisés ; purges liées aux conversations et dossiers (#133, `7e9c7d7`).

### Corrigé

- Consentement Identity après mise à jour : préparation additive avant autorisation, accord révocable et refus explicite si sa persistance échoue (#127, `f15a105`).
- Maintien de la présentation approuvée pendant modification/refus ; retrait, révocation et suspension immédiatement respectés, sans diffusion de la proposition en attente (#128, `45b036d`).
- Purge opérateur : un résultat incomplet ou en erreur ne peut plus être présenté comme une réussite complète (#133).

### Migration et compatibilité

- Schémas additifs : consentements Identity, instantané éditorial approuvé, notifications privées ; aucune conversion silencieuse des tables incompatibles. Le journal des opérations est préparé avant les premières mutations habilitées. Voir les contrats de modules et `docs/operations/FANS-REPASSE-DELIVERY.md`.
- Aucun flag, secret, rôle existant ou attestation de politique activé. Callback SSO enregistré et routes REST préservés ; les nouvelles routes humaines ne remplacent pas le callback.
- Captures et recettes WordPress/MariaDB isolées, matrice WordPress vers back-office et permissions incluses. Installation, vrai SSO et rendu Elementor cible restent à recetter par l’exploitant.
- Messagerie : cron fiable, stockage privé, habilitations, notifications externes éventuelles, recours et archivage des métadonnées restent des responsabilités opérateur distinctes. Les durées ratifiées des messages/preuves sont inchangées ; les notifications dans Fans ne valent pas e-mail envoyé.
- HoF/classements calculés, abonnement vérifié et parcours commerciaux restent fermés en l’absence des contrats serveurs correspondants. Retour arrière par le paquet précédent en conservant tables et journaux ; les événements anciens ne sont pas reconstitués.

## [0.11.1] - 2026-10-01

### Corrigé

- Préparation de la messagerie Fans avant admission : commandes opérateur WP-CLI protégées pour installer/vérifier les six tables et le cron horaire, inspecter la santé et exécuter une purge bornée avec échec explicite (#125, `66c5dc0`).
- Accès au panel privé du modérateur rétabli par l’enregistrement du sous-menu après son parent Faluss ; administrateur non habilité refusé en HTTP 403 avant les en-têtes.
- Procédure opérateur complète et preuves WordPress/MariaDB/navigateur isolées : ouverture, fermeture des envois, signalements/recours et purges indépendantes.

### Migration et compatibilité

- Aucun flag, rôle, secret ou attestation humaine modifié. La préparation additive explicite exige le rôle Fans et les deux capacités de modération ; les tables non conformes et récurrences incorrectes sont refusées.
- L’exploitant doit décider et tracer les notifications/recours, habiliter nominativement le modérateur, vérifier le planificateur et les sauvegardes, puis effectuer le vrai SSO cible avant ouverture. Voir `docs/operations/FANS-MESSAGING-RUNBOOK.md` ; les tests locaux ne constituent aucune attestation de politique ou de site.

## [0.11.0] - 2026-09-30

### Ajouté

- Administration Fans des demandes de profil Créateur : file privée, activation/suspension et journal atomique avec refus des décisions concurrentes obsolètes (#123, `313d202`). Les validations commerciales, éditoriales, images et d’identité restent indépendantes.

### Migration et compatibilité

- Journal de statut InnoDB séparé, installé uniquement pour un module profils Fans déjà autorisé, à l’activation du plugin ou au prochain accès administrateur. Aucun statut existant n’est modifié ou reconstitué par la mise à niveau.
- La route REST de statut exige la révision lue dans la fiche privée ; les appelants PHP historiques restent compatibles et journalisés. Aucun flag de production ou secret n’est modifié. Voir `docs/modules/FANS-PROFILES.md` pour le contrat et le retour arrière.

## [0.10.0] - 2026-09-30

### Ajouté

- Remember revocable Identity consent for exact client permissions (`f721d4f`)

### Corrigé

- Refine Fans button sizing and navigation layout (#120) (`70af1eb`)

### Sécurité

- Invalidate unexchanged codes when client configuration changes (`f721d4f`)

## [0.9.3] - 2026-09-30

### Corrigé

- Restrict Fans WordPress toolbar and admin screens to administrators (#118) (`df45736`)

## [0.9.2] - 2026-09-30

### Corrigé

- Style the native Fans SSO button without changing its flow (#116) (`561ec04`)

## [0.9.1] - 2026-09-30

### Corrigé

- Fix SSO client secret confirmation before credential writes (#114) (`c1e8f1b`)

## [0.9.0] - 2026-09-30

### Ajouté

- Demande textuelle Fan vers Créateur, acceptation ou refus, conversation bilatérale, blocage, quotas et protection contre les rejeux (#110, `b10a6f0`).
- Signalements privés avec preuve minimale, modération habilitée, recours, décision définitive, réexamen et conservation de litige motivée (#111, `d91f142`).
- Interfaces Fan, Créateur et panel de modération, captures ordinateur/mobile et purges : messages douze mois après le dernier envoi, preuves douze mois après décision définitive, sous réserve d’un litige motivé (#112, `c07614b`).

### Conditions d’ouverture

- Flags et attestation des traitements fermés par défaut ; aucune activation ni recette sur un site WordPress dans cette livraison. Politique, exploitation des purges et recette WordPress/Elementor/SSO à valider avant ouverture.
- L’ouverture directe Max/abonnement Créateur reste indisponible faute de preuve serveur autoritative consommable par Fans. Seul le parcours ordinaire après acceptation est fonctionnel et testé en isolation.

## [0.8.0] - 2026-09-30

### Ajouté

- Connect approved images to the creator publication form (#108) (`efb3e95`)
- Add private creator image library and safe upload retries (#107) (`2a89c34`)
- Add moderated Fans creator editorial identities (#106) (`e78eea8`)

### Documentation

- Reconcile Fans evaluation with the published release (#105) (`da1ca1b`)

## [0.7.0] - 2026-09-29

### Ajouté

- Lecture du nombre public de suivis sur les profils, sans ouverture des actions sociales (#101, `198ea13`).
- Publications approuvées du créateur sur son profil public (#97, `43eec96`).
- Filtre public par créateur et curseurs liés au filtre (#96, `2683da0`).
- Lecture à la demande des images de publications approuvées, sous opt-ins existants (#95, `9062655`).
- Demande native de profil créateur, soumise à approbation administrative (#94, `d25a07b`).
- Création, édition et retrait des textes via les contrats de modération existants (#92, `e5cf474`).
- Accueils et lecture Fans raccordés aux services disponibles (#91, `1dfcea4`).
- Retour vers la route Fans d’origine après connexion Identity Me (#90, `42123d4`).
- Huit accès Créateur et écran Classement Fans indisponible tant que le contrat et le service PF ne sont pas opérationnels (#89, `70933b5`).

### Corrigé

- Continuité du focus clavier pendant la pagination et sa reprise (#103, `48f0033`).
- Routes canoniques Fans acceptant un slash final unique (#100, `40ee560`).
- Conservation du texte sélectionné dans le retour SSO, sans sauvegarde du formulaire (#99, `6390576`).
- Focus clavier conservé pendant la lecture des images (#98, `222ab99`).
- Pages absentes rendues dans le shell du rôle en conservant le statut HTTP 404 (#93, `cbc2cae`).

### Documentation

- Matrice d’évaluation et quarante captures ordinateur/mobile avec limites explicites des fixtures (#102, `6cac194`).

### Disponibilité et compatibilité

- Aucun flag de production activé. La mise à jour livre le code ; installation, recette WordPress/Elementor/SSO réelle et activation restent sous le contrôle du propriétaire.
- HoF, classement PF, progression PC, messagerie et commerce restent indisponibles. Aucune identité publique ou invitée, contribution, session, rang ou progression fictive n’est créé.
- Aucun second passwordless/SMTP, paiement, migration ou changement des modules Me/Hub/Identity/Link dans ces lots. Les droits et refus serveur restent applicables.
- Détail des parcours réellement testés et des prérequis d’activation : `docs/modules/FANS-48H-EVALUATION.md`. La publication de cette version ne clôt pas le chantier de 48 heures.

## [0.6.3] - 2026-09-29

### Corrigé

- Onboarding : déploiement immédiat du panneau au focus d'un champ éditable, champ et actions visibles au-dessus du clavier tout en conservant le bas ancré et le défilement interne.
- Studio : contour de focus retiré au toucher/souris et conservé au clavier ; header opaque sticky pendant le défilement et les changements de viewport.
- Page publique : fond du document lié à la carte dès le premier rendu, conteneurs hôtes neutralisés sur cette seule route, hauteur minimale au grand viewport et marges de sécurité du contenu.

### Ajouté

- Design → Nom : couleur commune facultative du handle et de la bio, avec conservation du rendu précédent tant qu'aucun choix n'est fait.
- Présentation des liens : bordure globale des tuiles illustrées désactivée par défaut ou activée avec couleur ; aucun effet sur le mode liste.

### Compatibilité

- Préférences additives dans le JSON de composition existant, sans migration ni écriture lors d'une lecture. Les contrats d'onboarding et les modules SSO/OTP, Fans, Hub et moteurs restent inchangés. Un retour à 0.6.2 après enregistrement d'une nouvelle préférence non standard exige d'abord une remise aux valeurs par défaut sous 0.6.3 ; voir la recette.
- Vérifications locales et limites Safari/Elementor : `docs/evidence/me-v3-063/README.md`. Aucun accès à un site ni changement de flag.

## [0.6.2] - 2026-09-29

### Corrigé

- Aperçu V3 rempli jusqu'au bas du téléphone, formes et effets de l'avatar respectés, remplacement facultatif visible et préférence existante de masquage accessible dans Design → Avatar.
- Panneau d'onboarding compensant l'origine du viewport Safari sans défilement de la page ; champ actif révélé instantanément à l'intérieur et ancrage bas conservé.
- Noir exact, dégradé suivant le fond choisi sauf transition explicite, composition responsive légèrement remontée et mouvement discret du wallpaper public respectant la réduction des animations.
- Sélection et pill du Studio réactives avant la réponse réseau, navigation conservée et restauration visuelle sur échec.
- Disposition des liens illustrés accessible dans Liens : première tuile vedette, grille de deux colonnes, images et pictogrammes du catalogue, replis et cycle ajout/remplacement/retrait.

### Compatibilité et vérification

- Aucun nouveau type de lien, migration, flag, règle économique ou modification des modules Identity/SSO, Fans, Hub et Token Engine/PF. Les données et images existantes sont conservées ; l'ancien gris reste inchangé pour les membres qui l'ont enregistré.
- Recette locale ciblée Chromium/WebKit et transactions avec doubles WordPress documentées dans `docs/evidence/me-v3-062/README.md`. La sensation réelle sur Safari iPhone et l'intégration WordPress/Elementor restent à vérifier après installation par le propriétaire.

## [0.6.1] - 2026-09-29

### Supprimé

- Retrait de la récompense ALB historique de Faluss Link : shortcode et widget enregistrés mais vides, widget masqué du catalogue Elementor, assets de récompense supprimés et ancien AJAX fermé en HTTP 410 sans appel au Connector.

### Compatibilité

- Les droits des teasers et thèmes, Token Engine, son Connector et les fonctions quotidiennes PF restent conservés. Aucune migration, conversion économique ou modification des données et flags.

## [0.6.0] - 2026-09-28

### Ajouté

- Add Fans V2 UI foundations (#84) (`82b6809`)
- Add an isolated Fans HoF version three simulator (`47d3a4e`)
- Add the Fans administrative moderation panel (`e089997`)
- Add opt-in Fans image display derivatives (`a00010c`)
- Link approved Fans images to private text drafts (`2fd3a7b`)
- Add private Fans image quarantine (`dcfaa62`)
- Guard Fans text intake with quotas and idempotency (`930c43a`)
- Add moderated local Fans text publications (`9d5f2eb`)
- Prepare Fans publication access and teaser policies (`f5af1d1`)
- Add closed Fans catalogue with hidden adult listings (`bd50830`)
- Add local Fans followers with explicit launch limitations (`486bf3b`)
- Add Fans creator publication approval without identity verification (`d2f65c6`)
- Add isolated Fans SSO with atomic account linking (`7281659`)

### Corrigé

- Reject untrusted owners in image storage ancestors (`3aa240e`)
- Allow pending text edits at queue capacity (`e76e873`)
- Paginate Fans publication lists without hidden gaps (`51b6425`)
- Reject self-support in Fans simulation (`f255e5d`)

### Documentation

- Require Hub ratification before protocol implementation (`9a6556b`)
- Record the owner-code review and blocked Hub guarantees (`af9e21f`)
- Define the blocked Fans Hub purchased PF protocol (`98d070e`)
- Record the approved purchased PF to HoF score rule (`1ab3d36`)
- Clarify planned PC sources and adult catalog archival (`bf6cb24`)
- Align Fans PF PC and HoF contracts with version three (`325c93a`)
- Record real Fans pending quota recipe results (`6503ef9`)
- Finalize Fans badge and funded gift policy (`62562f3`)
- Define Fans HoF scoring and funded PF boundaries (`9870832`)
- Define Fans engine ownership and launch boundaries (`848221b`)

### Modifié

- Archive external adult catalog listings privately (`0c3ec40`)
- Separate Fans simulated score and consumed spending units (`aca542f`)
- Simulate corrected support facts without activating scores (`909175a`)

## [0.5.4] - 2026-09-25

### Corrigé

- Repositionnement du champ actif de l’onboarding explicitement instantané : événements de taille regroupés par frame, aucune correction du scroll de la fenêtre en réponse au défilement de Safari. Ancrage du panneau et clavier superposé conservés.
- Navigation principale et contextuelle du Studio sans rechargement de document, avec pill persistante animée ; liens directs, historique, clavier, sélection active et repli natif conservés. Mouvement supprimé selon la préférence système.
- Relecture canonique après sauvegarde sans rechargement complet ; erreurs et conflits conservent les saisies, chargements obsolètes annulés. Une relecture en échec après sauvegarde se retente sans répéter la mutation.

### Compatibilité

- Assets Me Studio 3.2.1. Aucune modification de carte publique, données, contrats de mutation, parcours, Fans ou flags. Aucune intervention WordPress ; essais navigateur locaux, sensation réelle iPhone à vérifier par le propriétaire.


## [0.5.3] - 2026-09-25

### Corrigé

- Make WordPress plugin updates reliable (`50a0e84`)

## [0.5.2] - 2026-09-25

### Corrigé

- Consommation de l’OTP et établissement WP/Registry réunis dans une transaction : une panne interne conserve la preuve valide pour une nouvelle tentative, sans réactiver une identité suspendue.
- Reprise des anciens curseurs Atomiques avec conservation du mode dans la transaction du curseur ; une session expirée présente la connexion.
- Alerte de sortie du Studio fondée sur les valeurs réellement modifiées ; confirmation serveur avant rechargement, champs conservés après refus de sauvegarde.

- Panneau d’onboarding ancré en bas sous le clavier, focus sans expansion automatique, champ actif révélé par le seul défilement interne et hauteur initiale adaptée aux contrôles.
- Composition V3 centrée et groupe d’identité descendu avec un espacement borné commun à l’aperçu, au public, au shortcode et à Elementor ; préférences historiques conservées.

### Modifié

- Studio V3 autonome : navigation Liens / Shop / Design / Profil, rubriques contextuelles, formulaire en pleine page et aperçu complet à la demande. Les treize éditeurs 0.5.1 restent accessibles ; Shop est explicitement indisponible, sans commerce fictif.

### Ajouté

- Diagnostics serveur bornés des étapes passwordless et des prérequis Studio ; aucune donnée personnelle ou secret transmis dans ces diagnostics.
- Régression transactionnelle OTP, recette WordPress/MariaDB/Elementor locale, parcours HTTP OTP, preuves mobiles et conflit HTTP 409 : `docs/evidence/me-v3-052/README.md`.

### Compatibilité

- Aucune migration, aucune intervention sur les sites ou leurs flags. Fond public pleine page 0.5.1, SSO et Fans conservés. La cause exacte de l’incident OTP de production reste non établie ; iPhone physique et configuration Elementor de production restent à valider par le propriétaire.

## [0.5.1] - 2026-09-25

### Corrigé

- Fond de la carte canonique prolongé jusqu’au bas du viewport et du contenu, sans bordure ni arrondi extérieur ; mêmes règles pour l’aperçu et les intégrations Link.
- Éditions perdues dans le Studio V3 rétablies : collections, contenus texte/média, suppression/visibilité/image des liens, ordre complet, bio/publication et réglages avancés.
- Paramètres de transition de couverture appliqués par le renderer commun ; cache des assets invalidé.

### Ajouté

- Rubriques natives Collections, Contenus et ordre, Réglages ; mutations unitaires de contenus dans l’agrégat Link existant, avec contrôle de version et ownership média.
- Une régression de page entière à 390 × 844, une preuve visuelle locale et une matrice de correspondance ancien Studio/V3.

### Compatibilité

- Aucune migration, aucun changement de flag ni intervention WordPress. Les versions et l’ordre des blocs restent canoniques ; une suppression doit être explicite.
- Recette réelle WordPress/Elementor/téléphone non exécutée. Résultats et limites : `docs/evidence/me-v3-viewport/README.md`.

## [0.5.0] - 2026-09-25

### Ajouté

- Studio V3 natif sous le flag V3, indépendant du flag Studio V2 : dix rubriques, aperçu canonique, sauvegardes ciblées et conflits de version.

### Corrigé

- Composition publique Simple/Atomique et aperçu de même densité ; couverture compacte ou pleine, avatar stable et suppression des décalages immersifs incompatibles.
- Priorité des choix temporaires sur le thème, bordure d'avatar identique avant/après sauvegarde, contraste serveur des boutons et invalidation immédiate des réponses d'aperçu obsolètes.
- Panneau mobile, champs à 16 px, clavier sans déplacement de progression, logo du projet, onglets Réseaux masqués et confirmation autonome.
- Graisse du nom incluse dans la version agrégée pour détecter les éditions concurrentes.

### Compatibilité

- Aucune migration ; données, médias, slug et blocs historiques conservés. Aucun changement au lot Fans, aux rôles ou au SSO.
- Livraison GitHub uniquement ; validation iPhone/WordPress/Elementor réel laissée à l'utilisateur. Preuves locales dans `docs/evidence/me-v3-correction/`.

## [0.4.0] - 2026-09-25

### Ajouté

- Parcours d'onboarding Faluss.me V3 natif, optionnel et désactivé par défaut, avec aperçu de la carte partagée, reprise des anciens curseurs et publication transactionnelle.
- Admission du rôle de site `fans` dans l'administration commune, sans module métier actif.
- Contrat de frontière et étapes de validation des futures catégories commerciales Fans.

### Corrigé

- Diagnostics structurés pour les échecs d'envoi d'image et signalement des médias de réseaux absents dans l'administration Link.
- Conservation du brouillon Identité V3 au Retour, au rechargement et après upload d'avatar ; préservation des dérogations de thème Link V4 lors des modifications successives du Studio.
- Empêche le hook d'activation Link d'installer son schéma sur un site non `me`, même si son flag est présent.

### Documentation

- Étend la recette locale V3 au parcours passwordless et aux uploads JPEG/GIF/WebP, avec réponses HTTP horodatées et protocole de staging en attente d'accès.

## [0.3.3] - 2026-09-24

### Ajouté

- Replace legacy inventory with module cards (`0b50070`)

### Documentation

- Refresh PR metadata (`0b50070`)

## [0.3.2] - 2026-09-24

### Ajouté

- Use full width for Faluss admin pages (#48) (`b616c5b`)

### Documentation

- Record WordPress 0.3.1 upgrade validation (#47) (`54be900`)

## [0.3.1] - 2026-09-24

### Corrigé

- Detect incomplete private updater installations (#45) (`71bc265`)

### Documentation

- Record scripted updater restore failure (#43) (`77b041c`)

## [0.3.0] - 2026-09-24

### Ajouté

- Automate semantic release preparation (`4d4fbd3`)

### Corrigé

- Parse gitmoji commits from squash bodies (#41) (`4d5705a`)
- Classify delivery commits as minor releases (`4d4fbd3`)

### Documentation

- Document scripted update activation recovery (#38) (`2317f63`)

## [0.2.0] - 2026-09-24

### Ajouté

- Publication privée de Faluss Platform par tag GitHub vers `updates.faluss.com`, avec archive de production et contrôle strict de la version.
- Client de mise à jour WordPress avec authentification par clé de licence et configuration centralisée dans l'administration Faluss.

### Sécurité

- Séparation complète entre le Bearer de publication GitHub Actions et la licence installée sur les sites WordPress.

### Corrigé

- Procédure de mise à jour scriptée renforcée pour conserver et vérifier l'état actif du plugin après son remplacement.

### Modifié

- Version du Master Plugin portée de `0.1.0` à `0.2.0` afin que l’updater WordPress reconnaisse la livraison de Studio V2 et Link 0.4.0 comme une mise à jour plus récente.

### Documentation

- Interface d’administration Platform modernisée avec une hiérarchie plus claire, des cartes d’état, des badges accessibles, un tableau responsive et des états de focus visibles.
- Migration de production achevée : `faluss-platform` devient l'unique plugin Faluss chargé sur les deux sites et les anciennes sources sont archivées hors de WordPress.
- Installation initiale de `faluss-platform` sur les deux sites, avec sauvegarde, vérification et retour arrière documentés.
- Bascule de Faluss Theme sur `faluss.me`, avec parité CSS et contrôles de production consignés.
- Bascule de Faluss Catalog sur `faluss.me`, avec parité des lectures, droits et retour arrière consignés.
- Bascule de Token Engine Connector sur `faluss.me`, avec authentification réelle, permissions et lectures métier validées.
- Bascule de Faluss Identity Client sur `faluss.com`, avec tables, PKCE, cookie de liaison et rejet du callback invalide vérifiés.
- Bascule de Faluss Apps Registry sur les deux sites, avec manifests fédérés et document membre validés.

### Corrigé

- Bootstrap Identity en lecture seule sur les requêtes publiques afin de conserver les migrations dans leurs chemins administratifs privilégiés.
- Documentation du rollback Link après une constante d'activation absente et renforcement de la vérification préalable à la désactivation historique.
- Projection du montant du gain quotidien dans Portal et suppression de la fausse échéance historique lorsqu'un abonnement n'en fournit aucune.
- Documentation de la bascule Portal validée sur faluss.com et de son retour arrière.
- Ordonnancement des quatre adaptateurs Portal pendant `plugins_loaded`, afin d'éviter le double enregistrement des providers et de la source Apps Registry.
- Double enregistrement de la source `faluss-me` lorsque Identity Client Platform démarre pendant `plugins_loaded`, qui verrouillait les lectures d'Apps Registry.
- Gouvernance des PR exécutée depuis la branche de base pour empêcher une PR de neutraliser ses propres contrôles, avec validation plus stricte des sections et des gitmojis.
- Règle explicite imposant branche, PR et contrôles CI avant `main`, sans approbation obligatoire, y compris pour les contributions produites avec une IA.
- Validation stricte des jetons et réponses Token Engine, cache Bearer court avec renouvellement unique après rejet, et résolution exclusivement serveur du sujet de session.
- Contrat public complet de `Faluss_Catalog_Themes`, y compris l’activation, les méthodes administratives historiques et leur route de retour compatible.
- Couverture des scénarios Catalog de coexistence, Connector indisponible et conservation des options d’activation sans écrasement.
- Parité exacte des variables CSS du module Faluss Theme avec le plugin historique, notamment les noms de rayon et d’action consommés par Faluss Link.
- Libellés de couleur visibles avec le sélecteur WordPress, noms des ombres, identifiants de nonce distincts et aperçu non interactif dans l’écran « Identité visuelle ».

### Ajouté

- Studio Faluss V2 natif et opt-in sur le rôle `me`, avec onboarding Atomique reprenable, preview/rendu public partagés, composition Link V4 additive, variantes visuelles, liens illustrés et registre d’extensions Apps Registry strict.
- Revue du `main` du 22 septembre 2026 : traçabilité des commits directs, résultats de contrôle et validations de migration encore nécessaires.
- Dossier de retrait de Faluss Production Reset, inventaire exact de son runtime destructif et contrat automatisé garantissant que Platform n’expose ni module, ni action, ni route de reset.
- Module Faluss Federation optionnel pour les rôles `me` et `hub`, avec transport Ed25519, politiques locales de pairs, anti-rejeu transactionnel, diagnostics bidirectionnels et administration historiques conservés.
- Contrats JSON FED et contrats automatisés Federation pour la canonicalisation, les signatures, la fraîcheur, les en-têtes, les limites, la concurrence, les politiques, les diagnostics et la parité des sources historiques.
- Module Faluss Analytics optionnel pour le rôle `hub`, avec consumer Events, trois tables privées, reçus d’idempotence, anonymisation, agrégats journaliers, read-model privé et rétention historique conservés.
- Schémas AN-01 et contrats automatisés Analytics pour les six mappings, les rejouements, les rollbacks, les bornes, la suppression de sujet et l’absence de faux zéro ou de visiteur unique inventé.
- Module Faluss Events optionnel pour les rôles `me` et `hub`, avec catalogues, stockage append-only, outbox, inbox, consumers, leases, retries, rétention et workers Cron historiques conservés.
- Contrats JSON EVT et contrats automatisés Events pour les événements locaux et intersites, doublons, rollback, panne réseau, reprise, limites de stockage, purge et parité des sources historiques.
- Module Faluss Identity optionnel pour `.me`, avec registre `faluss_id`, passwordless, profils publics, onboarding, serveur OAuth, consentements, administration et audit historiques conservés.
- Contrat public étroit d’Identity pour Link, projection bornée des profils publiés sans jointure intermodule, cycle d’activation/désactivation et garde contre une seconde autorité active.
- Contrats automatisés Identity pour le schéma additif, PKCE, codes à usage unique, passwordless, sessions, onboarding, navigation, profils publics, coexistence et parité octet des sources et assets historiques.
- Module Token Engine optionnel pour le Hub, avec projets, permissions, règles, droits, octrois, ledger générique et ledger PF append-only historiques conservés.
- Contrat Platform étroit pour le gain quotidien Hub, utilisé par Portal sans lecture directe de balance ou de table.
- Contrats automatisés Token Engine pour la coexistence, les schémas, la parité historique, les gains, l’idempotence et les compensations.
- Module Faluss Subscriptions optionnel pour le Hub, avec stockage InnoDB, catalogue, essais, entitlements, facturation Stripe, webhooks, notifications, administration et retours historiques conservés.
- Contrat public étroit de Subscriptions pour Portal, cycle d’activation/désactivation, garde de coexistence et SDK Stripe `21.3.0` verrouillé sans secret versionné.
- Contrats automatisés Subscriptions pour les écritures transactionnelles, Checkout, essai, retours, idempotence, refus fermés et parité des sources historiques.
- Module Faluss Link et Studio optionnel pour `.me`, avec éditeur, profil public, blocs, collections, médias, découvertes, onboarding, shortcodes et widgets historiques conservés.
- Adaptateurs fermés de Link vers Identity, Catalog et Token Engine Connector, sans copie d’identité ni décision économique locale.
- Contrats automatisés Link/Studio pour la coexistence, le schéma, les mutations transactionnelles, l’autosauvegarde, les assets historiques et la présentation mobile et bureau.
- Module Faluss Portal optionnel pour le Hub, avec shortcode, navigation, assets, manifest, catalogues descriptifs et façades historiques conservés.
- Adaptateurs fermés de Portal vers Identity Client, Apps Registry, Subscriptions, Token Engine et Analytics, sans lecture directe de table ni copie de prix, droit ou montant PF.
- Contrats automatisés Portal pour la session liée, l’unique lecture Apps Registry, les dépendances indisponibles, l’autorité économique, la coexistence et la parité de présentation.
- Module Faluss Apps Registry optionnel pour `.me` et le Hub, avec façades PHP historiques, contrats fermés, lecture membre bornée et cache public fédéré de cinq minutes au plus.
- Contrats automatisés du registre pour les manifests propriétaires, le modèle `apps.registry`, les collisions de sources, l'autorité Hub exacte et l'absence de fuite du `faluss_id`.
- Module Faluss Identity Client optionnel pour `faluss.com`, avec Authorization Code, PKCE S256, état navigateur lié, tables et façades publiques historiques conservées.
- Contrats automatisés de l'Identity Client pour le schéma InnoDB, les retours locaux exacts, la consommation transactionnelle de l'état, les claims, la création limitée à `subscriber` et la coexistence.
- Module Token Engine Connector optionnel pour `.me`, sans ledger local, avec façade PHP compatible, administration privée et garde de coexistence avec l’ancien plugin.
- Contrats automatisés du Connector pour la protection du secret, les permissions, le cache et renouvellement Bearer, les erreurs fermées, le sujet Identity et les actions administratives.
- Module de catalogue optionnel pour `.me`, avec façade PHP compatible Faluss Link et garde empêchant la coexistence avec l’ancien plugin.
- Test de parité de lecture du catalogue issu d’une comparaison avec le plugin historique sur des données synthétiques.
- Écran de gestion du catalogue préparé dans l’administration Faluss, non activé avant la bascule du module.
- Vérification CI de la syntaxe JavaScript de l’écran du catalogue.
- Actions d’administration du catalogue avec contrôles de droits, nonces et compatibilité des événements, non activées par défaut.
- Adaptateur de lecture des droits de thème fournis par Token Engine Connector, avec filtrage des définitions invalides.
- Éditeur du catalogue préparant en mémoire les créations, modifications et suppressions avec validation des droits associés.
- Lecteur de thèmes de catalogue compatible avec le schéma et les règles de lecture historiques, non activé en production.
- Inventaire en lecture seule des plugins Faluss historiques actifs dans le tableau de bord de chaque site.
- Validation des PR empilées par les workflows de gouvernance et de qualité PHP.
- Tests automatisés des hooks, de l’option et du handle CSS conservés par le module de jetons visuels.
- Module optionnel des jetons visuels de `.me`, réutilisant l’option et les variables CSS du plugin historique sans activation automatique.
- Tableau de bord WordPress Faluss commun aux deux rôles, limité aux administrateurs et sans interaction avec les plugins historiques.
- Point d’entrée WordPress inactif sans rôle explicite, rôles de site et registre de modules avec validation des dépendances.
- Tests unitaires du socle et contrôles CI de syntaxe PHP, analyse statique et tests.
- Documentation de l’architecture initiale et du mécanisme de cohabitation.
- Règles communes de contribution pour les agents Codex.
- Template français unique pour les pull requests.
- Validation automatique du nom des branches et des sections obligatoires des pull requests.
- Documentation du workflow de contribution.

## 0.0.0 - 2026-09-21

### Ajouté

- Initialisation du dépôt.
