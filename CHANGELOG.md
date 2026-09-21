# Changelog

Toutes les modifications notables de ce projet sont documentées dans ce fichier.

Le format s’inspire de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet utilisera une gestion sémantique des versions dès la première version publiée.

## Unreleased

### Ajouté

- Actions d’administration du catalogue avec contrôles de droits, nonces et compatibilité des événements, non activées par défaut.
- Adaptateur de lecture des droits de thème fournis par Token Engine Connector, avec filtrage des définitions invalides.
- Éditeur du catalogue préparant en mémoire les créations, modifications et suppressions avec validation des droits associés.
- Lecteur de thèmes de catalogue compatible avec le schéma et les règles de lecture historiques, non activé en production.
- Inventaire en lecture seule des plugins Faluss historiques actifs dans le tableau de bord de chaque site.
- Validation des PR empilées par les workflows de gouvernance et de qualité PHP.
- Tests automatisés des hooks, de l’option et du handle CSS conservés par le module de jetons visuels.
- Module optionnel des jetons visuels de `.me`, réutilisant l’option et les variables CSS du plugin historique sans activation automatique.
- Tableau de bord WordPress Faluss commun aux deux rôles, limité aux administrateurs et sans interaction avec les plugins historiques.
- Point d’entrée WordPress inactif sans rôle explicite, rôles de site et registre de modules avec validation des dépendances.
- Tests unitaires du socle et contrôles CI de syntaxe PHP, analyse statique et tests.
- Documentation de l’architecture initiale et du mécanisme de cohabitation.
- Règles communes de contribution pour les agents Codex.
- Template français unique pour les pull requests.
- Validation automatique du nom des branches et des sections obligatoires des pull requests.
- Documentation du workflow de contribution.

## 0.0.0 - 2026-09-21

### Ajouté

- Initialisation du dépôt.
