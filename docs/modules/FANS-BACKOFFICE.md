# Administration Fans unifiée

## Accès et architecture

`/app/admin` est une page privée du rôle `fans`, authentifiée par WordPress,
exigeant `manage_options`. Le panel natif **Faluss → Modération Fans** propose
un lien vers cette page ; le lien **Administration WordPress** permet le retour.
La racine utilise les pills centrées et Outfit de Fans, sans sidebar WordPress.
Ni URL secrète, ni compte Identity privilégié, ni nouveau flag.

Chaque section vérifie de nouveau ses capacités et la disponibilité de son
module. Les formulaires de décision sont les mêmes adaptateurs que le panel
WordPress : dispatch REST interne, nonce `fans_moderation`, révision et journal
du domaine. Les opérations supplémentaires utilisent `fans_backoffice`, une
confirmation explicite et une liste fermée de paramètres. Toutes les réponses,
y compris refus et erreurs, sont privées/no-store pour navigateur et CDN.

## Inventaire vérifiable

| Fonction WordPress / service Fans | Fonction du back-office | Capacité et prérequis serveur | Preuve |
|---|---|---|---|
| Liaisons SSO locales | Comptes, recherche e-mail/Faluss ID, fiche, contexte autorisé | `manage_options`, schéma SSO | HTTP recherche/404/pagination existante ; captures comptes |
| Admission Créateur, route `/creators/{id}/status` | Admission, filtres pending/active/suspended, détail et journal | `manage_options`, profils + journal conforme | HTTP suspension/réactivation, page publique 404/200, stale 409 |
| Présentation éditoriale | File par état, approbation/rejet/révocation | `manage_options`, module éditorial | Adaptateur partagé ; recette SQL snapshot approuvé ; capture présentation |
| Publications textuelles | File, fiche privée, décision, journal, référence image | `manage_options`, module publications | HTTP approbation réelle, stale rejet, compte exact approuvé |
| Images privées | Liste paginée, fiche, aperçu protégé, décision, nettoyage | `manage_options`, schéma/stockage images | Adaptateur partagé ; HTTP nettoyage ; SQL permissions images |
| Dossiers de signalement | Signalements, preuve sélectionnée, décisions/recours/finalisation | `manage_options` ET `moderate_faluss_fans_messages`, stockage rétention | Refus sans cap ; SQL/HTTP cycle réel dossiers ; capture modérateur |
| Commandes `fans messages prepare/status/purge` | Messagerie : diagnostic, préparation, purge explicite | Deux capacités ci-dessus, rôle Fans ; préparation possible avant ouverture | HTTP vrais schémas, événement hourly, purge saine, flags fermés |
| Administration utilisateurs WordPress | Équipe, habilitation nominative de modération | Lecture `list_users` ; mutation `promote_users`, `edit_user(target)`, cible administrateur avec `manage_options` | Deux grants concurrents : 200/409 ; audit défaillant : rollback ; révocation navigateur |
| Catalogue technique courant/archives | Catalogue paginé, création idempotente existante, référence exacte | `manage_options`, catalogue disponible | HTTP création/rejeu, compte propriétaire ; aucun achat ouvert |
| État des modules | Modules, navigation conditionnelle | Lecture seulement, capacités effectives | Modules forcés refusés ; onglets opérateur après habilitation |

La recherche transversale résout les références exactes des profils,
présentations, publications, images, dossiers et entrées catalogue. Aucun texte
de conversation ou motif interne n’est indexé. Le handle est explicitement
absent si aucun contrat ne le fournit ; seules les photos approuvées et
diffusables sont affichées. Les statistiques sont les comptages réels par état
des publications/images. Un service absent donne « indisponible », pas zéro.

## Journal opérateur et concurrence

La première mutation opérateur prépare explicitement la table additive
`{prefix}faluss_fans_admin_actions` InnoDB et vérifie colonnes/index. Elle ne
contient que UUID d’événement, acteur/cible WP locaux, action fermée, résultat
et date UTC. Aucun contenu, e-mail, nonce, secret ou note de dossier.

Pour préparation/purge/nettoyage/catalogue, l’intention `started` est persistée
avant l’appel du service existant, puis `completed` ou `failed`. Un échec de
confirmation est affiché en 503 : l’opération peut déjà avoir agi, relire le
diagnostic et conserver la clé idempotente du catalogue. Une intention non
terminée reste visible. L’écran montre les 50 dernières opérations ; la table
conserve le journal administratif, sans le confondre avec les preuves de
messagerie soumises à leur propre rétention. La durée d’archivage du journal
opérateur doit être fixée dans la politique d’exploitation avant activation.

Une habilitation prend un verrou par cible, verrouille ses métadonnées InnoDB,
relit les capacités puis compare l’état attendu. Écriture de la capacité et
journal sont dans la même transaction. Le stockage est relu avant commit ;
tout échec entraîne rollback et invalidation du cache utilisateur. Aucun
changement de rôle ; une cible SSO ordinaire ou super-admin multisite est
refusée. La révocation pose explicitement la capacité à false.

## Exploitation, compatibilité et retour arrière

Les flags restent en configuration. Le cron système fiable, les sauvegardes,
la politique et les modalités humaines de notification/recours ne sont jamais
attestés par le back-office. Voir [procédure messagerie](../operations/FANS-MESSAGING-RUNBOOK.md).
Fermer `FALUSS_PLATFORM_FANS_MESSAGING` conserve les parcours de rétention selon
leurs prérequis ; cela n’ouvre aucune émission ni aucun contrat commercial.

Aucune nouvelle dépendance, aucun callback SSO ou endpoint public modifié.
Rôle Me/Hub inchangé. Le thème est court-circuité uniquement à cette racine
privée ; cette preuve locale n’est pas une recette Elementor ou de production.
Retour arrière : version précédente du plugin, panel WordPress comme recours ;
conserver la table additive et les journaux, ne pas supprimer les données.
Une habilitation déjà accordée reste un droit WordPress et doit être révoquée
explicitement si nécessaire. Aucun downgrade automatique de rôle/capacité.
