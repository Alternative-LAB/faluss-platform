# Repasse Fans — état livré et recette restante

La base inventoriée était `ce74a283340c9f0f63b6d827152db7976d8eae68` (0.11.1).
Les lots de code suivent l’inventaire initial ; aucune donnée ou configuration
de site réel n’a été consultée ou modifiée. Les preuves ci-dessous sont locales
et isolées ; l’installation et la recette de `fans.faluss.me` restent au propriétaire.

## Lots et preuves

| Lot | PR | Résultat / preuve |
|---|---|---|
| Consentement Identity après mise à jour | #127 | [Cause reproduite, schéma absent puis préparation additive, corruption refusée](../evidence/identity-consent-upgrade/README.md) |
| Version éditoriale approuvée | #128 | [Maintien pendant modification/refus, retrait et révocation immédiats](../evidence/fans-approved-editorial/README.md) |
| Application et interaction Créer | #129 | [Routes, SSO, bannières, huit accès, compositeur clavier/mobile](../evidence/fans-app-create/README.md) |
| Publications et archives | #130 | [Cartes, liste courante, archives privées paginées](../evidence/fans-publication-archives/README.md) |
| Comptes liés dans l’administration | #131 | [Contexte privé, liaison manquante, garde d’approbation](../evidence/fans-admin-accounts/README.md) |
| Administration unifiée | #132 | [Matrice WP → back-office → capacité → preuve](../modules/FANS-BACKOFFICE.md), [26 captures](../evidence/fans-backoffice/README.md) |
| Notifications persistées | #133 | [Atomicité, destinataires, motifs, compteurs, purges et six captures](../evidence/fans-notifications/README.md) |

## Matrice écran / parcours

**Fonctionnel et testé** signifie sur WordPress/MariaDB réels jetables et/ou sur
la recette de service indiquée. Cela ne constitue ni une recette de production,
ni une preuve SSO entre les domaines réels.

| Écran / parcours | État | Ce qui fonctionne / ce qui manque |
|---|---|---|
| Landing : shortcode SSO compact | Fonctionnel et testé | Vrai POST/nonce, retour `/app`, texte accessible ; rendu Elementor cible à recetter |
| Consentement Identity révocable | Fonctionnel et testé | Préparation additive avant autorisation, accord mémorisé, portées/configuration/révocation conservées ; cause cible non inspectée |
| `/app`, anciennes routes humaines | Fonctionnel et testé | Résolution invité/Fan/Créateur, redirections, callback et REST préservés |
| Accueil Fan / Créateur | Fonctionnel et testé pour les données locales | Bannière et liens réels ; aucune statistique HoF ou contribution inventée |
| Explorer invité / lié | Fonctionnel et testé | Présentations approuvées, publications autorisées, pagination ; UUID technique sans faux nom public |
| Profil public | Fonctionnel et testé | Dernière présentation approuvée, publications ; 404 de la page pour profil absent/suspendu |
| Mon profil | Fonctionnel et testé | Nom/bio/portrait propriétaire, révisions, attente, refus, retrait ; statut d’admission distinct |
| Créer depuis tous les écrans Créateur | Fonctionnel et testé | Sidebar non assombrie, quatre choix, compositeur central, clavier/Échap/retour/mobile, repli sans JS |
| Publication | Fonctionnel et testé | Texte/émojis, idempotence/quotas, modération, image par association existante ; aucune fonction média prétendue absente du serveur |
| Galerie images privées | Fonctionnel et testé en isolation | Dépôt normalisé, aperçu propriétaire, modération, retrait/nettoyage ; stockage privé/diffusion cible à attester séparément |
| Publications courantes / archives | Fonctionnel et testé | Refus/retirées en archive propriétaire paginée ; pas de faux lien public ni bloc vide |
| Sidebar / navigation mobile | Fonctionnel et testé | Huit accès Créateur, label survol/focus/actif, routes distinctes, conteneur constant |
| HoF / session / classements Créateur | Interface prête mais service absent | États produit honnêtes, aucune session, rang, PF ou score factice |
| Progression Créateur / Classement Fans | Interface prête mais service absent | Contrat Hub d’attributions effectives, annulations/rejeux et règles de classement toujours nécessaires |
| Espace Fan / PC | Décision ou service requis | Progression PC non calculée, aucune équivalence monétaire |
| Prestation / Service / Produit / Ma boutique | Interface prête mais service absent | Champs contextuels, produit avec prix/photos préparés ; soumissions commerciales, achats et réservations fermés |
| Demande et conversation textuelle | Fonctionnel et testé | Fan lié → demande → acceptation/refus, bilateralité, quotas, blocage, signalement ; ouverture cible soumise à politique et rétention |
| Admission directe abonné Max/Créateur | Service requis | Contrat serveur de preuve d’abonnement absent ; aucune auto-ouverture fondée sur le navigateur |
| Signalements / recours / modération | Fonctionnel et testé | Preuve minimale, double capacité, conflits, décision/finalisation humaine, conservation séparée |
| Notifications Fan / Créateur | Fonctionnel et testé | Événements commités, destinataire exact, compteur/lu/non lu/pagination, liens contrôlés, purge liée aux conversations/dossiers |
| Racine privée et capacités WordPress Fans | Fonctionnel et testé | Inventaire détaillé et permissions dans la matrice back-office ; WordPress natif reste accessible |
| Toolbar / `/wp-admin/` | Fonctionnel et testé dans les lots antérieurs | Administrateurs uniquement sur rôle Fans, membres redirigés, transports SSO/AJAX/API préservés |
| Vrai SSO et rendu Elementor cible | Accès / recette propriétaire requis | Aucun accès au site dans cette mission ; installation et activation non réalisées |

## Avant une activation cible

1. Recette du ZIP officiel sur la configuration WordPress/thème/Elementor réelle,
   aux mêmes tailles ordinateur/mobile, avec invité, deux membres SSO distincts,
   Créateur actif/suspendu, administrateur et modérateur nominatif.
2. Habilitations, stockage privé, cron système et sauvegardes effectivement
   vérifiés ; préparation et diagnostic dans le back-office ou par le
   [runbook opérateur](FANS-MESSAGING-RUNBOOK.md). Aucun indicateur local ne prouve
   la disponibilité de ces composants sur Fans.
3. Politique de messagerie, canal de notification externe éventuel et recours
   décidés par l’exploitant ; une notification lue dans Fans ne vaut pas une
   attestation de remise ou de traitement du recours. Durée d’archivage des
   métadonnées éditoriales et journaux opérateur à préciser.
4. Hub : preuve d’attribution PF achetés, destinataire, identifiant unique,
   annulations/remboursements/corrections et reprise exactes ; règles de période,
   égalités, pseudonymes/invités et abus à ratifier avant classement public.
   PC et contrats commerciaux restent distincts, sans ouverture implicite.

Le socle décrit est livré pour évaluation. Fans ne peut pas être présenté comme
un produit intégralement activé : classement, commerce et abonnements n’ont pas
leurs contrats serveurs, et les contrôles opérationnels/cible sont humains.
