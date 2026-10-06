# Preuve locale H0 — 5 octobre 2026

Ce rapport décrit le périmètre historique H0. Au 6 octobre, H1–H4 et
[F1a fermé](../../modules/FANS-PF-F1A-CLOSED.md) ont leurs implémentations et
preuves isolées distinctes ; l'absence de capacités ci-dessous se rapporte à
la base H0, pas au code actuel ni à une admission économique sur les sites.

Base `main` : `254b4c971b23f0f44e5033fe2b8b4787df243c7e` (0.12.6).
La [recette reproductible](../../../tests/TokenEngine/recipe/README.md) appelle
le module propriétaire inchangé sur WordPress 7.1.2, MariaDB 11.8.6 et PHP 8.5.4.
[Résultats exportés](checks.json) : **27 contrôles, zéro échec** ; huit workers
distincts pour la course des claims, connexions InnoDB réelles, terminaisons autour
de COMMIT et compensations entières concurrentes. Le runtime a été supprimé.

Tests ciblés TokenEngine / PfContract / Progression : **38 tests, 283 assertions**.
PHPStan : aucune erreur ; lint du worker et syntaxe Python valides. Le premier
contrôle de hashes dans la copie Windows CRLF échouait ; la même copie isolée
normalisée en LF passe, sans changement des sources ou des attentes historiques.

Les écarts d'idempotence sémantique et d'insuffisance de solde restent caractérisés,
aucune opération économique n'est corrigée sans accord. La ligne de débit
préchargée sert uniquement au test d'insuffisance. La perte d'acquittement COMMIT
est injectée au niveau wpdb ; elle ne simule pas un protocole HTTP opérationnel.
Les lots achetés, attributions, reçus signés et corrections partielles restent
absents. Aucun Hub cible ou préproduction n'est désigné ; aucune preuve de score
HoF ni d'achat réel n'est annoncée.

Décisions soumises au propriétaire nommé ALB-Origine dans
[#147](https://github.com/Alternative-LAB/faluss-platform/issues/147).
