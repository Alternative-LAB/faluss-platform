# Migration de Faluss Link et Studio

Faluss Link est la surface publique et éditoriale du rôle `me`. Le module `0.4.0` conserve le profil public, le Studio interne de fallback, les blocs, collections projetées, médias, découvertes privées, onboarding, récompense quotidienne, shortcodes et widgets Elementor. Le [Studio V2 natif](ME-STUDIO.md) s’ajoute comme fournisseur optionnel sans dupliquer ces points d’entrée.

## Propriété et contrats consommés

| Besoin Link | Autorité publique | Comportement fermé |
|---|---|---|
| Identité active, profil public et onboarding | Faluss Identity via `LinkIdentityAdapter` | carte/Studio/onboarding indisponible, aucune identité locale inventée |
| Thèmes de carte | Faluss Catalog via `LinkCatalogAdapter` | thème système Link, thèmes protégés indisponibles |
| Droits et récompense quotidienne | Token Engine Connector via `LinkTokenEngineConnectorAdapter` | droit refusé et récompense indisponible, sans montant ni sujet navigateur |

Identity reste l’unique propriétaire du `faluss_id`, du slug, du nom, de la bio, de l’avatar, de la publication et de la projection des liens publics. Link conserve seulement ses préférences de carte, son flux ordonné de blocs et la bibliothèque privée de découvertes. La transaction Studio continue de verrouiller le profil Identity, la carte et les blocs dans une seule transaction MariaDB avant de projeter les liens et de commit.

`LinkIdentityAdapter` consomme désormais le contrat étroit `IdentityContract`, qu’Identity soit encore fourni par le plugin historique ou par son module Platform. La liste privée des découvertes lit uniquement la table Link, puis demande à Identity une projection bornée des profils publiés ; elle ne joint plus et ne lit plus directement la table Identity.

## Compatibilité conservée

| Surface | Contrat historique |
|---|---|
| Version et activation | `FALUSS_LINK_VERSION` à `0.4.0`, opt-in `FALUSS_PLATFORM_LINK` |
| Options | `faluss_link_schema_version` à `4`, `faluss_link_network_catalog` |
| Tables | `faluss_link_cards`, `faluss_link_blocks`, `faluss_link_discoveries`, `faluss_link_discovery_settings` avec préfixe WordPress |
| Shortcodes | `faluss_link_card`, `faluss_link_appearance`, `faluss_link_studio`, `faluss_link_daily_reward`, `faluss_link_discoveries` |
| Écritures | actions `admin_post_faluss_link_*` et AJAX authentifiés historiques ; aucun équivalent `wp_ajax_nopriv_*` |
| URLs publiques | slug et rewrite possédés par Identity, rendu dynamique sans cache |
| Studio | mêmes onglets, libellés, collections, blocs, prévisualisation partagée, version agrégée et mutations fermées |
| Elementor | mêmes noms de widgets, contrôles et sélecteurs isolés par `{{WRAPPER}}` |
| Assets | assets historiques sous `assets/link/`, complétés de façon ciblée pour les images/visibilités de lien ; assets V2 isolés sous `assets/me-studio/` |
| Manifestes/Events | fournisseurs descriptifs historiques conservés ; aucune production ou collecte d’événement n’est activée par ce lot |

Le schéma V4 ajoute uniquement la composition canonique à `faluss_link_cards`, avec migration V3 additive et reprise d’une promotion interrompue. Il échoue fermé devant une installation partielle ou une table non conforme et n’exécute aucun DDL depuis une requête publique. Il n’efface, ne renomme et ne convertit aucune donnée existante. Les collections restent une projection des blocs `section_title`, `text` et `link`, sans nouvelle table. Les URLs de contenu restent HTTPS, les médias doivent appartenir à l’utilisateur courant et la récompense ne reçoit jamais de `faluss_id`, montant ou règle depuis le navigateur.

## Activation contrôlée et rollback

Le module est limité au rôle `me` et dépend des modules Platform Catalog et Token Engine Connector :

```php
define('FALUSS_PLATFORM_ROLE', 'me');
define('FALUSS_PLATFORM_CATALOG', true);
define('FALUSS_PLATFORM_TOKEN_ENGINE_CONNECTOR', true);
define('FALUSS_PLATFORM_LINK', true);
```

Ces constantes appartiennent à une configuration non versionnée. Faluss Identity historique ou le module Platform Identity doit être l’unique autorité active. L’ancien plugin `faluss-link` doit rester désactivé ; si l’une de ses classes est déjà chargée, le nouveau module ne s’enregistre pas et son démarrage direct refuse la collision. La copie Link historiquement inactive de `faluss.com` ne doit jamais être activée.

Pour revenir du Studio V2 au Studio interne, désactiver uniquement `FALUSS_PLATFORM_ME_STUDIO_V2` et charger une nouvelle requête. Aucun plugin V1 ne doit être réactivé. La colonne additive `composition` reste disponible, sans suppression ni restauration de base. Le rollback complet de Link reste une opération de production séparée, soumise au dossier de bascule du Master Plugin et jamais effectuée par ce lot.

## Preuves et porte de bascule

Les tests automatisés verrouillent les assets visuels et interactifs, les sources de compatibilité, les cinq shortcodes, les classes historiques, l’absence d’AJAX public, les adaptateurs positifs et négatifs, les mutations Studio fermées, la transaction unique et le refus de coexistence. Les contrats historiques FL-01 à FL-19, Studio V1, FL-HOTFIX-01 et TE-03 ont été exécutés sur la source canonique avant le port.

Cette preuve reste statique et synthétique. Avant toute bascule de production, une copie représentative de `faluss.me` doit vérifier l’ordre de chargement WordPress, les quatre tables et les options réelles, Identity, Catalog, Connector, Elementor, les URLs et SEO, la création/édition/publication, les uploads, les collections, les découvertes, la récompense, les transactions MariaDB, le responsive desktop/mobile, le clavier et l’accessibilité. Aucun test de ce lot n’active le module, ne déploie un plugin ou n’écrit dans une instance WordPress réelle.

La bascule de production a été réalisée le 23 septembre 2026. Les quatre profils publics, les cinq shortcodes, les trois cartes, cinq blocs, une découverte, les assets et les contrats Identity/Connector ont été rapprochés. L'incident d'opt-in #29 a été rollbacké avant une seconde bascule réussie ; l'ancien dossier Link est désormais archivé hors de WordPress.
