# Faluss Fans — état des lots et contrats

## État vérifié dans le code

| Lot | État | Portée |
| --- | --- | --- |
| Admission du rôle, PR #53 | Implémenté et fusionné | `FALUSS_PLATFORM_ROLE='fans'` donne accès à l'administration commune ; les modules Me et Hub et leurs hooks d'activation restent isolés, y compris Link avec un flag erroné. |
| Client SSO Fans, PR #54 | Implémenté sur la branche précédente, opt-in, non activé sur un site réel | Client distinct de celui du Hub, deux tables locales, état navigateur, PKCE S256, échange avec Me et session WordPress locale. Voir [FANS-SSO.md](FANS-SSO.md). |
| Profils créateurs structurés | Implémenté dans cette PR dépendante, opt-in, non activé sur un site réel | Identifiant public opaque, catégorie fermée, statut `active` limité à l'approbation de publication, `identity_verified=false` dans l'API. La vérification d'identité et la condition de vente du créateur restent à implémenter. Aucun texte libre, média ou upload. Voir [FANS-PROFILES.md](FANS-PROFILES.md). |
| Publications, followers, messagerie, store, commandes et droits | Contractuel seulement | Aucun de ces domaines, stockage, route, transaction, achat ou droit n'est implémenté ici. |

Le choix du rôle, du client confidentiel Me, de son secret et du flag Fans appartient à une configuration de site hors Git. Aucun site de production n'est configuré, activé, migré ou déployé par cette PR.

## Frontières des domaines métier

Fans sera propriétaire de ses profils créateurs, publications, relations de suivi, messagerie, catalogue de vente, commandes et droits locaux. Chaque personne aura un compte WordPress local et une liaison au `faluss_id` opaque ; ni l'adresse e-mail ni le `wp_user_id` d'un autre site ne serviront de clé de rapprochement ou de droit. Le client SSO Fans consomme le code d'autorisation à usage unique de Me, PKCE S256 et l'échange serveur à serveur selon le contrat Identity ; il n'émet pas d'identité ni de session centrale.

Hub conservera ses données et décisions propres. Fans intégrera les façades publiques validées de Federation, Events et Apps Registry sans copier leur transport, outbox, validation ou modèle de composition. Ces modules ne déclarent aujourd'hui que `me` et `hub` : leur éventuelle admission sur `fans`, leurs identités de nœud, leurs politiques de pair et leurs contrats de lecture devront être ajoutés et testés dans des PR séparées. Aucune dépendance implicite à leurs classes internes ou tables n'est autorisée.

Les contrats à spécifier avant le code métier sont : liaison SSO et session locale ; identité et autorisation du créateur ; publication et visibilité ; suivi et blocage ; messages et pièces jointes ; catégorie canonique du produit ; commande, remboursement et droit ; manifests Apps Registry ; événements versionnés et destinataires Federation. Chaque écriture devra définir son propriétaire, sa capacité, son nonce ou sa permission API, sa règle d'idempotence et son traitement du retrait ou de l'échec.

## Catégories et portes commerciales

| Catégorie canonique | Présentation permise | Achat et livraison dans ce lot | Condition d'ouverture future |
| --- | --- | --- | --- |
| Contenu autorisé hébergé par Fans | Catalogue et description conformes aux règles du site, après implémentation | Aucun parcours d'achat implémenté | Validation explicite du prestataire pour ce modèle de plateforme, intégration de paiement et recettes de bout en bout |
| Droit à une livraison adulte externe | Catégorie distincte, visible et classable, avec métadonnées non explicites seulement, après implémentation | Achat refusé ; aucun droit délivré ; contenu et livraison hors Fans | Acceptation **explicite** du prestataire pour ce parcours précis, puis PR et activation distinctes après validation |

L'absence de parcours commercial dans cette PR est le verrou actuel. Avant d'exposer la seconde catégorie, le service de commande devra imposer une règle centrale **refus par défaut** pour tout achat de droit à une livraison adulte externe : panier, checkout, création de commande, intention de paiement, API, action d'administration, webhook, reprise et émission de droit. Une requête forgée, un changement de catégorie ou une ancienne commande ne devront pas contourner ce refus. Un flag, une réponse client ou une simple validation du modèle général ne constituent pas l'acceptation du parcours adulte externe. La levée du refus exigera un changement serveur explicite, revu et testé après preuve de l'accord du prestataire.

Aucun contenu adulte ne doit être hébergé ou envoyé par Fans, y compris dans les publications, médias, fichiers, aperçus, messages et pièces jointes. Les contrôles d'upload, de texte, de rendu, d'API et de modération devront être définis avant toute ouverture. La catégorie du droit externe ne doit pas devenir un canal de stockage, de proxy, d'aperçu ou de transmission du contenu livré ailleurs.

## Étapes et preuves avant ouverture

1. **Fondation Fans** : le client SSO Me, les comptes locaux non privilégiés, les tests de rejeu et de session et un profil créateur structuré sont implémentés par deux PR dépendantes. Valider encore sur une instance WordPress et MariaDB de test le callback exact, les tables InnoDB, les routes REST, le parcours Me, l'approbation du profil et le retour arrière avant activation réelle. Les champs éditoriaux du profil exigeraient un contrat de modération distinct.
2. **Social et contenu** : profils, publications, followers et messagerie avec permissions côté serveur, blocage, retrait, rétention et garde contre tout contenu adulte. Tester les uploads, messages, API, accès directs, changements de visibilité et tentatives de contournement ; confirmer le comportement sur WordPress et les surfaces utilisées.
3. **Contenu autorisé hébergé** : obtenir la validation du prestataire pour ce modèle de plateforme ; terminer les contrats catalogue, commande, paiement, remboursement et droits, puis tester les doublons, webhooks, échecs, annulations et accès après révocation. Une recette de bout en bout et un rollback vérifié précèdent l'ouverture.
4. **Droits à livraison adulte externe** : présenter la catégorie sans achat possible ; prouver par tests serveur et API que tous les chemins de commande et de délivrance refusent. Obtenir l'acceptation explicite du prestataire pour ce parcours distinct. Toute ouverture commerciale sera un lot séparé avec revue du contrat de livraison externe, du refus par défaut, des contrôles de contenu et des parcours de paiement et de remboursement.

Les tests de la PR #53 démontrent la reconnaissance du rôle et l'isolation de ses hooks. Ceux des PR SSO et profils ajoutent des preuves unitaires. Une recette locale jetable WordPress/MariaDB a vérifié les tables, la création/liaison SSO directe et les permissions REST des profils ; l'échange réseau avec Me, le navigateur et les courses réelles restent à tester. Aucun test de vente n'existe encore puisqu'aucun service de vente n'est implémenté ; ce verrou devra être prouvé par tests serveur et API dans le lot commerce.
