# Faluss Fans — état des lots et contrats

## État vérifié dans le code

| Lot | État | Portée |
| --- | --- | --- |
| Admission du rôle, PR #53 | Implémenté et fusionné | `FALUSS_PLATFORM_ROLE='fans'` donne accès à l'administration commune ; les modules Me et Hub et leurs hooks d'activation restent isolés, y compris Link avec un flag erroné. |
| Client SSO Fans, PR #54 | Implémenté sur la branche précédente, opt-in, non activé sur un site réel | Client distinct de celui du Hub, deux tables locales, état navigateur, PKCE S256, échange avec Me et session WordPress locale. Voir [FANS-SSO.md](FANS-SSO.md). |
| Profils créateurs structurés, PR #55 | Implémenté sur la branche précédente, opt-in, non activé sur un site réel | Statut `active` limité à l'approbation de publication, `identity_verified=false` dans l'API. La vérification d'identité et la condition de vente du créateur restent à implémenter. Aucun texte libre, média ou upload. Voir [FANS-PROFILES.md](FANS-PROFILES.md). |
| Followers, PR #56 | Implémenté sur la branche précédente, opt-in, non activé sur un site réel | Suivi/retrait idempotents d'un profil actif par un membre SSO lié ; seul le nombre est public. Blocage, signalements, suppression de compte et rétention restent à traiter avant une expérience sociale complète. Voir [FANS-FOLLOWERS.md](FANS-FOLLOWERS.md). |
| Catalogue store structuré | Implémenté dans cette PR dépendante, opt-in, non activé sur un site réel | Deux catégories visibles et classables ; fiches adultes liées à un créateur masquées sans consentement explicite, fiches sans contenu ni prix, tentative d'achat refusée côté serveur et API. Voir [FANS-STORE.md](FANS-STORE.md). |
| Publications, messagerie, commandes et droits | Contractuel seulement | Aucun de ces domaines, stockage, route, transaction, paiement, achat effectif ou droit n'est implémenté ici. |

Le choix du rôle, du client confidentiel Me, de son secret et du flag Fans appartient à une configuration de site hors Git. Aucun site de production n'est configuré, activé, migré ou déployé par cette PR.

## Frontières des domaines métier

Fans sera propriétaire de ses profils créateurs, publications, relations de suivi, messagerie, catalogue de vente, commandes et droits locaux. Chaque personne aura un compte WordPress local et une liaison au `faluss_id` opaque ; ni l'adresse e-mail ni le `wp_user_id` d'un autre site ne serviront de clé de rapprochement ou de droit. Le client SSO Fans consomme le code d'autorisation à usage unique de Me, PKCE S256 et l'échange serveur à serveur selon le contrat Identity ; il n'émet pas d'identité ni de session centrale.

Hub conservera ses données et décisions propres. Fans intégrera les façades publiques validées de Federation, Events et Apps Registry sans copier leur transport, outbox, validation ou modèle de composition. Ces modules ne déclarent aujourd'hui que `me` et `hub` : leur éventuelle admission sur `fans`, leurs identités de nœud, leurs politiques de pair et leurs contrats de lecture devront être ajoutés et testés dans des PR séparées. Aucune dépendance implicite à leurs classes internes ou tables n'est autorisée.

Les contrats à spécifier avant le code métier sont : liaison SSO et session locale ; identité et autorisation du créateur ; publication et visibilité ; suivi et blocage ; messages et pièces jointes ; catégorie canonique du produit ; commande, remboursement et droit ; manifests Apps Registry ; événements versionnés et destinataires Federation. Chaque écriture devra définir son propriétaire, sa capacité, son nonce ou sa permission API, sa règle d'idempotence et son traitement du retrait ou de l'échec.

## Catégories et portes commerciales

| Catégorie canonique | Présentation permise | Achat et livraison dans ce lot | Condition d'ouverture future |
| --- | --- | --- | --- |
| Contenu autorisé hébergé par Fans | Catégorie et fiches structurées visibles, sans contenu ni prix pour l'instant | Tentative d'achat refusée (503), aucune commande ni livraison | Validation explicite du prestataire pour ce modèle de plateforme, contenu autorisé, intégration de paiement et recettes de bout en bout |
| Droit à une livraison adulte externe | Catégorie distincte, visible et classable ; fiches associées à un créateur masquées tant que son consentement explicite n'existe pas | Tentative d'achat refusée (403) côté serveur et API ; aucun droit délivré ; contenu et livraison hors Fans | Consentement créateur vérifié avant toute publication de fiche, acceptation **explicite** du prestataire pour ce parcours précis, puis PR et activation distinctes après validation |

Le catalogue expose une API de tentative d'achat qui refuse toujours les deux catégories. Il n'a ni prix, ni panier, ni checkout, ni création de commande, ni intention de paiement, ni webhook, ni émission de droit. Le refus de l'API actuelle est testé ; il ne prouve pas le comportement des futurs chemins. Avant d'implémenter ces chemins, le service de commande devra imposer une règle centrale **refus par défaut** pour tout achat de droit à une livraison adulte externe : panier, checkout, création de commande, intention de paiement, API, action d'administration, webhook, reprise et émission de droit. Une requête forgée, un changement de catégorie ou une ancienne commande ne devront pas contourner ce refus. Un flag, une réponse client ou une simple validation du modèle général ne constituent pas l'acceptation du parcours adulte externe. La levée du refus exigera un changement serveur explicite, revu et testé après preuve de l'accord du prestataire.

Aucun contenu adulte ne doit être hébergé ou envoyé par Fans, y compris dans les publications, médias, fichiers, aperçus, messages et pièces jointes. Les contrôles d'upload, de texte, de rendu, d'API et de modération devront être définis avant toute ouverture. La catégorie du droit externe ne doit pas devenir un canal de stockage, de proxy, d'aperçu ou de transmission du contenu livré ailleurs.

## Étapes et preuves avant ouverture

1. **Fondation Fans** : le client SSO Me, les comptes locaux non privilégiés, les tests de rejeu et de session et un profil créateur structuré sont implémentés par deux PR dépendantes. La recette locale a vérifié tables InnoDB, création/liaison directe, permissions REST, approbation du profil et retrait des flags. Valider encore le callback HTTP exact, le parcours Me, les cookies et les courses réelles avant activation réelle. Les champs éditoriaux du profil exigeraient un contrat de modération distinct.
2. **Social et contenu** : les profils structurés et le suivi de base sont implémentés dans deux PR dépendantes. Publications, messagerie, blocage, signalement, retrait et rétention restent à construire avec permissions côté serveur et garde contre tout contenu adulte. Tester les uploads, messages, API, accès directs, changements de visibilité et tentatives de contournement ; confirmer le comportement sur WordPress et les surfaces utilisées.
3. **Contenu autorisé hébergé** : la catégorie est classable, sans contenu ni vente. Obtenir la validation du prestataire pour ce modèle de plateforme ; terminer les contrats publication autorisée, commande, paiement, remboursement et droits, puis tester les doublons, webhooks, échecs, annulations et accès après révocation. Une recette de bout en bout et un rollback vérifié précèdent l'ouverture.
4. **Droits à livraison adulte externe** : la catégorie est présentée sans achat possible ; les fiches nominatives restent masquées en l'absence de consentement explicite du créateur et l'API existante refuse. Définir et tester le recueil du consentement avant publication. Prouver à nouveau par tests serveur et API que tous les chemins de commande et de délivrance futurs refusent. Obtenir l'acceptation explicite du prestataire pour ce parcours distinct. Toute ouverture commerciale sera un lot séparé avec revue du contrat de livraison externe, du refus par défaut, des contrôles de contenu et des parcours de paiement et de remboursement.

Les tests de la PR #53 démontrent la reconnaissance du rôle et l'isolation de ses hooks. Ceux des PR SSO, profils, followers et catalogue ajoutent des preuves unitaires. Une recette locale jetable WordPress/MariaDB a vérifié les cinq tables Fans InnoDB sans table Link malgré son flag, la création/liaison SSO directe avec échec et nouvelle tentative, les permissions REST des profils, la fiche adulte masquée, les refus 403/503 de l'API actuelle et la fermeture des routes après retrait des flags. L'échange réseau avec Me, le navigateur, les routes Followers en HTTP et les courses réelles restent à tester. Les refus actuels ne prouvent pas ceux des commandes, paiements ou droits, qui restent à construire.

## Prochains moteurs

La matrice des propriétaires, les contrats proposés et les portes de validation sont décrits dans [FANS-ENGINE-OWNERSHIP.md](FANS-ENGINE-OWNERSHIP.md). Ils ne constituent pas des moteurs activés. La référence visuelle Fans reste conceptuelle ; aucun badge de la maquette ne prouve une vérification d'identité.
