# B3c2c3b — lecteur borné du corpus Fans

Recette du 6 octobre 2026 sur copie LF immuable de l'arbre
e1675b304a729443edfedbdb0dc835e207fbdd51, avec les dépendances exactes
du verrou Composer. WordPress 7.1.2, PHP 8.5.4, MariaDB 11.8.6 ; deux
WordPress et bases distincts, socket SQL privé, racine POSIX 0700,
bail 0600 et clés fictives différentes. HTTP Hub en loopback seulement.

```sh
python3 tests/TokenEngine/recipe/run.py --b3-corpus-reader \
  --source "$SOURCE_ISOLEE" --core "$CORE_JETABLE" --cli "$CLI_JETABLE" \
  --output /var/tmp/fans-pf-b3-corpus-reader-checks.json
```

**548/548 contrôles WordPress/MariaDB**, dont **30 nouveaux** pour
le lecteur, après les 48 contrôles inbox, les 61 HTTP et les préfixes
propriétaires H0/H1/H2/H3, barrières, consommation 0.3, snapshots 2.0
et corpus. Les anciennes recettes HTTP H3/H4 et F1a restent des gates
CI séparés ; cette invocation ne les compte pas. Fixtures, bases et
serveurs détruits à la fin de la recette.

Suite PHP complète : **623 tests / 6 888 assertions**, aucun échec ou
erreur, deux dépréciations historiques ; HoF : 51 tests / 105 assertions.
PHPStan complet avec cible PHP 8.3 ; syntaxe des PHP/Python modifiés,
scan ciblé de secrets, liens relatifs et contrôle du diff.

## Preuves obtenues

- Une à seize étapes par appel, quatre par défaut ; budget invalide et
  WordPress ordinaire refusés sans écriture ni HTTP.
- Lookup primaire, start, pages puis finish raccordés au vrai client
  WordPress. ID/clé durables repris après chaque interruption. Aucun
  nouveau travail automatique pour contourner un refus signé.
- Enregistrement de la requête exacte avant chaque POST ; observation
  de l'absence de transaction/mutex Fans pendant HTTP.
- Quatre lecteurs concurrents, absence primaire retardée, curseurs
  persistés et pages uniques : pas de recul du checkpoint ni de corpus
  partiel promu. Un corpus vérifié renvoie son instant primaire ; un
  ancien corpus remplacé reste historique, sans restauration.
- Réponse Hub perdue après COMMIT réel, préparation locale acquittée
  de façon incertaine, perte de réseau, redirect refusé, signature
  altérée, HTTP 503 et clé révoquée : état pending, même travail/clé,
  reprise primaire après retour explicite de la capacité.
- Nouvelle consommation pendant la collecte : refus de la génération
  complète. Reconstruction explicitement demandée de 104 faits fictifs,
  net corrigé exact ; ancien refus incapable de masquer la nouvelle
  génération. Aucun HTTP supplémentaire pour un travail terminé.
- Toutes les écritures/claims historiques sont comparées ; aucune
  table de ledger économique Fans créée.

Les fautes réseau sont injectées dans l'adaptateur de recette ; la perte
de corps après COMMIT Hub passe par les vrais HTTP et MariaDB. Une
itération de développement de récupération HTTP n'a pas abouti lors
d'une analyse statique complète concurrente ; sa cause n'est pas
établie. Le rejeu isolé de 142 contrôles puis cette recette complète
immuable ont réussi. Le délai HTTP reste borné à huit secondes et un
résultat incertain est repris, jamais annoncé comme vérifié.

## Limites et retour arrière

La preuve porte sur **verified_at**, pas sur une fraîcheur continue
future. Aucun cron, route membre, schéma supplémentaire, score public,
expiration métier ou politique de rétention n'est introduit. Transport
des attributions 0.3/barrières et projections B4/B5 restent distincts.

Les horloges de quotas historiques sont accélérées uniquement dans la
recette ; les signatures utilisent l'horloge réelle. Mesures de verrous
sur petits corpus uniquement, sans validation du dimensionnement en
production. Ni véritable SSO Me, TLS, achat, compte réel, panne réelle
d'infrastructure, réplique, restauration ni site cible testés.

Retour arrière : retirer le lecteur de la recette privée, supprimer
son enclave ou revenir au parent. Aucune installation, migration, purge
ou flag de site ; #150 et #161 restent distinctes.

Voir le [contrat](../../modules/HUB-PF-B3-RANKING-CORPUS.md) et le
[rapport expurgé](wordpress-checks.json). Seuls intitulés de contrôles,
versions, mesures et totaux sont publiés : aucun fait détaillé, identité,
clé, requête, signature ou contenu privé.
