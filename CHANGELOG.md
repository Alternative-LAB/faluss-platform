# Changelog

Toutes les modifications notables de ce projet sont documentées dans ce fichier.

Le format s’inspire de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet utilisera une gestion sémantique des versions dès la première version publiée.

## Unreleased

### Documentation

- Décision HoF : pack sans score, cadeau de 300 pièces financées dépensées = 300 points de session, soutien direct = 1 point par euro confirmé ; distinction progression sur dépense réelle et revenu EUR privé, étude PF Hub sans second ledger et adaptation obligatoire du simulateur #73 avant fusion.
- Matrice des propriétaires Fans/Me/Hub/Pro, limites des moteurs existants et contrats de publication, progression, cosmétiques, Max et commerce fermé.
### Vérifié

- Recette REST HTTPS du catalogue et des profils : permissions, fiches adultes masquées, refus 403/503 et changement de catégorie.

- Recette SSO HTTPS Me/Fans sur deux WordPress/MariaDB jetables : cookies, rejeu, expiration, collision, rollback/retry et callbacks concurrents ; limites navigateur et OTP documentées.

### Ajouté

- Catalogue Fans opt-in avec deux catégories visibles, fiches structurées et refus serveur de toute tentative d'achat actuelle, API comprise.
- Fiches adultes associées à un créateur masquées sans consentement explicite ; refus d'achat 403/503 fondé sur la catégorie stockée, y compris après changement.
- Suivi Fans opt-in : relation locale idempotente, retrait et compteur public sans identité de follower.
- Limites d'ouverture sociale du suivi Fans documentées : blocage, signalements, suppression de compte et rétention à implémenter.
- Profils créateurs Fans opt-in : identifiant public opaque, catégorie fermée, approbation et suspension, routes REST à permissions explicites sans champ libre ni média.
- Champ de contrat `identity_verified=false` sur chaque profil Fans ; l'approbation de publication ne constitue pas une vérification d'identité ni une autorisation de vendre.
- Client SSO Fans opt-in avec tables, état navigateur, PKCE S256, échange confidentiel avec Me et session locale limitée aux subscribers liés.
- Contrat d'activation, rollback et preuves restantes pour le SSO Fans, indépendamment des domaines métier et commerciaux.

### Corrigé

- Création de compte Fans et liaison SSO atomiques sur InnoDB, avec verrouillage concurrent, rollback et invalidation du cache utilisateur en cas d'échec de liaison.

## [0.5.4] - 2026-09-25

### Corrigé

- Repositionnement du champ actif de l’onboarding explicitement instantané : événements de taille regroupés par frame, aucune correction du scroll de la fenêtre en réponse au défilement de Safari. Ancrage du panneau et clavier superposé conservés.
- Navigation principale et contextuelle du Studio sans rechargement de document, avec pill persistante animée ; liens directs, historique, clavier, sélection active et repli natif conservés. Mouvement supprimé selon la préférence système.
- Relecture canonique après sauvegarde sans rechargement complet ; erreurs et conflits conservent les saisies, chargements obsolètes annulés. Une relecture en échec après sauvegarde se retente sans répéter la mutation.

### Compatibilité

- Assets Me Studio 3.2.1. Aucune modification de carte publique, données, contrats de mutation, parcours, Fans ou flags. Aucune intervention WordPress ; essais navigateur locaux, sensation réelle iPhone à vérifier par le propriétaire.


## [0.5.3] - 2026-09-25

### Corrigé

- Make WordPress plugin updates reliable (`50a0e84`)

## [0.5.2] - 2026-09-25

### Corrigé

- Consommation de l’OTP et établissement WP/Registry réunis dans une transaction : une panne interne conserve la preuve valide pour une nouvelle tentative, sans réactiver une identité suspendue.
- Reprise des anciens curseurs Atomiques avec conservation du mode dans la transaction du curseur ; une session expirée présente la connexion.
- Alerte de sortie du Studio fondée sur les valeurs réellement modifiées ; confirmation serveur avant rechargement, champs conservés après refus de sauvegarde.

- Panneau d’onboarding ancré en bas sous le clavier, focus sans expansion automatique, champ actif révélé par le seul défilement interne et hauteur initiale adaptée aux contrôles.
- Composition V3 centrée et groupe d’identité descendu avec un espacement borné commun à l’aperçu, au public, au shortcode et à Elementor ; préférences historiques conservées.

### Modifié

- Studio V3 autonome : navigation Liens / Shop / Design / Profil, rubriques contextuelles, formulaire en pleine page et aperçu complet à la demande. Les treize éditeurs 0.5.1 restent accessibles ; Shop est explicitement indisponible, sans commerce fictif.

### Ajouté

- Diagnostics serveur bornés des étapes passwordless et des prérequis Studio ; aucune donnée personnelle ou secret transmis dans ces diagnostics.
- Régression transactionnelle OTP, recette WordPress/MariaDB/Elementor locale, parcours HTTP OTP, preuves mobiles et conflit HTTP 409 : `docs/evidence/me-v3-052/README.md`.

### Compatibilité

- Aucune migration, aucune intervention sur les sites ou leurs flags. Fond public pleine page 0.5.1, SSO et Fans conservés. La cause exacte de l’incident OTP de production reste non établie ; iPhone physique et configuration Elementor de production restent à valider par le propriétaire.

## [0.5.1] - 2026-09-25

### Corrigé

- Fond de la carte canonique prolongé jusqu’au bas du viewport et du contenu, sans bordure ni arrondi extérieur ; mêmes règles pour l’aperçu et les intégrations Link.
- Éditions perdues dans le Studio V3 rétablies : collections, contenus texte/média, suppression/visibilité/image des liens, ordre complet, bio/publication et réglages avancés.
- Paramètres de transition de couverture appliqués par le renderer commun ; cache des assets invalidé.

### Ajouté

- Rubriques natives Collections, Contenus et ordre, Réglages ; mutations unitaires de contenus dans l’agrégat Link existant, avec contrôle de version et ownership média.
- Une régression de page entière à 390 × 844, une preuve visuelle locale et une matrice de correspondance ancien Studio/V3.

### Compatibilité

- Aucune migration, aucun changement de flag ni intervention WordPress. Les versions et l’ordre des blocs restent canoniques ; une suppression doit être explicite.
- Recette réelle WordPress/Elementor/téléphone non exécutée. Résultats et limites : `docs/evidence/me-v3-viewport/README.md`.

## [0.5.0] - 2026-09-25

### Ajouté

- Studio V3 natif sous le flag V3, indépendant du flag Studio V2 : dix rubriques, aperçu canonique, sauvegardes ciblées et conflits de version.

### Corrigé

- Composition publique Simple/Atomique et aperçu de même densité ; couverture compacte ou pleine, avatar stable et suppression des décalages immersifs incompatibles.
- Priorité des choix temporaires sur le thème, bordure d'avatar identique avant/après sauvegarde, contraste serveur des boutons et invalidation immédiate des réponses d'aperçu obsolètes.
- Panneau mobile, champs à 16 px, clavier sans déplacement de progression, logo du projet, onglets Réseaux masqués et confirmation autonome.
- Graisse du nom incluse dans la version agrégée pour détecter les éditions concurrentes.

### Compatibilité

- Aucune migration ; données, médias, slug et blocs historiques conservés. Aucun changement au lot Fans, aux rôles ou au SSO.
- Livraison GitHub uniquement ; validation iPhone/WordPress/Elementor réel laissée à l'utilisateur. Preuves locales dans `docs/evidence/me-v3-correction/`.

## [0.4.0] - 2026-09-25

### Ajouté

- Parcours d'onboarding Faluss.me V3 natif, optionnel et désactivé par défaut, avec aperçu de la carte partagée, reprise des anciens curseurs et publication transactionnelle.
- Admission du rôle de site `fans` dans l'administration commune, sans module métier actif.
- Contrat de frontière et étapes de validation des futures catégories commerciales Fans.

### Corrigé

- Diagnostics structurés pour les échecs d'envoi d'image et signalement des médias de réseaux absents dans l'administration Link.
- Conservation du brouillon Identité V3 au Retour, au rechargement et après upload d'avatar ; préservation des dérogations de thème Link V4 lors des modifications successives du Studio.
- Empêche le hook d'activation Link d'installer son schéma sur un site non `me`, même si son flag est présent.

### Documentation

- Étend la recette locale V3 au parcours passwordless et aux uploads JPEG/GIF/WebP, avec réponses HTTP horodatées et protocole de staging en attente d'accès.

## [0.3.3] - 2026-09-24

### Ajouté

- Replace legacy inventory with module cards (`0b50070`)

### Documentation

- Refresh PR metadata (`0b50070`)

## [0.3.2] - 2026-09-24

### Ajouté

- Use full width for Faluss admin pages (#48) (`b616c5b`)

### Documentation

- Record WordPress 0.3.1 upgrade validation (#47) (`54be900`)

## [0.3.1] - 2026-09-24

### Corrigé

- Detect incomplete private updater installations (#45) (`71bc265`)

### Documentation

- Record scripted updater restore failure (#43) (`77b041c`)

## [0.3.0] - 2026-09-24

### Ajouté

- Automate semantic release preparation (`4d4fbd3`)

### Corrigé

- Parse gitmoji commits from squash bodies (#41) (`4d5705a`)
- Classify delivery commits as minor releases (`4d4fbd3`)

### Documentation

- Document scripted update activation recovery (#38) (`2317f63`)

## [0.2.0] - 2026-09-24

### Ajouté

- Publication privée de Faluss Platform par tag GitHub vers `updates.faluss.com`, avec archive de production et contrôle strict de la version.
- Client de mise à jour WordPress avec authentification par clé de licence et configuration centralisée dans l'administration Faluss.

### Sécurité

- Séparation complète entre le Bearer de publication GitHub Actions et la licence installée sur les sites WordPress.

### Corrigé

- Procédure de mise à jour scriptée renforcée pour conserver et vérifier l'état actif du plugin après son remplacement.

### Modifié

- Version du Master Plugin portée de `0.1.0` à `0.2.0` afin que l’updater WordPress reconnaisse la livraison de Studio V2 et Link 0.4.0 comme une mise à jour plus récente.

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

- Studio Faluss V2 natif et opt-in sur le rôle `me`, avec onboarding Atomique reprenable, preview/rendu public partagés, composition Link V4 additive, variantes visuelles, liens illustrés et registre d’extensions Apps Registry strict.
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
