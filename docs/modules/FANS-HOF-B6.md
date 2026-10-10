# B6 — lectures autorisées et présentation

## Première primitive : visibilité des places

`RankingVisibleProjection` transforme une famille déjà reconstruite en lignes
affichables. Ce calcul pur exige l'ordre corrigé original et sa forme exacte ;
doublons, quantité nulle, positions incohérentes et ordre altéré sont refusés.
Le fournisseur serveur doit auparavant authentifier le corpus courant complet,
vérifier l'origine ouverte et les autres barrières nécessaires à sa livraison.

Pour chaque identité opaque, le fournisseur de visibilité rend un nom approuvé
ou `null`. Les seuls profils visibles reçoivent des places publiques successives,
dans le même ordre économique corrigé. Un rang masqué n'est pas divulgué par un
trou dans les places. Points d'une identité masquée, UUID, dates d'atteinte et
ordre technique Hub ne sortent pas du calcul. Aucun score n'est recalculé ni
consommé ; une exception de visibilité interrompt toute la réponse.

Ce contrat local n'est pas une nouvelle preuve ou API Hub. Une fonction fournie
à ce calcul n'autorise pas un navigateur à choisir un membre ou un nom. Le futur
adaptateur utilisera la filiation SSO et `RankingVisibility`, sans repli sur le
login WordPress ou les données Identity.

## Scénarios et preuve

Positifs : filtrage, places uniques parmi les visibles, égalités selon ordre Hub,
retrait/rétablissement de consentement, dernière présentation approuvée pendant
révision, suspension/réadmission et liste véritablement vide.
Négatifs : pseudonyme en attente ou révoqué, absence d'accord, données techniques
ajoutées, origine de classement tronquée/réordonnée, alias vide et faute de lecture.

La [recette isolée](../evidence/fans-hof-b6-visibility/README.md) vérifie les états
de consentement et de modération sur WordPress/MariaDB réel : 67 contrôles dont
17 nouveaux. **Les lignes classées de ce sous-lot sont des entrées synthétiques**,
pas un score Hub attesté. La recette ne constitue pas une lecture complète B6.

## Raccordements suivants

Origine/acquittements B1/B2, choix d'attribution, source primaire à jour, mapping
des filiations et visibilité doivent être composés avec B4/B5 avant livraison.
Les relevés mensuels resteront privés, liés au membre courant ; les tableaux
Créateurs et Fans sont distincts et les sessions ne deviennent pas un relevé.
La présentation réutilisera les routes, composants et DA V2 validés ; aucun
écran, REST, flag, activation ou score public n'est ajouté par cette primitive.

Un classement incomplet ne doit jamais être présenté comme exact. Publication,
vrai SSO, source d'achat et admission réelle restent distincts ; #150 et #161
sont indépendantes. Retour arrière : retirer ces classes sans migration ou purge.
