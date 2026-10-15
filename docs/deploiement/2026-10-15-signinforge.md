# Connexions SignInForge — 15 octobre 2026

Intervention explicitement demandée sur les trois sites en production, suivie par
l’issue [#219](https://github.com/Alternative-LAB/faluss-platform/issues/219).

## Résultat

| Site | Connexion publique | Expérience Elementor | Charte |
|---|---|---|---|
| Hub | https://faluss.com/wp-login.php | 353 | Crème, orange, Outfit, logo existant |
| Me | https://faluss.me/wp-login.php | 1570 | Noir, crème, vert profond, Outfit |
| Fans | https://fans.faluss.me/wp-login.php | 485 | Forêt sombre, menthe, Outfit, logo et photo existants |

WordPress 7.1.3 / PHP 8.3.33 sur les trois sites. Elementor est actif et ses
containers sont disponibles partout ; aucun recours à l’éditeur natif nécessaire.
Les expériences se modifient dans **SignInForge → Expériences → Modifier avec
Elementor**. Ce sont des documents du plugin servis à l’adresse WordPress
habituelle, pas des pages supplémentaires ni des copies de la logique métier.

La version officielle **SignInForge 0.1.1** provient de
[WordPress.org](https://wordpress.org/plugins/signinforge/). SHA-256 de l’archive :
`4033ac7f4c84ec689a556d70d7e93dc073834454aa376c24dcd69be5ac765b77`.
Aucun fichier du plugin téléchargé n’a été modifié.

## Configuration

- L’expérience publiée est affectée à la connexion. Mot de passe oublié,
  réinitialisation et inscription reprennent automatiquement la même expérience,
  avec le widget `signinforge-auth-form` en mode `auto`.
- Les inscriptions WordPress restent désactivées sur les trois sites.
- `after_login` reste vide : destination explicite, rôle et filtres existants
  continuent de décider du retour. La déconnexion revient sur
  `/wp-login.php?loggedout=true` et affiche sa confirmation.
- Les erreurs génériques et la limitation de tentatives sont actives. Turnstile
  reste désactivé, faute de configuration demandée ; aucun service tiers ajouté.
- Le cache HTTP des écrans est privé, avec `no-store`. Apache dispose déjà du
  module `remoteip`, avec proxies internes déclarés ; aucun en-tête IP arbitraire
  n’est utilisé par une nouvelle surcharge applicative.
- Les liens Identity existants `/login/` sur Me et `/continuer/` sur le Hub sont
  conservés. Aucun compte Identity, client SSO ou secret OAuth modifié.
- Sur téléphone/tablette, le formulaire passe en premier et le panneau visuel
  décoratif est masqué. Les champs restent à 16 px ; focus clavier et liens
  nommés sont vérifiés. Les couleurs de boutons/bordures assurent leur contraste
  sans remplacer les couleurs d’identité des panneaux. Le panneau Fans réutilise
  la taille WordPress existante 768 × 1024 (781 Ko), au lieu du PNG original
  de 1,96 Mo ; aucun nouvel asset graphique ni dépendance ajouté.

Le catalogue public français, absent du paquet officiel consulté, est compilé
à partir de [fr-frontend.json](signinforge/fr-frontend.json) puis installé dans
`wp-content/languages/plugins/signinforge-fr_FR.mo`. Il couvre les labels,
notices, erreurs, contextes et pluriels publics. Une future traduction officielle
peut le remplacer ; contrôler ces écrans après une mise à jour.

Sur Fans seulement, le [MU-plugin de langue](signinforge/faluss-signinforge-locale.php)
choisit le français sur `wp-login.php` et pour les e-mails de récupération lorsque
le compte n’a pas de langue spécifique. Un choix explicite `wp_lang`, son cookie
ou la langue propre du compte prévalent. La langue globale du site et de
l’administration n’est pas changée. Le catalogue français du cœur WordPress est
également installé pour cette page.

## Sources et réapplication

Les paramètres visuels sont dans [profiles.json](signinforge/profiles.json).
Après sauvegarde et vérification de la version installée, copier ce fichier et
[scripts/configure-signinforge.php](../../scripts/configure-signinforge.php) dans
un emplacement temporaire de chaque conteneur, puis lancer comme `www-data` :

```sh
php /tmp/configure-signinforge.php --wordpress=/var/www/html --profiles=/tmp/signinforge-profiles.json
```

Le script vérifie hôte, plugin, Elementor et containers, puis valide le document
avant écriture. Il retrouve l’expérience par sa métadonnée de déploiement `219` ;
plusieurs correspondances provoquent un arrêt. L’API de document Elementor
régénère le contenu de repli et le CSS. Une réapplication conserve les IDs.
Une sauvegarde privée du post, de ses métadonnées et des réglages est créée avant
modification ; une exception déclenche leur restauration. Contrôler le résultat
et garder la sauvegarde externe avant toute reprise.

Compiler le catalogue sans dépendance Python supplémentaire :

```sh
python3 scripts/compile-signinforge-translations.py docs/deploiement/signinforge/fr-frontend.json /tmp/signinforge-fr_FR.mo
```

Installer le catalogue et le MU-plugin Fans séparément ; ne pas recopier le
MU-plugin sur les autres sites. Les fichiers servis et générés doivent appartenir
à `www-data`, les sauvegardes et clés de secours rester privées.

## Vérifications et preuves

[Preuves de recette](../evidence/signinforge-2026-10-15/README.md) : captures finales,
résultats structurés d’authentification et de mise en page, sans données de compte.

Sur **chacun des trois sites**, la recette navigateur a vérifié :

- formulaire personnalisé français, réponse 200 et absence de cache public ;
- refus d’identifiants invalides avec message générique ;
- connexion abonné, cookie sécurisé/HttpOnly et retour local explicite ;
- déconnexion, révocation du cookie et retour sur l’écran personnalisé ;
- génération du mail de récupération, formulaire de réinitialisation, changement
  du mot de passe puis connexion avec le nouveau mot de passe ;
- rejet d’un `redirect_to` vers un domaine externe ;
- destination `/wp-admin/` pour un administrateur sans retour explicite ;
- disponibilité du mode de secours natif, sans publier sa clé.

Les comptes temporaires ont été supprimés après la recette, ainsi que leurs
sessions, identifiants et le dispositif ciblé de capture de mail. Leur adresse
`.invalid` n’a reçu aucun envoi externe. Cette recette vérifie la génération et
le traitement du mail, **pas la délivrabilité réelle SMTP**. Aucun parcours SSO
complet avec une identité réelle n’a été rejoué ; les points d’entrée publics
existants sont contrôlés sans demander ni envoyer de code de connexion.

Le rendu est contrôlé à 1440, 768, 390 et 320 px, avec absence de débordement,
labels associés, liens nommés et focus visible. Aucun test sur téléphone physique.

L’accueil du Hub présentait déjà « Il semble que rien n’a été trouvé à cet
emplacement » pendant l’état des lieux, malgré son HTTP 200. Cette anomalie de
contenu préexistante n’est pas corrigée par la personnalisation de connexion.

## Sauvegardes et retour arrière

Dans le conteneur Incus `faluss`, le répertoire privé
`/opt/backups/signinforge-20261015` contient les exports SQL cohérents des trois
bases, leurs `wp-config.php` et les snapshots d’expériences. Droits : répertoire
700, fichiers 600. Les trois archives ont passé `gzip -t` ; leur restauration
complète n’a pas été éprouvée pendant cette intervention.

Pour revenir aux écrans WordPress natifs, désactiver uniquement
`signinforge/signinforge.php` via WordPress ou son API `deactivate_plugins()`.
Sur Fans, retirer aussi le MU-plugin de langue ajouté. Les expériences peuvent
rester en brouillon pour inspection. Ne pas désactiver Elementor ni Platform,
ne pas changer les opt-ins Identity et ne pas restaurer toute une base pour
annuler un changement visuel : cela écraserait les écritures postérieures.

Si seule une expérience doit être restaurée, utiliser son snapshot privé pour
restaurer post, métadonnées et `signinforge_settings`, puis régénérer son CSS.
Le mode de secours documenté dans les réglages SignInForge reste disponible en
cas de difficulté d’accès ; sa clé ne doit jamais figurer dans Git ni les captures.
