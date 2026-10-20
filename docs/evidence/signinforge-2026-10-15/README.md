# Recette SignInForge — 15 octobre 2026

Les expériences sont installées et publiées en production. Les captures montrent
les formulaires anonymes, sans compte, nonce de secours ni données personnelles.

| Site | Ordinateur | Mobile |
|---|---|---|
| Hub | [1440 px](hub-login-1440.png) | [390 px](hub-login-390.png) |
| Me | [1440 px](me-login-1440.png) | [390 px](me-login-390.png) |
| Fans | [1440 px](fans-login-1440.png) | [390 px](fans-login-390.png) |

[Résultats d’authentification](authentication-results.json) : dix vérifications
réussies par site, avec de vrais formulaires HTTP et comptes de recette temporaires.
Le flux a changé le mot de passe et reconnecté le compte. Les comptes, sessions,
identifiants et le filtre de capture de mail ont ensuite été supprimés ; zéro
compte de recette et zéro filtre de mail restant vérifiés sur les trois sites.

[Résultats de présentation](layout-results.json) : 1440, 768, 390 et 320 px,
HTTP 200, français par défaut, un titre H1 visible, absence de débordement,
liens nommés, focus clavier visible et absence de cache public. Contrastes des
boutons : Hub 5,94:1, Me 11,71:1, Fans 8,53:1 ; bordures des champs supérieures
à 3:1. Cela ne constitue pas un audit complet de conformité WCAG.

La compilation du catalogue français a aussi été contrôlée avec le lecteur
standard `gettext.GNUTranslations` : UTF-8, contexte du titre et pluriels.
Les deux scripts PHP ont passé `php -l`, les fichiers JSON leur décodage et les
liens de documentation une vérification locale. Aucun nouveau test métier PHP
nécessaire : le plugin Platform et ses modules n’ont pas été modifiés.

Les e-mails de récupération des seules adresses de test `.invalid` ont été
capturés localement et aucun n’a été envoyé. La délivrabilité SMTP réelle n’est
pas évaluée. Les points d’entrée Identity existants Me `/login/` et Hub
`/continuer/` répondent 200, sans envoi de code ni cycle SSO avec une vraie identité.

[Configuration et retour arrière](../../deploiement/2026-10-15-signinforge.md).
