# B6 — gestion des sessions : travail en cours, non livrable

Ce raccordement est sauvegardé pour une pause demandée le 15 octobre 2026.
**Il n'est ni validé sur les nouvelles routes HTTP, ni prêt à fusionner ou à
publier.** Les services [B2](FANS-HOF-B2.md) et le
[cycle de vie attesté](FANS-HOF-B3-SESSION-LIFECYCLE.md) restent les autorités.

## Périmètre déjà écrit

- Liste privée paginée des sessions associées au Créateur lié courant, vingt
  éléments et curseur UUID stable ; ce curseur ne promet pas un ordre temporel.
  Une invitation permet sa découverte sans acceptation implicite.
- Entrées `creator/hof?manage=sessions`, `manage=new` et
  `manage=session&session=…` dans la route existante, sans changement de sidebar.
  Le retour SSO n'accepte que ces sélections canoniques et le curseur de liste.
- Formulaires natifs de brouillon, modification, soumission des règles et recours,
  réutilisant les nonces, permissions, révisions et journaux B2. Les champs de
  propriété ne sont pas choisis par le navigateur. Les textes sont conservés
  lors d'erreur ; conflit ou résultat incertain impose une relecture explicite,
  sans remplacer silencieusement la révision soumise.
- Dates locales interprétées par le calendrier existant : fuseau explicite,
  refus d'une heure inexistante, choix du décalage en cas d'ambiguïté et
  conservation des fractions UTC pour une saisie inchangée.
- Panel Fans d'examen des règles : accès administratif existant, nonce lié à la
  session, décision motivée sur une révision et une empreinte exactes, recours
  et journal. Présentation des dates dans le fuseau WordPress explicitement
  indiqué, sans modification des instants UTC.

La fabrique `ClosedSessionManagement` vérifie l'enclave physique de recette et
les schémas déjà installés explicitement avant toute lecture. Elle n'installe
rien. Le raccordement est fermé sur un site ordinaire ; une copie des constantes
ne suffit pas à l'ouvrir. Aucun nouveau contrat Hub, admission, flag, schéma,
cron, achat, attribution, score public ou migration automatique n'est ajouté.

## Vérifications acquises et limites

La liste propriétaire a passé la recette WordPress/MariaDB B2 existante :
49 contrôles, notamment exclusions, suspension, invitations et plus de vingt
sessions. Les scénarios calendaires couvrent été/hiver, ambiguïtés, lacunes et
précision UTC. Les tests de retour SSO et de fermeture hors enclave sont ajoutés.

Le dernier arbre de code indexé `f8b2ccb85ae7b1be9807bcbde60465d2d778fb9d`
passe 802 tests / 8 004 assertions, dont 84 tests HoF / 291 assertions ;
PHPStan ne signale aucune erreur. Deux dépréciations historiques subsistent.
Cette qualité locale utilise PHP 8.5.4, avec la cible statique PHP 8.3.35.
**Elle ne démontre pas le fonctionnement des nouveaux formulaires en HTTP,
leurs décisions concurrentes ni leur rendu navigateur.** Aucune capture de ce
nouveau parcours n'est encore une preuve acquise.

La CI intégrée de `main` au SHA `57a0639c3e2dbab19530f76486f1319a6ea9a748`
valide le code antérieur à cette branche : 791 contrôles physiques conjoints,
dont 64 B6, zéro échec et nettoyage confirmé. Elle ne valide pas ce travail.
Sa première tentative avait reçu `403 pf_corpus_busy` ; une seule relance
inchangée a réussi, sans établir la cause de la première erreur.

## Travail restant avant revue

1. Recette HTTP native sur les instances Hub/Fans jetables : propriétaire,
   invité, Fan, autre Créateur, administrateur ; nonces, cible falsifiée,
   conflits de révision, rejet, recours, révocation et absence de diffusion.
2. Captures et examen ordinateur/mobile des formulaires, états et erreurs,
   navigation clavier, défilement et comparaison aux composants V2 existants.
3. Raccorder invitations, participations, territoires et leurs recours aux
   services déjà approuvés, puis ouverture/fermeture et reprise au cycle B3.
   **La révocation locale B2 ne constitue pas encore un transport de fermeture
   Hub depuis ce panel.** Aucun succès réseau ne doit être supposé.
4. Terminer les intentions et les raccordements B6 restants ; revue et CI du
   head exact, puis vérification conjointe sur main avant publication officielle.

La création de brouillon n'est pas annoncée idempotente : après un résultat
incertain, relire la liste avant une nouvelle création. Les garanties de
consommation PF restent celles de Hub, sans second ledger dans Fans.
Les données réelles, le véritable SSO Me, la source d'achat, la conservation
[#150](https://github.com/Alternative-LAB/faluss-platform/issues/150) et les images
RustFS [#161](https://github.com/Alternative-LAB/faluss-platform/issues/161) restent
distincts. Aucun site ni environnement de production n'a été utilisé.

Retour arrière avant intégration : conserver la branche, retirer ce raccordement
sans migration ni purge. Aucun état de production n'a été créé.
