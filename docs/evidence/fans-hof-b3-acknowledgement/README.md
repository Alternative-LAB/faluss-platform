# Acquittement courant fermé — preuve de branche

Tree testé `399c65f3faac1b65bef52136528832d07f224904`, copie LF
immuable et verrou Composer exact. WordPress 7.1.2, MariaDB 11.8.6,
PHP 8.5.4, deux bases et clés de nœuds fictives distinctes, loopback privé.

```sh
python3 tests/TokenEngine/recipe/run.py --b3-barrier-acknowledgement \
  --source "$SOURCE_ISOLEE" --core "$CORE_JETABLE" --cli "$CLI_JETABLE" \
  --output /var/tmp/fans-barrier-ack-final-checks.json
```

**399 contrôles / zéro échec**, dont **14 nouveaux** ; fixture détruite.
Les 385 précédents sont conservés. Suite complète **662 tests / 7076 assertions**,
HoF 51 / 105, PHPStan cible 8.3 sans erreur ; deux dépréciations historiques.
Deux PHP lintés, scripts Python compilés, liens et scan ciblé de secrets,
`git diff --check`.

Application sous transaction de champs exacts et du résultat primaire :
ouverture 1.0, annulation, fin 1.1, rollback du callback et COMMIT incertain.
Préparation en attente, autre origine, preuve hachée altérée, confiance révoquée
et transaction imbriquée refusées avant toute décision locale. Ancien ACK
d'ouverture pendant fermeture : état closing/closed conservé. Aucun second
événement Hub, clé ou wire transmis au callback, ni modification économique.

Le premier rapport avait repris le préfixe des 21 tests d'admission et comptait
35 au lieu des 14 nouveaux. Le préfixe est maintenant distinct et la recette
complète a été rejouée ; ce rapport final conserve les compteurs exacts.

La façade n'est pas encore le raccordement B1/B2, un parcours SSO central ou
un classement de site. Ces résultats appartiennent à la branche, pas à main.
Aucune installation, migration, activation, politique #150 ou adaptation
RustFS #161. Voir le [contrat](../../modules/HUB-PF-B3-SESSION-COMPLETION.md).
