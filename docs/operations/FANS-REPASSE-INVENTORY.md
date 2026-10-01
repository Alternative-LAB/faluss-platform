# Repasse Fans — inventaire de départ 0.11.1

Base : `ce74a283340c9f0f63b6d827152db7976d8eae68` ; aucun accès cible.
Ce document suit les lots de code ; une ligne inventoriée n'est pas une preuve de livraison.

## Surfaces et contrats à raccorder

| Fonction WordPress Fans actuelle | Service / permission serveur | Cible back-office | Preuve à produire |
| --- | --- | --- | --- |
| Admission et statut Créateur | CreatorStatusReview ; manage_options, CAS révision, journal | File filtrée, fiche liée, activer/suspendre | liaison absente, conflit, visibilité |
| Nom, bio, portrait éditoriaux | EditorialService ; propriétaire / manage_options | File, fiche et décisions existantes | dernière approbation, retrait, suspension |
| Publications et image associée | TextPublicationService / PublicationImageReference ; propriétaire / manage_options | File, lecture et journal | refus, révision, image périmée |
| Images privées, aperçu, nettoyage | ImageService / ImageRest ; propriétaire / manage_options | File, aperçu protégé, décision, nettoyage autorisé | permissions, révocation, stockage absent |
| Signalements, recours, mesures et conservation | ReportModeration ; manage_options + moderate_faluss_fans_messages | File, preuves minimales, actions existantes | isolation, conflit, motif privé |
| Préparation et santé messagerie | MessageOperations ; mêmes deux capacités | Préparer, diagnostiquer, purger | schémas, cron, échec explicite |
| Catalogue administratif existant | StoreCatalogService / StoreCatalogRest ; manage_options | Catalogue, catégories, états | achats toujours refusés |
| Comptes WordPress locaux liés | FansSsoService + utilisateurs WP ; capacités locales appropriées | Recherche, fiche, états et statistiques réelles | accès refusé, liaison manquante, aucun droit Me |
| Staff et habilitations locales | Capacités WordPress existantes | Liste et opérations explicitement autorisées, journal | CSRF, escalade refusée, traçabilité |
| Modules et configuration | Disponibilité effective des modules ; manage_options | Diagnostic en lecture, fonctions conditionnelles | aucun changement de flag |

Le panel natif `Faluss → Modération Fans` et les API/commandes opérateur restent
les recours. Le nouveau back-office doit adapter ces contrats, sans moteur de
modération concurrent. Aucune interface n'atteste une identité civile.

## Parcours produit

Les routes humaines actuelles `/faluss-fans/fan/...`, `/faluss-fans/creator/...`
et `/faluss-fans/creators/{id}` doivent être reprises sous `/app` ; le callback
`/faluss-fans/sso/callback` et les REST `/faluss-fans/v1` restent stables.
Accueil, Explorer et HoF conservent une bannière ; profils et gestion auront un
en-tête compact. Le rail Créateur garde huit accès. Créer doit fonctionner sur
chaque écran Créateur avec repli navigable sans JavaScript.

Lots dépendants : persistance Identity ; données éditoriales approuvées et
archives ; routes et composition ; administration unifiée ; notifications privées.
La publication attend la validation de l'ensemble. Les décisions Hub, PC et
commerciales déjà recensées ne sont pas réinventées par cette repasse.

## Scénarios transversaux

- Invité, membre lié, Créateur actif/suspendu, administrateur, modérateur habilité
  ou non : distinguer navigation, lecture et décision serveur.
- Nonce absent/étranger, objet d'un autre compte, révision concurrente, stockage
  absent, module fermé : refus explicite et absence de mutation partielle.
- Notifications : événement effectivement commité, bon destinataire, reprise
  sans doublon, compteurs et pagination ; aucun motif interne dans le produit.
- Recette jetable WordPress/MariaDB, navigateur ordinateur/mobile, sans réseau
  de production. Ces preuves ne remplacent pas la recette cible du propriétaire.
