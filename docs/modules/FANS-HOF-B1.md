# B1 — fondations des règles HoF / classement Fans

## Autorisation et séparation des livraisons

Les règles [F1b R2](FANS-PF-F1B-PROPOSAL.md) sont validées le 6 octobre 2026.
B1 est scindé en code mathématique/calendriers (B1a), puis registre persistant
des dimensions, origine, consentements et pseudonymes (B1b). B2 suit pour les
sessions. Aucune capacité cible n'est supprimée par ce découpage.

Le [nouveau contrat Hub B3](HUB-PF-B3-RANKING-PROPOSAL.md) a reçu son accord
distinct pour la recette fermée, pas la production. Ces classes ne modifient ni H3/H4, ni ledger, claims, PF/PC ou sessions
WordPress. Aucun bootstrap, REST, cron, flag, migration ou score public.

## B1a — calendriers et calcul pur

`RankingPolicy` conserve les règles/version, catégories existantes, trois portées
et plafonds 90 / 3 / 10. `RankingCalendar` fournit :

- relevé civil **Europe/Paris**, bornes UTC, été/hiver et année bissextile ;
- rattachement à la confirmation d'origine, intervalle début inclus / fin exclue ;
- durée de session au plus 90 × 24 heures entre instants UTC, sans assimiler
  la session au mois ; fuseau IANA de programmation explicite ;
- conversion de programmation locale refusant les heures inexistantes ;
  sélection d'occurrence par décalage UTC explicite pour une heure ambiguë ;
- précision à six décimales, aucun horodatage du navigateur ou réseau.

L'origine réelle, les décisions territoriales et les pays autorisés ne sont pas
inventés par une constante ou un réglage de recette.

### Calcul et format interne

`RankingFacts` est un **calculateur pur**, pas un validateur de signature ni un
nouveau format de transport Hub. Entrées : sources canoniques **complètes et déjà
authentifiées** par le futur adaptateur B3/B4, une révision entière par membre,
une époque propriétaire d'ordre et des attributions regroupées multi-lots.

Chaque attribution comprend identifiants immuables, original/net PF, confirmation
UTC et ordre Hub. Ce format interne ne ratifie ni ne fournit l'autorité de ces
champs : les preuves H3/H4 actuelles n'en fournissent pas tous et restent inchangées.
Aucun appel normal de l'application ne peut donc annoncer un classement avec B1a.

Reconstruction déterministe : dernière révision complète de chaque source,
identités immuables et unicités, exclusion du net nul, sommes distinctes par Fan
et Créateur. À même score : dernière contribution encore positive, confirmation
et ordre Hub, puis identité immuable. Places uniques. Revisions plus anciennes
ne restaurent ni points ni avantage d'ancienneté. Conflits, source qui perd une
attribution connue, ordre absent/dupliqué ou époque étrangère : refus fermé.

Les résultats internes contiennent les références nécessaires au rapprochement ;
ils ne sont pas une réponse publique. Consentement, alias approuvé, permission,
origine et corpus exhaustif/à jour sont des portes B1b/B3/B4/B6, pas inférés ici.
La catégorie/session impose son contexte attesté avant son calcul B4/B5.

## Scénarios écrits avant implémentation

Positifs : mars 2026 (743 h), octobre 2026 (745 h), année bissextile ; bornes
exactes et microsecondes ; occurrences automne explicites ; égalités/places uniques ;
remboursements partiels/totaux ; retrait de l'avantage d'une contribution annulée ;
litige/résolution au seul net restant ; sources désordonnées/rejouées ; même fait
pour les projections Fan/Créateur sans consommation additionnelle.

Négatifs : date invalide, heure inexistante/ambiguë sans occurrence, faux offset,
durée supérieure à 90 jours ou nulle ; ordre Hub absent/dupliqué, époque mixte,
faits/identités/revisions contradictoires, source incomplète, net négatif/excessif,
auto-attribution, PC/champs étrangers, pack seul/net zéro sans place.

Les tests mathématiques utilisent uniquement des sources fictives en mémoire.
Ils ne prouvent pas encore l'attestation d'ordre, la provenance ou le réseau Hub,
le véritable SSO ou un classement complet. Les tests historiques restent distincts.

## Retour arrière et exploitation

Classes inertes et sans données persistées en B1a : revert du lot sans migration.
B1b conserve une installation explicite/testable et un retour arrière propre. Aucun
appel ne réécrit les ledgers, claims ou anciennes projections F1a. Conservation
#150 et stockage RustFS #161 distincts. Aucune activation réelle par publication.

## B1b — scénarios écrits avant l'implémentation

Registre privé Fans : dimensions versionnées (général, catégorie, mois), origine
préparée **sans ouverture réelle**, pseudonyme soumis/approuvé et consentements
Fan/Créateur séparés. Aucune origine implicite à l'installation ou à la première
visite. Le futur adaptateur attesté enregistrera l'ouverture réelle ; B1b n'offre
aucune action économique ou ouverture d'attribution.

Positifs : installation explicite idempotente sur InnoDB ; dimensions stables et
mois Paris ; soumission/modération du pseudonyme ; dernière version approuvée
pendant une révision ; consentement explicite puis retrait immédiat ; décision
et journal dans la même transaction ; relecture après redémarrage.

Négatifs : invité, compte non lié ou privilégié comme membre ; modification d'un
autre membre ; approbation sans permission ; fausse révision et décisions
concurrentes ; alias non approuvé/publication sans consentement ; schema divergent
ou non transactionnel ; installation pendant une transaction ; origine remplacée
ou ouverture réelle inventée. Les pseudonymes sont éditoriaux Fans, jamais un
changement de compte Faluss Identity.

### Livraison du registre privé

`RankingSchema` installe explicitement cinq tables propres, vérifie colonnes,
index et InnoDB ; une installation partielle/divergente ou imbriquée est refusée.
Création sur tables temporaires uniques puis renommage atomique. Aucun appel
depuis le bootstrap, l'activation ou une visite normale ; pas de migration de site.

`RankingRegistry` prépare une origine immuable à politique versionnée, puis des
dimensions général/catégorie/mois aux bornes Paris persistées. L'état reste
`prepared`, **sans date d'ouverture réelle**. Aucun calcul ne peut transformer
cette préparation en attributions admissibles. L'enregistrement de la future
ouverture attestée sera un adaptateur spécifique ; aucune origine par défaut.

`RankingVisibility` : propriétaire SSO local normal seulement, révision attendue
obligatoire, consentements Fan/Créateur distincts, modération `manage_options`,
retrait immédiat, contrôle actif/présentation à chaque livraison Créateur. Alias
simple de 2–80 caractères, vingt soumissions par heure, aucun repli sur le login
technique ou les données Identity. Une révision en attente/rejetée conserve la
dernière version approuvée, jamais son texte nouveau. Le retrait/révocation
efface l'alias public ; le retrait du consentement masque sans modifier les faits.

Chaque écriture membre et son journal sans texte libre sont dans la même
transaction, sous verrou par membre et révision ; une décision concurrente est
refusée. Les interfaces HTTP/formulaires avec nonce appartiendront aux adaptateurs
B6. Ces classes ne sont pas enregistrées comme routes publiques ou actives.

Recette reproductible :

```sh
python3 tests/Fans/Profiles/recipe/admission-wordpress.py \
  --source "$SOURCE_ISOLEE" --core "$CORE_JETABLE" --cli "$CLI_JETABLE" \
  --test --hof-b1 --output /var/tmp/fans-hof-b1-checks.json
```

WordPress réel et MariaDB privés, comptes locaux/filiations SSO fictifs injectés
pour la recette. Cela ne prouve pas une connexion centrale sur Me. Aucune requête
aux sites, score public, API Hub, flag de production ou donnée réelle.

Les [preuves B1b](../evidence/fans-hof-b1/README.md) recensent cinquante vérifications,
dont permissions, décisions concurrentes, rollback sur panne du journal,
retrait/suspension et divergence des index. La CI rejoue la recette sur chaque PR.

Retour arrière : revert des classes/adaptateurs ; conserver les tables privées
pour réinstallation compatible, sans DROP automatique ni purge. Politique réelle
de rétention #150 à décider avant activation. Aucun défaut de 24 mois ajouté.
