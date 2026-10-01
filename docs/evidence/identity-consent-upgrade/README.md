# Consentement après mise à jour — comparaison isolée

Base avant correctif : `ce74a283340c9f0f63b6d827152db7976d8eae68` (0.11.1).
WordPress 7.1.2, thème Twenty Twenty-Five, PHP 8.5.4, MariaDB 11.8.6,
Chrome 154.0.8037.58. Installations jetables, base et socket propres, TLS boucle
locale, comptes générés, aucun appel sortant ou accès cible.

## Cause démontrée et limites

Après suppression des seules tables/options de consentement dans la fixture,
pour représenter une mise à jour sans activation ni visite administrateur :
la version de base présente deux accords successifs et délivre deux codes,
sans créer le stockage des accords. Le navigateur reproduit ce cas avec
`EXPECT_UPGRADE_BUG=1` et confirme que les tables restent absentes.

Après correctif, la première requête prépare le schéma avant toute transaction
d'autorisation. Le premier accord persiste ; la deuxième connexion passe sans
écran d'accord. Le client Fans garde `first_party=0`. Un schéma déjà installé
passé volontairement en MyISAM entraîne un 503 à l'approbation, sans code ni
accord ajouté. Aucun contournement des contrôles de permission n'est introduit.

Ceci démontre une cause possible du symptôme rapporté, pas l'état du site cible
qui n'a pas été consulté. Une modification réelle du client, des scopes ou une
révocation doit toujours provoquer une nouvelle demande.

## Vérifications

- 309 tests PHPUnit, 4 926 assertions ; deux dépréciations préexistantes.
- PHPStan : aucune erreur ; lint des PHP modifiés et diff-check réussis.
- `consent-sql.php` sur vrai WordPress/MariaDB : migration sans session admin,
  révisions, isolation propriétaire, scopes exacts, révocation/configuration
  concurrentes, rollback accord/code et configuration/révision, refus MyISAM.
- `consent-browser.cjs` : neuf groupes complets dans [results.json](results.json),
  handlers HTTP réels, PKCE négatif, code à usage unique, nonce étranger/invalide,
  identité suspendue, rotation du secret et injection de panne d'écriture.
- Captures contrôlées à 1440 × 900 et 390 × 900, aucun débordement horizontal :
  [accord ordinateur](consent-1440.png), [accord mobile](consent-390.png),
  [révocation ordinateur](authorizations-1440.png), [révocation mobile](authorizations-390.png).

Les cookies/secrets de recette sont hors dépôt. La connexion passwordless cible,
Elementor cible et l'installation de production ne sont pas attestés ici.
