# Changelog

Toutes les modifications notables de ce projet sont documentées dans ce fichier.

Le format s’inspire de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet utilisera une gestion sémantique des versions dès la première version publiée.

## Unreleased

### Corrigé

- Validation stricte des jetons et réponses Token Engine, cache Bearer court avec renouvellement unique après rejet, et résolution exclusivement serveur du sujet de session.
- Contrat public complet de `Faluss_Catalog_Themes`, y compris l’activation, les méthodes administratives historiques et leur route de retour compatible.
- Couverture des scénarios Catalog de coexistence, Connector indisponible et conservation des options d’activation sans écrasement.
- Parité exacte des variables CSS du module Faluss Theme avec le plugin historique, notamment les noms de rayon et d’action consommés par Faluss Link.
- Libellés de couleur visibles avec le sélecteur WordPress, noms des ombres, identifiants de nonce distincts et aperçu non interactif dans l’écran « Identité visuelle ».

### Ajouté

- Module Faluss Portal optionnel pour le Hub, avec shortcode, navigation, assets, manifest, catalogues descriptifs et façades historiques conservés.
- Adaptateurs fermés de Portal vers Identity Client, Apps Registry, Subscriptions, Token Engine et Analytics, sans lecture directe de table ni copie de prix, droit ou montant PF.
- Contrats automatisés Portal pour la session liée, l’unique lecture Apps Registry, les dépendances indisponibles, l’autorité économique, la coexistence et la parité de présentation.
- Module Faluss Apps Registry optionnel pour `.me` et le Hub, avec façades PHP historiques, contrats fermés, lecture membre bornée et cache public fédéré de cinq minutes au plus.
- Contrats automatisés du registre pour les manifests propriétaires, le modèle `apps.registry`, les collisions de sources, l'autorité Hub exacte et l'absence de fuite du `faluss_id`.
- Module Faluss Identity Client optionnel pour `faluss.com`, avec Authorization Code, PKCE S256, état navigateur lié, tables et façades publiques historiques conservées.
- Contrats automatisés de l'Identity Client pour le schéma InnoDB, les retours locaux exacts, la consommation transactionnelle de l'état, les claims, la création limitée à `subscriber` et la coexistence.
- Module Token Engine Connector optionnel pour `.me`, sans ledger local, avec façade PHP compatible, administration privée et garde de coexistence avec l’ancien plugin.
- Contrats automatisés du Connector pour la protection du secret, les permissions, le cache et renouvellement Bearer, les erreurs fermées, le sujet Identity et les actions administratives.
- Module de catalogue optionnel pour `.me`, avec façade PHP compatible Faluss Link et garde empêchant la coexistence avec l’ancien plugin.
- Test de parité de lecture du catalogue issu d’une comparaison avec le plugin historique sur des données synthétiques.
- Écran de gestion du catalogue préparé dans l’administration Faluss, non activé avant la bascule du module.
- Vérification CI de la syntaxe JavaScript de l’écran du catalogue.
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
