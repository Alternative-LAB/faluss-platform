# ADR 0016 — Démarrer les profils créateurs avec des champs structurés

## Décision

Le premier profil créateur Fans contient un identifiant public opaque, une catégorie contrôlée et un état de publication approuvé par un administrateur. Il n'offre aucun champ libre, média, lien ou upload. Les lectures publiques filtrent exclusivement l'état `active`, qui ne représente pas une vérification d'identité ; l'API renvoie `identity_verified=false`. Le contrat et les routes sont détaillés dans [FANS-PROFILES.md](../modules/FANS-PROFILES.md).

## Motif

Une biographie ou un fichier transmis directement à Fans constituerait immédiatement un canal d'hébergement de contenu adulte interdit par le contrat du produit. Les catégories prédéfinies suffisent pour établir l'identité de domaine et tester la propriété, la publication et l'isolation sans avancer implicitement une politique de modération non validée.

## Conséquences

L'expérience reste volontairement minimale : les noms affichés, biographies et médias nécessitent un lot avec modération, retrait et tests d'API et de stockage. Le SSO Me prouve la possession du compte lié, sans vérifier l'identité du créateur ni accorder de droit métier ou commercial. L'approbation du profil est une décision locale de publication Fans ; une future vente devra imposer une vérification du créateur distincte.
