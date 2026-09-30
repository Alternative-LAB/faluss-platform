# Secret SSO administrateur — preuves locales

30 septembre 2026. Vrai WordPress 7.1.2, thème Twenty Twenty-Five, plugin complet
Faluss Platform 0.9.0 + correctif, rôle Me/Identity dans une fixture jetable.
PHP 8.5.4, MariaDB 11.8.6/InnoDB, wp-cli 2.12.0. HTTP 127.0.0.1, base/socket propres,
réseau sortant WordPress bloqué. Aucun site réel ni données de production.

## Résultats

| Cas | Avant : main `7aaecf3` | Après correctif |
| --- | --- | --- |
| Créer un client confidentiel Fans | HTTP 500, client/hash écrits, secret non affiché | HTTP 200, secret dans la seule réponse de confirmation |
| Rotation | HTTP 500, hash remplacé, secret non affiché | HTTP 200, nouveau secret affiché une fois |
| Échange token avec secret affiché | Impossible depuis le secret perdu | HTTP 200, ancien secret HTTP 401 après rotation |
| Rejeu du code OAuth | Sans objet | HTTP 400 après première consommation |
| Hook d’en-tête/pied de page défaillant | Écriture déjà réalisée | Retour d’erreur, aucune mutation |
| Écriture SQL défaillante | Sans objet | Retour d’erreur, aucun secret montré, ancien hash conservé |
| Nonce / abonné WP sans capacité / invité | Sans objet | 403 / 403 / 400, aucune mutation |
| Client inconnu en rotation | Sans objet | Retour d’erreur, aucune confirmation |
| Fans avec case forcée en POST | `first_party=0`, case décochée localement | `first_party=0`, case décochée et désactivée |
| Fans avec valeur anormale `1` semée en base | Sans objet | Case inéligible ; sauvegarde remet `0` sans rotation |
| Client exact Faluss.com | Sans objet | Case officielle préservée, scopes basic/email conservés |
| Retour à la liste et journaux | Secret perdu non récupérable | Aucun secret brut retrouvé |

L’apparence cochée rapportée en production n’a pas été reproduite : cette preuve
ne prétend pas connaître la valeur stockée sur le site. L’échange token utilise
un code PKCE de recette semé en SQL ; ce n’est pas une connexion réseau Me→Fans.

## Captures

- [Confirmation native WordPress](confirmation-desktop.png) : secret de fixture
  remplacé **dans le DOM avant la capture**, aucune image avec secret brut enregistrée.
- [Fiche Fans ordinateur](fans-client-desktop.png).
- [Fiche Fans mobile, viewport 390 px](fans-client-mobile.png).
- [Résultat navigateur](browser.json).
- [Empreintes des deux fichiers runtime testés](runtime-sha256.json), normalisées LF.

## Reproduire

Prérequis : PHP/mysqli/mbstring, MariaDB, WordPress 7.1.2 décompressé, wp-cli 2.12.0,
dépendances Composer physiques correspondant au lock. Ne pas employer la jonction
`vendor` Windows d’un autre checkout. La CI utilise les archives épinglées.

```bash
git archive HEAD -o /tmp/sso-plugin.tar
python3 tests/Identity/recipe/sso-admin-wordpress.py \
  --core /chemin/wordpress --cli /chemin/wp-cli.phar \
  --archive /tmp/sso-plugin.tar --vendor /chemin/vendor
# Pour la reproduction initiale, utiliser une archive de 7aaecf3 et --expect-bug.
# --keep-web maintient uniquement la fixture locale pour la capture navigateur.
```

`capture.cjs` utilise Chrome/Playwright et le fichier de session privé de la fixture
WSL, sans sauvegarder cookie, secret, trace ou HTML. Les identifiants et textes visibles
sont exclusivement ceux de la recette. La production, ses extensions, Elementor,
ses caches et sa connexion SSO restent à vérifier par le propriétaire.

[Diagnostic et procédure sûre de reprise](../../incidents/2026-09-30-sso-client-secret-confirmation.md).
