# B1 — preuve privée d'ouverture d'origine

10 octobre 2026, arbre LF `f6e8cdf512956a7aed5334c17b553fc220e1d598`,
Composer exact ; WordPress 7.1.2/PHP 8.5.4/MariaDB 11.8.6, deux WordPress
et bases privées, clés fictives, racine POSIX 0700 et bail 0600.
HTTP uniquement en loopback, aucun site ou véritable compte/SSO.

```sh
python3 tests/TokenEngine/recipe/run.py --b1-origin-ack \
  --source "$SOURCE_ISOLEE" --core "$CORE_JETABLE" --cli "$CLI_JETABLE" \
  --output /var/tmp/fans-hof-b1-origin-checks.json
```

**417/417 contrôles**, dont 18 nouveaux pour le pont B1 et 14 pour la façade
d'acquittement. Préfixe propriétaire H0–H3/barrières 1.0/1.1 et reprise HTTP ;
H4, corpus et F1a conservent leurs recettes CI séparées. Fixture, bases et
serveurs détruits, rapport [expurgé](wordpress-checks.json).

- Préparation du registre sans origine ouverte, lecture de disponibilité
  sans installation ; schéma partiel refusé et installation explicite
  idempotente de deux tables InnoDB privées.
- Invité refusé ; préparations concurrentes retrouvent action et intervalle
  figés, aucun identifiant de reprise divulgué ou clé de remplacement.
- ACK requis avant ouverture, preuve primaire signée et confiance actuelle,
  instants exacts Hub/borne figée plutôt que compte, pack ou réception réseau.
- Faute d'écriture locale : rollback, preuve encore utilisable ; résultat
  inconnu après vrai COMMIT : lecture primaire retrouve l'ouverture et les
  mêmes instants/acteurs, sans second événement Hub.
- Révocation de clé, fermeture locale puis acquittement final interdisent
  de livrer l'origine comme active ; ancien ACK ne rouvre pas l'historique.
- Ledger officiel identique, aucun ledger PF Fans, source d'achat ou score.

PHPUnit complet **666 tests / 7 118 assertions**, HoF 55/116, PHPStan complet
cible PHP 8.3, zéro erreur et deux dépréciations historiques. Six PHP lintés,
deux Python compilés, scan ciblé, égalité LF et diff propres. Les gardes de
disponibilité/installation refusent WordPress ordinaire avant toute requête SQL.

Cette preuve concerne le service fermé d'administration de recette. Elle ne
valide pas les barrières de participation/Créateur/pays, choix d'intention,
source d'achat, lecture B6, vrai SSO ou admission/activation sur site. Aucun
score public, migration, flag, publication ou politique de conservation.
#150 et #161 restent distinctes ; aucune capture pour ce lot sans interface.
