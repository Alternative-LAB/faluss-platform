# Demande de profil créateur — UI native

## Scénarios du lot

Un Fan déjà lié au SSO Me peut choisir une catégorie dans Mon espace et demander
son profil via `POST /creators/me`. Le résultat reste `pending` jusqu’à décision
administrative. Rejouer la même catégorie retrouve le profil ; une autre catégorie
retourne un conflit. Aucun nom, portrait, badge vérifié ou rôle WordPress privilégié
n’est créé. Un profil existant mène à sa page de gestion distincte.

Refus : invité, compte non lié ou administrateur, nonce absent/faux, catégorie
inconnue, schéma/flag/API indisponible, erreur réseau/stockage ou conflit. Le formulaire
ne propose pas d’approbation, de suspension ni de choix d’identité propriétaire.
Le serveur REST demeure l’autorité ; le nonce UI précède son nonce REST interne.

Le parcours fonctionne sans JavaScript. Les erreurs sont rendues avec leur HTTP,
le choix est conservé et aucune requête n’est rejouée automatiquement. Une nouvelle
lecture confirme le profil courant ; un 404 de route absente n’est pas assimilé
à « aucun profil » et n’ouvre pas le formulaire. Les captures utilisent des fixtures
et ne prouvent pas le rendu ou le SSO d’un site WordPress.

## Limites

Cette demande ne vérifie pas l’identité, n’ouvre pas une vente et ne garantit pas
une approbation. L’administration des statuts reste le contrat existant. La progression
Fan PC demeure indisponible, séparée du Classement Fans. Aucun flag, schéma, compte
réel, migration ou release dans ce lot. Voir [le contrat Profils](FANS-PROFILES.md).
