# B6 — déclaration, examen et reprise territoriale natifs

## Parcours et périmètre

Depuis « Mes sessions », le Créateur accède à « Mon territoire d’activité ».
Il consulte les critères publics immuables déjà enregistrés, choisit le territoire
principal proposé par cette politique et soumet une déclaration. Le pays est
déduit de la référence serveur : aucun pays forgé ou champ de propriétaire ne
fait autorité. Les politiques sont paginées par vingt, sans critère par défaut.
Un registre vide ne fabrique pas de pays admis ou d'approbation.

Les critères sont enregistrés explicitement par l'administration propriétaire via
le service B2 `TerritoryService::registerPolicy` ; cette interface ne publie pas
une politique applicable aux sites et ne remplace pas les critères humains à
définir. Le dépôt d'une nouvelle déclaration ferme l'admission courante jusqu'à
son examen. Le Créateur conserve le retrait et le recours motivé après refus.
Les états et motifs utiles sont affichés sans présenter l'examen comme une preuve
de domicile ou un accord commercial.

Le panel Fans existant comporte une section « Territoires » : liste paginée,
déclaration, critères, recours et journal des décisions. L'administrateur décide
avec permission `manage_options`, nonce lié au Créateur et révision exacte.
Une révocation exige une confirmation explicite. Les visites ne décident rien ;
deux soumissions concurrentes ne peuvent approuver ou modifier la même révision
deux fois. Le fuseau WordPress est nommé et appliqué à l'affichage des dates ;
les instants enregistrés et calculs restent en UTC.

## Reprise durable

Une perte d'admission crée le checkpoint existant, dans la transaction de décision.
Le changement local et la fermeture des nouvelles contributions concernées sont
commis avant toute tentative réseau. Un résultat incertain ne réécrit pas cette
décision comme un échec ou une approbation.

Le propriétaire et l'administrateur peuvent consulter les fermetures et poursuivre
leur confirmation. Un POST avance au plus une page de collecte de vingt versions,
ou une seule cible de remise. Le journal détermine le Créateur, la politique, la
révision et la cible ; le navigateur ne choisit ni endpoint, ni clé, ni motif Hub.
Les ponts existants gardent l'action et la clé après une panne et vérifient le
primaire. Un GET ne collecte pas, ne prépare pas et n'envoie aucun message réseau.

La progression distingue collecte, confirmations en cours et confirmation complète.
Les compteurs portent sur les cibles du checkpoint, pas sur des scores. Les anciens
acquittements ne rouvrent aucune version et ne changent pas une future admission.
Une réadmission territoriale revue n'ouvre pas elle-même une session suspendue :
la réadmission de session et celle des participations restent explicites.

Le nouveau raccordement local typé `faluss_fans_hof_closed_territory_retirement`
réutilise le contrat Hub1.0/1.1 existant pour suspendre la session de l'organisateur.
Le garde physique de recette et la connexion primaire sont contrôlés **avant** ce
filtre ; un flag, marqueur ou objet copié sur un site réel ne suffit pas. Les seules
admissions territoriales utilisent le transport1.2 déjà approuvé. Aucun nouveau
contrat Hub, schéma, installation automatique ou activation n'est ajouté.

## Vérifications prévues

- WordPress/MariaDB et HTTP réels jetables : absence de migration par GET, invités
  et comptes sans droit refusés,21 politiques paginées, nonces et propriétaire.
- Déclarations/examens concurrents, refus, recours, approbation et audit exact.
- Création/modération/ouverture native locale depuis la déclaration approuvée,
  révocation puis reprise après réponse perdue après COMMIT et lookup de la même clé.
- Captures ordinateur/mobile du propriétaire, du panel et de la progression,
  clavier, absence de débordement ; aucun nouveau dessin de l'accueil/catégorie
  HoF ni de l'intérieur d'une session, sidebar et composants existants conservés.

Les résultats seront consignés après exécution, pas déduits des tests de services
antérieurs. Le registre unique reste [#147](https://github.com/Alternative-LAB/faluss-platform/issues/147).
Retour arrière par PR de code, sans suppression d'un checkpoint incomplet ; aucune
migration à inverser. Pas de preuve du vrai SSO Me, d'Elementor cible ou de matériel
mobile physique. #150, #161 et l'admission réelle restent distinctes.
