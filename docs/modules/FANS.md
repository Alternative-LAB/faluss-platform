# Faluss Fans — admission et contrats à construire

## État de ce lot

`FALUSS_PLATFORM_ROLE='fans'` est admis par le socle. Seule l'administration commune se charge. Aucun module Fans, route, shortcode, table, option métier, cron, paiement, contenu ou droit n'est créé. Les flags des modules Me et Hub ne rendent pas ces modules compatibles avec Fans. Le hook d'activation Link doit ignorer Fans même si `FALUSS_PLATFORM_LINK === true` par erreur. Le choix du rôle appartient à la configuration du site, hors Git ; cette PR ne configure ni n'active un site réel.

## Frontières des lots suivants

Fans sera propriétaire de ses profils créateurs, publications, relations de suivi, messagerie, catalogue de vente, commandes et droits locaux. Chaque personne aura un compte WordPress local et une liaison au `faluss_id` opaque ; ni l'adresse e-mail ni le `wp_user_id` d'un autre site ne serviront de clé de rapprochement ou de droit. Le client SSO Fans consommera le code d'autorisation à usage unique de Me, PKCE S256 et l'échange serveur à serveur selon le contrat Identity ; il n'émettra pas d'identité ni de session centrale.

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

1. **Fondation Fans** : client SSO Me, comptes locaux non privilégiés, séparation des profils et rôles, contrôle des collisions de hooks/routes/tables/options, tests de rejeu et de sessions. Valider sur une instance WordPress de test ; définir le rollback avant activation.
2. **Social et contenu** : profils, publications, followers et messagerie avec permissions côté serveur, blocage, retrait, rétention et garde contre tout contenu adulte. Tester les uploads, messages, API, accès directs, changements de visibilité et tentatives de contournement ; confirmer le comportement sur WordPress et les surfaces utilisées.
3. **Contenu autorisé hébergé** : obtenir la validation du prestataire pour ce modèle de plateforme ; terminer les contrats catalogue, commande, paiement, remboursement et droits, puis tester les doublons, webhooks, échecs, annulations et accès après révocation. Une recette de bout en bout et un rollback vérifié précèdent l'ouverture.
4. **Droits à livraison adulte externe** : présenter la catégorie sans achat possible ; prouver par tests serveur et API que tous les chemins de commande et de délivrance refusent. Obtenir l'acceptation explicite du prestataire pour ce parcours distinct. Toute ouverture commerciale sera un lot séparé avec revue du contrat de livraison externe, du refus par défaut, des contrôles de contenu et des parcours de paiement et de remboursement.

Les tests de cette PR démontrent seulement la reconnaissance du rôle, l'administration locale et l'isolation des modules et hooks d'activation. Ils ne valent ni recette WordPress réelle, ni accord du prestataire, ni autorisation de vente ou de déploiement.
