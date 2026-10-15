# B6 — pseudonyme Fan, visibilité et modération

## Statut et périmètre

Raccordement depuis main `ab874a3`, après intégration des lectures et vues des
trois objets. Les tests unitaires et l'analyse statique passent. Les nouveaux
scénarios HTTP ont passé sur la paire jetable ; une première recette navigateur
a révélé un débordement d'un pseudonyme approuvé de 80 caractères, corrigé dans
les styles locaux. La recette complète corrigée reste en cours : ce constat
partiel ne constitue pas encore sa validation globale.

L'espace Fan propose un pseudonyme de classement et un consentement distinct,
révocable. La progression Créateur propose son propre consentement. Les deux
familles restent séparées. Le compte et la filiation restent sous l'autorité
Identity ; ces champs sont éditoriaux Fans. Aucun alias invité n'est fabriqué.

Les adapters utilisent `RankingVisibility` et son journal existants. Les noms
en attente ne deviennent jamais publics par un consentement. Une modification
conserve uniquement le dernier pseudonyme approuvé jusqu'à décision ; retrait
volontaire ou révocation enlève la diffusion et l'accord Fan, sans effacement
des faits ou du relevé privé. L'accord Créateur ne valide pas une présentation.

## Frontières

`ClosedRankingPreferences` impose l'enclave physique et le schéma local déjà
installé. Aucun flag ou constante copiée ne permet d'ouvrir ces formulaires sur
un site ordinaire. Aucun schéma créé implicitement, contrat Hub, route REST,
source d'achat, opération économique ou ledger ajouté.

Ces préférences sont indépendantes de la disponibilité réseau du corpus : une
incertitude Hub ne doit pas empêcher un membre de retirer son consentement.
La lecture de score conserve ses refus ; une écriture de préférence ne la rend
pas disponible et ne rafraîchit aucune preuve par supposition.

Chaque formulaire est un POST WordPress avec nonce, permission propriétaire et
révision attendue. Aucune identité de destinataire issue du navigateur. La
famille vient de la vue serveur. Une révision concurrente produit 409, jamais
une décision sur une nouvelle révision choisie silencieusement. Les GET ne
créent ni préférence ni décision. Les formulaires fonctionnent sans JavaScript.

L'admission Créateur conserve son nonce et ses contrôles. Une soumission de
préférence ne déclenche pas son handler ; un POST mélangeant les deux actions
est refusé avant toute mutation. Les autres écrans et la navigation sont inchangés.

## Administration existante

Le panel Fans présente les propositions en attente, par pages de vingt, et un
détail lié à la fiche du compte. Permission `manage_options` existante revalidée,
nonce lié au compte examiné, révision, motif contrôlé et journal atomique B1.
Approbation, rejet et révocation gardent les sémantiques de ce service.
`inspectForReview` est une lecture administrative additive ; elle ne crée pas
de préférence et n'accorde aucun consentement. Le contrat de liste existant
reste inchangé.

La présentation de ces nouvelles dates utilise le fuseau WordPress explicitement
indiqué ; les enregistrements techniques restent UTC. Cette page ne prétend pas
terminer l'harmonisation de toutes les anciennes dates du back-office.
Le diagnostic périmé « contrat Hub non ratifié » est remplacé par l'état réel :
contrats fermés ratifiés/testés, admission et activation réelles distinctes.

## Scénarios écrits avant recette

- GET propriétaire sans écriture ; absence de nonce/nonce altéré refusée.
- Alias invalide, pending, rejet, approbation puis révocation ; jamais de texte
  en attente dans la livraison B6. Deux requêtes concurrentes : une décision,
  une révision gagnante ; journal propriétaire conservé.
- Deux comptes liés distincts : faux membre/famille ne modifie jamais l'autre.
- Retrait sans confirmation refusé ; consentement révocable, relevé privé intact.
- Invité/Fan sans habilitation refusés au panel ; admin non lié ne devient pas
  un propriétaire SSO par ce parcours.
- Incertitude de relecture primaire : score indisponible, retrait local possible.
- Fuseau Europe/Paris en présentation, valeur UTC inchangée en base.
- Clavier/mobile, labels, focus et absence de débordement sur les vrais formulaires.

Recette par les lectures/formulaires réels de la paire WordPress/MariaDB existante,
dans `b6_privacy_http_checks.py`. Les scénarios applicatifs sont ajoutés à la
recette B6 existante, sans modifier CI, préparation, caches, délais ou runners.
Les comptes/achats/clés sont fictifs ; aucune preuve du véritable SSO Me.

## Suite et retour arrière

Sessions/gouvernance, territoires et intentions complètes restent à raccorder.
Fraîcheur continue, producteur d'achat/admission réels, #150 et RustFS #161 sont
distincts. Aucun score ouvert sur les sites par cette livraison fermée.
Retour arrière : revert des adapters et de leurs appelants, sans suppression
des décisions, préférences, faits, reçus ou checkpoints existants.
