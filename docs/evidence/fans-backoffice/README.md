# Preuves — back-office Fans

Environnement jetable exclusivement local : WordPress 7.1.2, Twenty Twenty-Five,
PHP 8.5.4, MariaDB 11.8.6 (socket privé), Chrome 154, réseau navigateur limité au
loopback. Tous les comptes sont des fixtures `example.invalid` ; aucun compte
ou site réel. La chaîne « Fixture private pending name » a été approuvée par la
recette avant les captures : elle ne prouve aucune diffusion avant modération.

- PHP lint de chaque fichier modifié ; PHPStan : zéro erreur.
- Suite complète : 314 tests, 5220 assertions ; deux dépréciations préexistantes.
- Recette SQL/HTTP domaines complète `run.py --http --publications --messages` :
  succès (primitives WP simulées, MariaDB réel).
- WordPress réel `admission-wordpress.py --backoffice --test --keep` : 84 contrôles.
- `backoffice-http.py` sur ce WordPress : 62 contrôles, dont décisions, visibilité,
  concurrence, nonce, manque de capacité, journal défaillant/rollback et rétention.
- `backoffice-wordpress.cjs` : 26 captures, 1440×900 et 390×900 ; grant/revoke via
  vrais formulaires navigateur puis accès avec session distincte. Aucun mock REST.

Les fichiers `*-detail-*` sont volontairement cadrés sur le contenu ; les autres
montrent la navigation racine. Les captures messagerie/signalements utilisent
le compte modérateur habilité, les autres le compte administrateur sans cette
capacité. Les envois messagerie et son attestation restent fermés dans la fixture.

Limites : aucun site cible, aucun Elementor cible, aucun SSO distant ni cron
système. Les erreurs SQL injectées sont attendues ; aucune erreur fatale PHP.
La matrice et les limites opérateur sont dans [FANS-BACKOFFICE](../../modules/FANS-BACKOFFICE.md).
