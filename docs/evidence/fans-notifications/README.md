# Preuves — notifications persistées

WordPress 7.1.2 réel, Twenty Twenty-Five, PHP 8.5.4, MariaDB 11.8.6 sur socket
privé ; Chrome 154, 1440×900 et 390×900. Comptes et contenus de recette synthétiques,
cookies/nonces réels, HTTP limité au loopback. Aucun site cible ni SSO distant.

- 314 tests / 5256 assertions, PHPStan zéro erreur, lint PHP ; deux dépréciations
  préexistantes de la suite. Recette SQL/HTTP domaines complète réussie.
- Recette d’admission réelle : 83–84 assertions selon le gagnant de la concurrence.
- `notifications-http.py` : 60 contrôles de destinataire, nonce, pagination,
  compteurs, rejeu et rollback réel d’une bio refusée et d’un message.
- `notifications-reports-http.py` : 12 contrôles, deux destinataires de dossier,
  motif interne canari absent, liens privés exacts, finalisation explicite.
- `notifications-retention-http.py` : 17 contrôles, MyISAM refusé, purge échouée
  explicitement et rollback, admission fermée, expiration conversation et preuve,
  suppression associée des événements sans effacer les autres décisions.
- Navigateur : six captures, vrais formulaires lu/non lu avec rechargement,
  liens vers la vraie conversation, compteur, absence de toolbar et de débordement.

Les événements visibles résultent de véritables appels aux services sur cette
fixture, pas de notifications insérées pour remplir une capture. L’approbation
et la finalisation d’un dossier sont des scénarios synthétiques : aucune
attestation de politique réelle ni aucun recours d’une personne réelle.

Les scripts sont sous `tests/Fans/Profiles/recipe/notifications-*.py` et
`tests/Fans/Ui/recipe/notifications-wordpress.cjs`. Ordre : admission avec options
`--backoffice --test --keep`, notifications HTTP, dossiers HTTP, navigateur,
enfin rétention (qui supprime les messages de la fixture).
La configuration messagerie n’est ouverte que dans cette fixture pour ces tests.
Ces captures ne sont pas une preuve du rendu Elementor sur `fans.faluss.me`.
