# Administration des profils Créateur — preuves locales

Base de travail : `origin/main` **b16e1438c8d73378afeae62eebfe54f59c77f7f6**, plugin **0.10.0**.

## Environnement

- Nouveau WordPress **7.1.2**, PHP **8.5.4**, MariaDB **11.8.6**, Twenty Twenty-Five et plugin complet avec dépendances Composer physiques correspondant au lockfile.
- Base privée, socket MariaDB sans réseau, serveur HTTP **loopback uniquement**, 4 workers PHP. Aucune base ou configuration de site préexistante réutilisée.
- Comptes et demandes créés pour la recette uniquement. Mail et HTTP externe bloqués. Les flags nécessaires sont définis seulement dans le WordPress jetable ; aucun flag de production changé.
- Pas d’Elementor dans le panel d’administration natif ; aucun accès à `faluss.me`, `faluss.com`, `fans.faluss.me`, aux serveurs ou à l’updater pour cette validation.
- Chrome **154** par Playwright, JavaScript désactivé, vues **1440×1000** et **390×844**. Les captures de file sont limitées au viewport ; les fiches sont capturées entièrement.

## Résultats

- **309 tests / 4908 assertions** dans un snapshot Linux isolé, `php -l` sur tous les PHP modifiés, PHPStan sans erreur. Deux dépréciations préexistantes restent signalées.
- **82 contrôles HTTP WordPress/MariaDB réels**, détail dans [wordpress-checks.json](wordpress-checks.json).
- Concurrence réelle : deux administrateurs, une décision `200`, l’autre `409`, une seule transition journalisée. Rejeu du formulaire et ancienne révision après retour à un statut antérieur refusés.
- Journal absent ou moteur non transactionnel : refus fermé, sans installation ni mutation depuis REST. Trigger SQL d’échec d’audit : `503`, rollback du statut, révision et journal inchangés.
- Invité, membre lié, autre propriétaire, compte non lié et éditeur ne peuvent lire la file privée ni décider. Nonces WordPress et REST vérifiés ; confirmation, types et champs du formulaire contrôlés côté serveur.
- Pages publiques réellement `404` avant activation et après suspension, `200` lorsqu’actives ; API Explorer correspondante. Le journal, sa révision et les identifiants administrateur ne sont jamais projetés dans les réponses publiques.
- Aucun nom/bio non approuvé diffusé après activation. La suspension ne modifie pas la modération éditoriale ; la réactivation rétablit uniquement une présentation déjà approuvée séparément.
- Mise à niveau additive vérifiée depuis un profil antérieur : le membre ne crée pas le journal, le prochain accès administrateur autorisé le crée, sans changer les profils. Aucun historique rétroactif inventé.
- Pagination réelle : 22 demandes, pages de 20 puis 2, sans doublon. Textes, images et messagerie passent aussi leur recette SQL/HTTP isolée existante.
- **6 vues navigateur** : file, fiche en attente et fiche active sur ordinateur/mobile. Focus visible, ordre clavier sélection → confirmation → bouton, soumission réelle d’une décision dans le navigateur sans JavaScript, absence de débordement horizontal. Résultats dans [browser.json](browser.json).

## Captures

| Vue | Ordinateur | Mobile |
|---|---|---|
| File des profils en attente | [Capture](queue-desktop.png) | [Capture](queue-mobile.png) |
| Demande et décision native | [Capture](pending-desktop.png) | [Capture](pending-mobile.png) |
| Profil actif et journal | [Capture](active-desktop.png) | [Capture](active-mobile.png) |

Ces captures montrent le WordPress jetable et des références de fixture privées.
La barre WordPress est présente car l’utilisateur est administrateur. Les requêtes
Gravatar externes sont bloquées dans le runner. Il ne s’agit pas d’une preuve du
rendu ni du SSO sur le site cible ; sa recette et son activation restent sous le
contrôle de l’utilisateur.

## Reproduire

Voir [ADMISSION.md](../../../tests/Fans/Profiles/recipe/ADMISSION.md). La CI exécute
la même recette WordPress avec PHP 8.3 et les archives WordPress/WP-CLI épinglées.
Les fichiers de cookies, configs, secrets de fixture, bases et logs restent hors Git.
