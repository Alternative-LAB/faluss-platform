# B2 — sessions Créateur, gouvernance privée

Autorisation : [F1b R2](FANS-PF-F1B-PROPOSAL.md) et accord B3 fermé distinct.
B2a livre sessions/invitations/participation/dates ; B2b ajoute l'admission
territoriale versionnée et les contrôles des portées locale/nationale. B3 apporte
les acquittements propriétaires, pas une nouvelle règle produit.

## Scénarios écrits avant implémentation

Positifs : Créateur actif lié et présentation approuvée crée un brouillon ;
coorganisateur accepte explicitement ; organisateur peut demander sa propre
participation ; participants individuels ; programmation UTC/fuseau IANA ; règles
figées au passage en ouverture ; trois réservations d'ouverture par organisateur,
coorganisation comprise ; admission tardive horodatée côté serveur ; fermeture
locale immédiate des nouveaux choix et état en cours jusqu'à preuve Hub.

Négatifs : invité, Fan sans profil actif, compte privilégié ou non lié ; action sur
une session étrangère ; faux rôle, revision obsolète, règles non acceptées ;
quatrième ouverture concurrente ; coorganisation non acceptée ; doublon de
participation ; modification des règles/dates/catégorie/portée après gel ; durée
supérieure à 90 jours ; portée territoriale sans examen/politique publique ;
faux acquittement Hub ou reprise silencieuse d'une session annulée.

## États et frontières

`draft` → `opening` → `open` → `closing` → état final (`closed`, `cancelled`,
`suspended`). B2 ne fournit **aucun passage local non attesté de opening à open**.
Les nouveaux choix sont interdits dans opening/closing. Après résultat incertain,
le futur adaptateur B3 conserve la même clé et interroge le primaire ; pas de
nouveau contexte pour contourner le timeout. L'histoire antérieure reste intacte.

Le plafond de trois réserve une place dès la demande d'ouverture (`opening`) et
la conserve pendant `open`/`closing`. Cela empêche deux demandes concurrentes ou
un acquittement perdu de dépasser le plafond. Une session programmée dans le futur
compte dès sa demande ; fermeture finale ou échéance effective libère la place.
Les règles figées ne changent jamais par réadmission/modération.

Le schéma propre et son installation sont explicites, sans bootstrap, route,
flag ou migration automatique. Les lectures privées/API/UI se raccordent en B6.
Aucun score, PF consommé, wallet, PC, prix, achat, gagnant ou récompense.

Une demande de participation peut être instruite pendant `opening` pour préparer
les admissions ; cela n'autorise aucune sélection PF. Les états `opening` et
`closing` restent exclus de la future liste de choix d'attribution.

## Livraison B2a et preuve

Quatre tables InnoDB propres : sessions, rôles, décisions et version de schéma.
Les transactions courtes de gouvernance utilisent un verrou commun au domaine
des sessions pour vérifier les quotas de tous les organisateurs sans course.
Révision attendue, décision et journal atomiques ; aucune transaction imbriquée
avec les modules de profil/présentation ou le ledger Hub.

Le propriétaire édite le brouillon et programme l'ouverture. Les coorganisateurs
acceptés instruisent les participations ; une invitation seule ne donne aucun
pouvoir. Un organisateur n'est jamais inscrit automatiquement comme participant.
Les lectures du participant exposent ses seuls rôles, pas ceux des autres.
Retrait et lecture privée restent possibles après suspension du profil.

La limitation technique de création est vingt brouillons par Créateur et par
heure ; elle ne change pas le plafond produit de trois ouvertures simultanées.
Les trois portées sont conservées, mais B2a refuse une ouverture locale/nationale
en l'absence d'admission territoriale examinée. B2b ajoute cet examen et les recours.

Recette :

```sh
python3 tests/Fans/Profiles/recipe/admission-wordpress.py \
  --source "$SOURCE_ISOLEE" --core "$CORE_JETABLE" --cli "$CLI_JETABLE" \
  --test --hof-b2 --output /var/tmp/fans-hof-b2-checks.json
```

Les [preuves B2a](../evidence/fans-hof-b2/README.md) distinguent WordPress/MariaDB
jetable avec filiations SSO locales fictives d'une connexion centrale Identity.
Ces services privés sont effectivement persistés et testés ; ils ne constituent
pas encore un parcours UI, une ouverture économique ou une preuve de production.
La CI rejoue les scénarios, sans exposition HTTP de recette.

Retour arrière : revert des classes et adaptateurs, conservation des tables
privées pour reprise compatible. Aucun DROP automatique, cron ou nouvelle durée
de rétention ; #150 reste distincte.

## Dépendances conservées

- B2b : territoire principal déclaré/examiné, critères publics versionnés. Aucun
  lieu inféré de l'IP, du SSO ou de la langue ; trois portées conservées.
- B3 : barrières/contextes/acquittements attestés, y compris versions d'admission.
- B4/B5 : scores réconciliés et corrigés même après clôture, consommation unique.
- B6 : formulaires avec nonce, parcours et DA V2, modération et recours accessibles.
- Pays autorisés Faluss, véritable SSO/admission réseau et recette opérateur avant
  production ; conservation #150 et RustFS #161 distinctes.
