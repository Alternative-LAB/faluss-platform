# B3c3b3 — persistance privée Fans 0.3

7 octobre 2026 ; arbre Git immuable exécuté
`5b2cb654567e728c22648e82a66a1638f103ff8e`, copie LF et verrou Composer exact.
WordPress 7.1.2, PHP 8.5.4, MariaDB 11.8.6 ; identités et clés fictives,
deux instances jetables, aucun site consulté.

```sh
python3 tests/TokenEngine/recipe/run.py --b3-ranked-inbox \
  --source "$SOURCE_ISOLEE" --core "$CORE_JETABLE" --cli "$CLI_JETABLE" \
  --output /var/tmp/fans-pf-b3-ranked-inbox-checks.json
```

**370 contrôles satisfaits, dont 31 nouveaux** après les 339 propriétaires et
réseau précédents. [Rapport expurgé](wordpress-checks.json) limité aux noms,
comptes et versions ; aucune identité, intention, clé, enveloppe ou base privée.
Les instances et bases sont supprimées en fin de recette.

- Installation explicite de quatre tables InnoDB, absence à l'activation,
  refus de schéma partiel ou non transactionnel.
- Six préparations concurrentes : une intention et une clé ; trois opérations
  avec des clés distinctes, stables, enregistrées avant émission HTTP.
- Récupération exacte dans un nouveau processus ; membre étranger, contexte
  modifié et empreinte corrompue refusés.
- Échec d'insertion, arrêt avant/après COMMIT et acquittement perdu : absence
  d'écriture partielle et aucune nouvelle clé pour contourner une incertitude.
- Réponse Hub perdue après consommation : lookup primaire sous la clé durable,
  un seul débit officiel ; échec d'écriture du reçu sans perte de l'intention.
- COMMIT local du reçu sans acquittement suivi de six rejeux : une seule preuve,
  enveloppe signée originale persistée et vérifiée ; signature altérée, autre
  intention, clé révoquée et preuve locale corrompue refusées sans réparation.

Les échanges sont transmis par le contrôleur Python entre deux WordPress.
La preuve concerne la persistance Fans et sa composition avec le gateway ;
**le client Fans intégré et la résolution du membre SSO restent à raccorder**.
Aucune preuve de TLS, infrastructure réelle, restauration/réplica, capacité de
production, achat ou score public. Le reçu d'origine n'est pas le score corrigé.

Suite PHP complète **650 tests / 6 987 assertions**, zéro échec, deux
dépréciations historiques. PHPStan complet cible PHP 8.3 ; HoF 51 / 105.
Quatre PHP lintés, trois Python compilés ; huit fichiers de runtime comparés
à l'arbre réellement exécuté. Liens, scan ciblé et diff check avant commit.
L'étape CI ajoutée ne constitue pas la seule preuve de sa propre modification.

Voir le [contrat fermé](../../modules/HUB-PF-B3-CLOSED.md). Aucun installateur
public, hook, route, flag, purge, score ou ledger PF Fans ajouté.
