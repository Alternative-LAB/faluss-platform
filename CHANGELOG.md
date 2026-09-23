# Changelog

Toutes les modifications notables de ce projet sont documentées dans ce fichier.

Le format s’inspire de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet utilisera une gestion sémantique des versions dès la première version publiée.

## Unreleased

### Documentation

- Interface d’administration Platform modernisée avec une hiérarchie plus claire, des cartes d’état, des badges accessibles, un tableau responsive et des états de focus visibles.
- Migration de production achevée : `faluss-platform` devient l'unique plugin Faluss chargé sur les deux sites et les anciennes sources sont archivées hors de WordPress.
- Installation initiale de `faluss-platform` sur les deux sites, avec sauvegarde, vérification et retour arrière documentés.
- Bascule de Faluss Theme sur `faluss.me`, avec parité CSS et contrôles de production consignés.
- Bascule de Faluss Catalog sur `faluss.me`, avec parité des lectures, droits et retour arrière consignés.
- Bascule de Token Engine Connector sur `faluss.me`, avec authentification réelle, permissions et lectures métier validées.
- Bascule de Faluss Identity Client sur `faluss.com`, avec tables, PKCE, cookie de liaison et rejet du callback invalide vérifiés.
- Bascule de Faluss Apps Registry sur les deux sites, avec manifests fédérés et document membre validés.

### Corrigé

- Bootstrap Identity en lecture seule sur les requêtes publiques afin de conserver les migrations dans leurs chemins administratifs privilégiés.
- Documentation du rollback Link après une constante d'activation absente et renforcement de la vérification préalable à la désactivation historique.
- Projection du montant du gain quotidien dans Portal et suppression de la fausse échéance historique lorsqu'un abonnement n'en fournit aucune.
- Documentation de la bascule Portal validée sur faluss.com et de son retour arrière.
- Ordonnancement des quatre adaptateurs Portal pendant `plugins_loaded`, afin d'éviter le double enregistrement des providers et de la source Apps Registry.
- Double enregistrement de la source `faluss-me` lorsque Identity Client Platform démarre pendant `plugins_loaded`, qui verrouillait les lectures d'Apps Registry.
- Gouvernance des PR exécutée depuis la branche de base pour empêcher une PR de neutraliser ses propres contrôles, avec validation plus stricte des sections et des gitmojis.
- Règle explicite imposant branche, PR et contrôles CI avant `main`, sans approbation obligatoire, y compris pour les contributions produites avec une IA.
- Validation stricte des jetons et réponses Token Engine, cache Bearer court avec renouvellement unique après rejet, et résolution exclusivement serveur du sujet de session.
- Contrat public complet de `Faluss_Catalog_Themes`, y compris l’activation, les méthodes administratives historiques et leur route de retour compatible.
- Couverture des scénarios Catalog de coexistence, Connector indisponible et conservation des options d’activation sans écrasement.
- Parité exacte des variables CSS du module Faluss Theme avec le plugin historique, notamment les noms de rayon et d’action consommés par Faluss Link.
- Libellés de couleur visibles avec le sélecteur WordPress, noms des ombres, identifiants de nonce distincts et aperçu non interactif dans l’écran « Identité visuelle ».

### Ajouté

- Revue du `main` du 22 septembre 2026 : traçabilité des commits directs, résultats de contrôle et validations de migration encore nécessaires.
- Dossier de retrait de Faluss Production Reset, inventaire exact de son runtime destructif et contrat automatisé garantissant que Platform n’expose ni module, ni action, ni route de reset.
- Module Faluss Federation optionnel pour les rôles `me` et `hub`, avec transport Ed25519, politiques locales de pairs, anti-rejeu transactionnel, diagnostics bidirectionnels et administration historiques conservés.
- Contrats JSON FED et contrats automatisés Federation pour la canonicalisation, les signatures, la fraîcheur, les en-têtes, les limites, la concurrence, les politiques, les diagnostics et la parité des sources historiques.
- Module Faluss Analytics optionnel pour le rôle `hub`, avec consumer Events, trois tables privées, reçus d’idempotence, anonymisation, agrégats journaliers, read-model privé et rétention historique conservés.
- Schémas AN-01 et contrats automatisés Analytics pour les six mappings, les rejouements, les rollbacks, les bornes, la suppression de sujet et l’absence de faux zéro ou de visiteur unique inventé.
- Module Faluss Events optionnel pour les rôles `me` et `hub`, avec catalogues, stockage append-only, outbox, inbox, consumers, leases, retries, rétention et workers Cron historiques conservés.
- Contrats JSON EVT et contrats automatisés Events pour les événements locaux et intersites, doublons, rollback, panne réseau, reprise, limites de stockage, purge et parité des sources historiques.
- Module Faluss Identity optionnel pour `.me`, avec registre `faluss_id`, passwordless, profils publics, onboarding, serveur OAuth, consentements, administration et audit historiques conservés.
- Contrat public étroit d’Identity pour Link, projection bornée des profils publiés sans jointure intermodule, cycle d’activation/désactivation et garde contre une seconde autorité active.
- Contrats automatisés Identity pour le schéma additif, PKCE, codes à usage unique, passwordless, sessions, onboarding, navigation, profils publics, coexistence et parité octet des sources et assets historiques.
- Module Token Engine optionnel pour le Hub, avec projets, permissions, règles, droits, octrois, ledger générique et ledger PF append-only historiques conservés.
- Contrat Platform étroit pour le gain quotidien Hub, utilisé par Portal sans lecture directe de balance ou de table.
- Contrats automatisés Token Engine pour la coexistence, les schémas, la parité historique, les gains, l’idempotence et les compensations.
- Module Faluss Subscriptions optionnel pour le Hub, avec stockage InnoDB, catalogue, essais, entitlements, facturation Stripe, webhooks, notifications, administration et retours historiques conservés.
- Contrat public étroit de Subscriptions pour Portal, cycle d’activation/désactivation, garde de coexistence et SDK Stripe `21.3.0` verrouillé sans secret versionné.
- Contrats automatisés Subscriptions pour les écritures transactionnelles, Checkout, essai, retours, idempotence, refus fermés et parité des sources historiques.
- Module Faluss Link et Studio optionnel pour `.me`, avec éditeur, profil public, blocs, collections, médias, découvertes, onboarding, shortcodes et widgets historiques conservés.
- Adaptateurs fermés de Link vers Identity, Catalog et Token Engine Connector, sans copie d’identité ni décision économique locale.
- Contrats automatisés Link/Studio pour la coexistence, le schéma, les mutations transactionnelles, l’autosauvegarde, les assets historiques et la présentation mobile et bureau.
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
