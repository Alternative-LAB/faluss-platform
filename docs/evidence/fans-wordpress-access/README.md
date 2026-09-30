# Accès WordPress sur Fans — recette du 30 septembre 2026

## Règle et scénarios

- Rôle du site `fans` uniquement ; aucune dépendance aux flags UI/SSO ou au schéma.
- Invité, membre SSO, auteur, éditeur, rôle personnalisé : aucune toolbar publique,
  même avec la préférence activée. Aucun logo WordPress ou identifiant de compte
  généré par la barre n’est présent dans le HTML.
- Administrateur WordPress : barre présente même avec préférence désactivée,
  écrans natifs conservés. Superadministrateur multisite couvert en test unitaire.
- Membre connecté ouvrant un écran admin : 302 `no-store` vers l’accueil public.
  Invité : authentification WordPress native. URL cible fixée côté serveur,
  insensible à `redirect_to` ou aux paramètres de la requête.
- POST/AJAX exclus du renvoi, sans contourner leurs nonces ou permissions.
  REST et callback SSO ne sont pas des écrans admin et restent traités normalement.

La recette a montré que `admin_init` arrivait trop tard pour certains écrans
(`edit.php` refusé avant ce hook). Le contrôle final utilise `init` priorité 0,
avec `is_admin()` et exclusion des points d’entrée techniques.

## Environnement réel mais jetable

- WordPress **7.1.2**, PHP **8.5.4**, MariaDB privée via socket, thème Twenty
  Twenty-Five et **Elementor 4.3.1 actif** ; landing Canvas réellement construite
  avec les widgets Elementor (titre, texte et shortcode).
- Plugin complet activé sur une nouvelle installation locale de rôle Fans.
  Aucun flag métier activé : les adaptateurs SSO/UI de production sont enregistrés
  par un MU-plugin de test, sans modifier la politique d’accès du plugin.
- Comptes temporaires `example.invalid`, vrais cookies WordPress et deux membres
  reliés à des UUID de test dans les tables SSO locales. La liaison est préparée
  par la fixture ; la connexion avec le véritable Identity n’est pas revendiquée.
- Domaine HTTPS virtuel `fans.example.test`, transport HTTP loopback seulement.
  Redirections externes inspectées sans les suivre ; HTTP sortant bloqué dans
  WordPress et dans le navigateur. Aucun site ou secret réel utilisé.

## Résultats

**308 tests / 4 856 assertions**, deux dépréciations préexistantes ; PHPStan niveau 7
sans erreur. Lint des PHP modifiés, syntaxe du runner navigateur, scan ciblé de
secrets et `git diff --check` conformes.

Chrome 154 : **30 cas** de rendu et transport, résultats dans [results.json](results.json).
Six contextes réels : invité, deux membres SSO (préférence on/off), éditeur,
deux administrateurs (préférence on/off). Pour chacun :

- Landing Elementor et route `/faluss-fans/fan/hof`, ordinateur 1440 px / mobile 390 px.
- Toolbar absente des non-administrateurs dans le DOM, aucun identifiant technique
  local du membre dans le HTML, aucune classe `admin-bar` parasite.
- `/wp-admin/`, `profile.php`, `edit.php` : membre/éditeur 302 vers la landing,
  administrateur 200, invité redirigé vers la connexion WordPress.
- `admin-post.php` et `admin-ajax.php` : sonde avec vrai nonce WordPress 200,
  nonce invalide 403. Le vrai handler SSO refuse un nonce invalide par sa redirection
  attendue ; un vrai démarrage invité avec nonce valide atteint la redirection
  d’autorisation Identity, non suivie. Callback invalide refusé par le handler SSO.
- Index REST WordPress 200 JSON ; aucune redirection vers la landing.

## Captures

| Profil | Landing ordinateur | Landing mobile | Fans ordinateur | Fans mobile |
| --- | --- | --- | --- | --- |
| Invité | [1440](elementor-guest-1440.png) | [390](elementor-guest-390.png) | [1440](fans-hof-guest-1440.png) | [390](fans-hof-guest-390.png) |
| Membre SSO, préférence activée | [1440](elementor-member-on-1440.png) | [390](elementor-member-on-390.png) | [1440](fans-hof-member-on-1440.png) | [390](fans-hof-member-on-390.png) |
| Administrateur, préférence désactivée | [1440](elementor-admin-off-1440.png) | [390](elementor-admin-off-390.png) | [1440](fans-hof-admin-off-1440.png) | [390](fans-hof-admin-off-390.png) |

La landing est une fixture Elementor minimale, pas une copie de la landing réelle.
L’avatar externe de l’administrateur peut être absent car Gravatar est bloqué.
Les dépréciations Elementor/WP-CLI avec PHP 8.5 ne produisent pas d’erreur fatale.
La recette ne couvre pas le cache/CDN de production, un multisite réel ni un échange
SSO avec l’autorité distante. L’utilisateur conserve installation et recette cible.

## Reproduction

```sh
python3 tests/Fans/Access/recipe/wordpress.py \
  --source /snapshot-linux-avec-vendor-physique \
  --core /wordpress-propre --cli /wp-cli.phar --elementor /plugin-elementor
# Utiliser l’URL loopback et le fichier session.json privés annoncés :
BASE=http://127.0.0.1:PORT SESSION=/dossier-jetable/session.json \
  OUT=docs/evidence/fans-wordpress-access node tests/Fans/Access/recipe/browser.cjs
# Toucher STOP dans le dossier jetable pour arrêter PHP et MariaDB.
```

Les cookies et identifiants générés restent dans le dossier privé jetable, jamais
dans les résultats ou le paquet. Les tests et preuves sont exclus du ZIP officiel.
Pas de migration ni nouvelle option : retour arrière par réversion en PR/version.
