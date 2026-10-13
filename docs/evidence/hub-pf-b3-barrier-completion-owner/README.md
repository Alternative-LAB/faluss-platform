# B3b4b — clôture à échéance sur le primaire, preuve fermée

Copie Git immuable `e86b9a48248fd1db4472cbc28bb4ed5827e23419`, 10 octobre 2026,
avant ajout de cette preuve. WordPress 7.1.2 / MariaDB 11.8.6 / PHP 8.5.4
jetables, données et preuves fictives uniquement. Aucun site, pair réel ou flag.

- `run.py --b3-barrier-completion` : **309 contrôles**, dont **37 nouveaux**,
  zéro échec. [Rapport expurgé](wordpress-checks.json), fixture détruite.
- Heure primaire avant/à/après échéance, mauvais type/origine/politique/permission,
  contexte expiré avant/après attente, huit rejeux concurrents et audit atomique.
- Fermeture en attente d'une sélection propriétaire : l'instant est pris après
  acquisition des lignes, à l'échéance figée ; nouvelles sélections ensuite refusées.
- Erreur d'audit, COMMIT sans acquittement et processus tué avant/après COMMIT :
  lookup sur la même action/clé, absence ou clôture effective sans second événement.
- Annulation anticipée conservée ; aucun gagnant ou conversion vers fin normale.
  Ledger officiel, anciens claims, reçus et journaux comparés byte à byte.
- Suite complète : 662 tests / 7 076 assertions, HoF 51 / 105 ; zéro échec,
  deux dépréciations historiques. PHPStan complet cible PHP 8.3 satisfait.

La CI exécute le nouvel argument **en plus** des scénarios propriétaires
existants ; aucune gate retirée. L'heure de connexion est contrôlée uniquement
dans le worker privé pour tester la frontière exacte ; elle n'est pas une entrée
du protocole ni une date choisie par le navigateur.

Cette preuve est propriétaire SQL, sans HTTP 1.1, reprise Fans 1.1, véritable
SSO ou cycle B2 raccordé. Les résultats corrigibles après clôture sont traités
par H4/B4/B5, pas par une écriture économique dans cette primitive. Aucun
déploiement, migration, purge de production, score public ou ouverture réelle.
#150 et #161 restent distinctes.
