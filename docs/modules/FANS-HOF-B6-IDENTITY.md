# B6 — filiation locale des identités attestées

Lot serveur additif, rôle `fans`. Aucun contrat Hub nouveau, route, hook,
option, table, cron, migration ou flag. Aucune attribution, ouverture de score
public ou activation de classement. La source admissible reste le corpus
complet rapproché et attesté ; cette filiation ne le remplace pas.

## Responsabilités et lecture

Un fait Hub désigne un `faluss_id`, jamais le UUID local d'un profil Créateur.
`FansLinkedAccounts`, contrat privé du module SSO, résout uniquement le lien
déjà enregistré vers le compte WordPress local. Il ne rapproche pas par email,
ne crée pas de compte, ne choisit pas de destinataire d'API et ne transmet pas
les données du compte. Le module Profils résout ensuite le profil actif du
propriétaire. HoF compose ces contrats dans `RankingIdentity` : aucun SQL des
modules SSO ou Profils dans HoF, aucun dictionnaire transmis par un navigateur.

Ces lectures exigent **la transaction existante du primaire**, partagée par
les trois modules via la connexion WordPress courante. Elles ne démarrent,
ne valident ni ne réparent une transaction. Lien, existence de compte et méta
des rôles sont relus en SQL avec verrous InnoDB ; les caches WordPress ne sont
pas des preuves d'autorisation. Un rôle local exactement `subscriber` est
requis, conformément au SSO existant. Administrateur, éditeur, rôles multiples,
compte supprimé, lien absent, métadonnées absentes/ambiguës ou corrompues,
réplica et tables de comptes non transactionnelles échouent fermés.

`CreatorProfileService::activeIdForOwnerInTransaction` ne valide pas
l'éditorial ni le consentement. `RankingIdentity::visibleNameInTransaction`
vérifie séparément le pseudonyme Fan approuvé et son consentement révocable,
ou le profil actif, la dernière présentation approuvée et le consentement
Créateur. Une révision en attente/refusée ne remplace pas le nom approuvé ;
une suspension ou un retrait masque la présentation. Le Fan d'un Créateur
suspendu conserve sa filiation distincte, sans autoriser un profil public.

Les gardes existantes `activeOwner(..., true)` et `visibleFan` dans une
transaction utilisent aussi cette preuve primaire. Les lectures hors
transaction restent compatibles. L'ajout ne modifie ni passwordless, ni
échange de codes, cookie, durée des sessions, claims ou autorité Identity.
Une session SSO centrale réelle n'est pas simulée comme preuve d'identité.

## Scénarios et recette

Positifs : identifiants compte/profil distincts, liaison persistée, nom approuvé
et consentement, dernière présentation conservée pendant une révision,
réadmission, transaction appelante et rollback conservés.

Négatifs : UUID local confondu avec Faluss ID, identité inconnue ou email,
absence de transaction, connexion différente, rôle privilégié avec cache
WordPress périmé, suppression/retrait du lien, métadonnées ambiguës, MyISAM,
réplica, consentement retiré et présentation suspendue. Une suppression
concurrente du lien attend la lecture verrouillée ; après sa validation,
la lecture suivante refuse la filiation. Aucun fait économique n'est modifié.

La recette `admission-wordpress.py --test --hof-b6-visibility` couvre ces
scénarios sur un WordPress/MariaDB jetable, avec comptes et identités fictifs.
Le [rapport de filiation](../evidence/fans-hof-b6-identity/README.md) réussit
120 contrôles, dont 46 nouveaux B6id ; les ressources jetables sont nettoyées.
Les lignes classées utilisées pour vérifier le filtrage sont **synthétiques** :
elles ne prouvent pas la livraison finale depuis le Hub. L'énumération, le
corpus signé, les barrières et les projections sont vérifiés séparément dans
la [consolidation de main](../evidence/fans-hof-main-consolidation/README.md).
La jonction de ces primitives avec les autorisations de session et l'UI B6
reste le lot suivant ; aucun classement n'est testable sur les sites.

## Compatibilité et retour arrière

Pas d'administration ou modification visuelle : captures sans objet.
WordPress/Elementor et les applications `me`/`hub` conservent leurs parcours.
Aucune installation automatique de schéma. Retour arrière par revert de ce
lot après arrêt de ses futurs appelants, sans modifier liens SSO, profils,
consentements, faits Hub ou projections. #150 et RustFS #161 restent distincts.
