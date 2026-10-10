# B3c2c2 — corpus HTTP fermé, clés de nœuds distinctes

Recette du 6 octobre 2026 sur copie LF immuable de l'arbre
be0c5e63723e3da304666729d21b90bf3a467a0a et dépendances du verrou Composer exact.
WordPress 7.1.2, PHP 8.5.4, MariaDB 11.8.6 ; primaire sur socket privé,
racine POSIX 0700 et bail 0600, deux WordPress et bases séparées, clés
fictives différentes. HTTP Hub uniquement en loopback ; Fans utilise la
véritable API HTTP WordPress depuis son contexte CLI physiquement isolé.
Aucune route globale n'est accordée à un membre ou navigateur.

```sh
python3 tests/TokenEngine/recipe/run.py --b3-corpus-http \
  --source "$SOURCE_ISOLEE" --core "$CORE_JETABLE" --cli "$CLI_JETABLE" \
  --output /var/tmp/hub-pf-b3-corpus-http-checks.json
```

**470/470 contrôles WordPress/MariaDB**, dont **61 nouveaux** pour ce lot.
Le préfixe vérifie H0/H1/H2/H3 propriétaire, barrières, consommation 0.3,
snapshots membres 2.0 et corpus. Les anciennes recettes HTTP H3/H4 et F1a
restent des contrôles CI séparés ; cette invocation ne les compte pas.
Fixture, serveurs et bases détruits à la fin.

Suite complète **621 tests / 6 851 assertions**, aucune erreur ou échec,
deux dépréciations historiques ; 51 tests HoF / 105 assertions. PHPStan
complet avec cible PHP 8.3, syntaxe des huit PHP et Python modifiés,
liens relatifs, scan ciblé de secrets et contrôle du diff.

## Preuves obtenues

- Origine non admise refusée même avec demande signée ; origine admise
  vide, manifeste exact et permission globale dédiée sur les deux nœuds.
- Signature extérieure/contexte, audiences, origine/politique liées,
  clés inconnues/révoquées/différentes, dates futures/expirées, ancien
  domaine, opération économique et destinataire ajouté refusés.
- Nonce durable distinct ; quatre requêtes concurrentes admettent une
  seule demande ; expiration pendant l'attente réelle du mutex refusée.
- URL exacte de loopback, port valide, aucune redirection suivie, GET
  incapable de muter/lire ; réponses privées sans cache navigateur/CDN.
- Réponse perdue **après le vrai COMMIT de matérialisation** : résultat
  inconnu côté client, puis lookup primaire sous la même clé et nouveau
  nonce ; génération et première page identiques, sans nouveau corpus.
- 101 attributions réellement consommées par les primitives propriétaires
  fictives, pages 100 + 1 signées et uniques. Fence complète ; autre
  curseur/origine refusé sans faits inventés.
- Nouvelle consommation et correction H4 rendent l'ancienne fence
  indisponible. Rapprochement partiel bloque toute lecture exacte ;
  annulations conservées et ordre Hub original préservé. Pages historiques
  immuables, aucun ancien fait ne prétend redevenir courant.
- Schéma partiel/MyISAM refusé, aucune installation automatique. Claims,
  lignes historiques des ledgers et service historique inchangés. MU
  loader copié sur WordPress ordinaire : zéro route, zéro requête SQL.

## Limites

La clé est fournie par le propriétaire de la reprise ; l'inbox qui la
persistera avant HTTP et remplacera atomiquement le corpus reste le lot
suivant. Ce transport ne prouve pas une réception durable exhaustive Fans,
un classement public ou une fraîcheur continue.

Horloge SQL accélérée uniquement dans la recette pour les quotas
historiques ; signatures sur horloge réelle. Mesures des verrous pour
101/102 faits publiées sans extrapolation de capacité de production.
Aucun vrai SSO Me, achat, compte réel, TLS, panne d'infrastructure réelle,
réplique/restauration, admission de production ou politique de conservation
n'est attesté. Aucun site, flag, migration de production ou activation.

Voir le [contrat](../../modules/HUB-PF-B3-RANKING-CORPUS.md) et le
[rapport expurgé](wordpress-checks.json). Aucune clé, signature, requête
privée, identité ou fait détaillé n'est publié.
