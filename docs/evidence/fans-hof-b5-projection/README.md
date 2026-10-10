# B5a — calcul privé des sessions, preuve pure

Copie Git immuable `c8d852cb002602dd38b4ad8d6ab224f9dd2b04ac` du 10 octobre 2026,
avant ajout de cette preuve et correction d'un lien documentaire vers la branche
indépendante du codec 1.1. Aucun runtime ou source PHP modifié après la copie.

- `RankingSessionProjectionTest` : **9 tests / 78 assertions**, zéro échec.
- HoF : 69 / 239 ; suite complète : 641 / 7 073 ; zéro échec et deux
  dépréciations historiques. PHP 8.5.4 ; PHPStan complet cible PHP 8.3 satisfait.
- Une attribution dans dix sessions, trois portées, réadmission, corrections
  après échéance, litige/résolution, égalités Hub et absence de rattachement
  rétroactif vérifiés. Corpus tronqué, doublon, PC et règles figées contradictoires
  refusés. Aucune donnée de test n'est livrée dans une interface publique.

Le test ne prouve ni stockage B5b, ni admission actuelle, visibilité publique,
signature réseau ou SSO. La [recette B4b](../fans-hof-b4-cache/README.md) appartient
au parent ; elle n'est pas déclarée comme une recette SQL de ce nouveau calcul.
Aucun ledger, route, migration, cron, flag, session réelle ou classement actif.
