# ADR 0015 — Isoler le client SSO Fans du client Identity Hub

## Décision

Fans consomme l'autorité de Me au moyen d'un module `fans-sso` propre au rôle `fans`. Ses états, liaisons, route de retour, cookie, shortcode et constante de secret sont distincts de ceux d'Identity Client sur Hub. L'opt-in exige un client confidentiel Me, une URI HTTPS exacte et un schéma Fans vérifié. Les contrats et la porte d'activation sont décrits dans [FANS-SSO.md](../modules/FANS-SSO.md).

## Motif

Identity Client sur Hub contient aussi une projection Apps Registry, des conventions Portal, un widget Elementor et des surfaces historiques de `faluss.com`. Étendre sa liste de rôles à `fans` transporterait ces choix de domaine vers Fans et créerait des collisions de stockage et de hooks. Le client Fans réutilise le **protocole** de Me, sans importer les adaptateurs métier du Hub.

## Conséquences

Les deux clients ont des implémentations locales séparées et un contrat SSO commun à caractériser par leurs tests. Les évolutions du protocole Me devront vérifier les deux consommateurs. Aucun changement n'est apporté aux liaisons Hub ou à son activation actuelle. Le domaine Fans et les contrats Federation, Events et Apps Registry restent des lots distincts.
