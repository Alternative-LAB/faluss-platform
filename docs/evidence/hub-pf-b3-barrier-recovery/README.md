# B3b3e fermé — reprise durable des barrières Fans

Recette du 10 octobre 2026 : deux WordPress 7.1.2 jetables, MariaDB 11.8.6,
PHP 8.5.4, WP-CLI 2.12.0. Pairs, clés et données fictifs ; racine POSIX 0700,
bail 0600 et socket primaire privé. Aucun compte ni site réel, aucun flag.

```sh
python3 tests/TokenEngine/recipe/run.py --b3-barrier-http \
  --source "$SOURCE" --core "$WP_CORE" --cli "$WP_CLI" \
  --output "$PRIVATE_OUTPUT/barrier-recovery-checks.json"
```

**336 contrôles satisfaits, dont 33 nouveaux de reprise**, après les 303 du
gateway. [Rapport expurgé](wordpress-checks.json) : noms et compteurs, versions,
empreinte du service historique, limites et destruction de la fixture. Aucune
clé, identité, requête signée ou donnée privée publiée.

Préparations simultanées : une action et une clé durables. Close pendant une
ouverture incertaine : passage local immédiat à closing, rapprochement register
avant close, acquittement primaire obligatoire. Quatre échanges WordPress HTTP
hors transaction/mutex et après enregistrement de chaque requête. Aucun ancien
acquittement register ne réouvre closing/closed.

Corps HTTP réellement perdu après COMMIT register/close : reprise par lookup,
mêmes action/clé, aucun événement Hub supplémentaire. Erreurs d'insertion,
COMMIT local sans acquittement et arrêt réel des processus avant/après COMMIT :
absence ou action complète sur le primaire, jamais état partiel. Rejeux, clé
Hub révoquée, restauration contradictoire, état active sans preuve et MyISAM
refusés. Les écritures PF restent byte-identiques ; aucun ledger Fans ajouté.

Arbre immuable exécuté **9332069faec5d81e4fcb905e2f6114cd2697fab0** : huit
sources de runtime comparées au checkout final. Suite PHP complète **657 tests /
7 048 assertions**, zéro échec et deux dépréciations historiques ; HoF 51 / 105,
PHPStan complet cible 8.3 sans erreur. Syntaxe des cinq PHP et trois Python
vérifiée. CI du head final requise avant fusion ; cette preuve de branche n'est
pas celle du code finalement intégré à main.

La première recette a détecté un défaut de l'injecteur : l'erreur d'insertion
était consommée au COMMIT de la lecture préalable. L'injecteur corrigé conserve
ce défaut jusqu'à l'insertion visée ; la recette complète ci-dessus est verte.
Ce résultat ne doit pas être présenté comme un échec du protocole Hub.

Limites : le serveur de recette compose les champs, sans véritable SSO.
Raccordement aux décisions B1/B2 et à la sélection d'attribution, concurrence
réseau confirmation/fermeture, production/TLS, réplica et restauration
incohérente non attestés. Aucun endpoint membre ou bootstrap de site ajouté ;
aucun classement testable sur les sites avant raccordement et publication.
Retour arrière : retirer les composants inertes et détruire la fixture ;
aucune migration de site. #150 et #161 restent distinctes.
Voir le [contrat approuvé et les limites](../../modules/HUB-PF-B3-BARRIER-TRANSPORT.md).
