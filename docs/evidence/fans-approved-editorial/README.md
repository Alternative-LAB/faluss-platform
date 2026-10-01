# Dernière présentation approuvée — preuves du lot

WordPress 7.1.2, Twenty Twenty-Five, PHP 8.5.4, MariaDB 11.8.6, Chrome
154.0.8037.58. Installation jetable, comptes locaux de recette liés par fixture,
aucun SSO cible ou appel de production. Les origines des actions des formulaires
produit sont ramenées à la boucle locale dans le navigateur ; nonce, champs et
traitements WordPress de production sont conservés.

- 311 tests PHPUnit / 4 945 assertions ; PHPStan sans erreur, lint PHP et
  diff-check réussis ; deux dépréciations préexistantes.
- Recette SQL/HTTP complète : profils, images, publications, messages et
  signalements passent. Maintien du portrait approuvé exact pendant modification
  et refus ; retrait d'image et suspension révoquent sa lecture. Migration v1
  vers v2 conserve la seule version effectivement approuvée, avec sa révision.
- 82 vérifications d'admission sur vrai WordPress/MariaDB réussies, dont
  permissions, décisions concurrentes, rollback et visibilité publique.
- [Recette navigateur](browser.json) : vraie soumission du propriétaire, refus
  natif du modérateur sans perte de la version publiée, retrait propriétaire
  après refus, révocation HTTP concurrente (un 200 / un 409), aucune
  auto-approbation ni retrait par un autre compte.
- [Propriétaire ordinateur](owner-1440.png), [mobile](owner-390.png),
  [modération ordinateur](moderation-1440.png), [mobile](moderation-390.png) :
  1440 × 900 et 390 × 900 ; aucun débordement horizontal.

Les textes de test portent des marqueurs explicites de fixture ; la version
initialement nommée « private pending » a réellement été approuvée par le test
d'admission avant ces captures. Les captures montrent le panel WordPress
existant ; elles ne prétendent pas livrer le futur back-office unifié ni les
en-têtes compacts, prévus dans les lots suivants. Le Gravatar administratif est
bloqué par l'absence d'accès réseau externe de la recette.
