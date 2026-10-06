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

## B2b — scénarios préalables : examen territorial

Registre explicite de critères publics et de territoires nommés, immuable par
version. Il n'autorise pas les pays depuis lesquels les Fans peuvent soutenir :
ce dernier contrat reste distinct. Aucun référentiel ou critère installé par
défaut ; les données de recette sont expressément fictives.

Positifs : déclaration propriétaire du territoire d'activité principal ;
modérateur approuve la révision exacte selon la version déclarée ; ouverture
nationale/localisée et participation compatibles avec le territoire examiné ;
politique figée dans la session ; nouvelle politique sans réécriture du passé ;
rejet motivé, recours privé et décision tracée ; retrait immédiatement opposable
aux nouveaux choix, sans effacer les anciennes attestations.

Négatifs : localisation déduite d'IP/SSO, déclaration étrangère, auto-approbation,
politique absente/divergente, territoire inconnu, pays/localité non compatibles,
révision obsolète/décisions concurrentes, version en attente/rejetée utilisée
comme admission, changement implicite des règles d'une session gelée.

Une nouvelle déclaration ferme l'éligibilité territoriale jusqu'à sa décision ;
elle ne modifie aucune contribution historique. B3 devra fermer les versions
de contexte correspondantes avec sa barrière et sa reprise attestée, avant toute
ouverture économique. En B2b, aucune attribution n'est possible.

### Livraison et frontières B2b

Cinq tables InnoDB supplémentaires : versions immuables des critères et du
référentiel nommé, déclaration actuelle, journal des décisions, rattachement
immuable de la politique à la session et version de schéma. Installation explicite
après B1/B2a, sans modifier leurs tables ou appeler un bootstrap.

Les Créateurs déclarent leur territoire principal ; les administrateurs Fans
examinent la révision exacte, motivent leur décision, traitent le recours privé
et peuvent révoquer. Vingt soumissions par heure ; pseudonyme, identité, portrait,
partenariat commercial et pays autorisés des Fans ne sont pas certifiés par cet
examen. Une suspension du profil empêche les nouvelles admissions ; lecture
privée, recours et retrait demeurent possibles selon la liaison propriétaire.

En portée nationale, le pays examiné doit correspondre ; en portée locale,
la référence nommée et le pays doivent correspondre. Tous les organisateurs et
participants admis suivent ces mêmes conditions. En portée internationale,
aucune restriction territoriale supplémentaire n'est ajoutée. La version des
critères est liée à la session dans la transaction de gel ; un échec annule
aussi ce rattachement. Changer de politique ne modifie pas les anciennes règles.

Les empreintes des textes éditoriaux acceptent UTF-8. Elles sont distinctes du
codec historique ASCII du protocole Hub, laissé inchangé. Aucun texte de critères,
recours ou donnée éditoriale ne devient un champ économique Hub.

Recette : option `--test --hof-territory`, [42 vérifications en base](../evidence/fans-hof-territory/README.md).
Les critères, pays et territoires du rapport sont fictifs, sans habilitation
de production. Aucun référentiel réel ni politique de pays autorisés n'a été
fourni ou attesté ; leur désignation/examen opérateur reste nécessaire avant
activation. B2c complète la modération des sessions et les recours de participation.

## B2c — scénarios préalables : modération, recours et réadmission

L'admission du profil ne valide pas les règles/contenus d'une session. Le
propriétaire soumet son brouillon ; approbation explicite d'une empreinte exacte
avant demande d'ouverture. Une édition invalide cette approbation pour l'ouverture,
sans diffuser la nouvelle version. Refus motivé et recours privé. Révocation d'une
session gelée ferme immédiatement les nouveaux choix, avec `closing` tant que B3
n'atteste pas la fermeture. Aucun acquittement local inventé.

La participation refusée a son recours distinct, réservé au candidat concerné et
aux modérateurs. Une décision favorable ne permet qu'une admission future sous
les mêmes règles, territoire, dates et permissions ; aucun rattachement rétroactif.
Une session expirée/fermée ne peut être réouverte par un recours de participation.
Le traitement motivé reste possible sans admission lorsque le contexte est fermé.

Après suspension effective attestée, le propriétaire peut demander une réadmission
versionnée : règles gelées conservées, nouvelle approbation, quotas/organisateurs
et territoires revalidés, version de barrière incrémentée, attente d'acquittement
primaire. Annulation ou échéance n'est jamais transformée en réouverture.

Positifs : demande/modération distinctes du profil, exactitude d'empreinte,
refus/recours/reprise, visibilité approuvée seulement, journaux atomiques ;
participation refusée réexaminée et admission future ; lecture propriétaire
après suspension ; fermeture en cours et réadmission versionnée après preuve.

Négatifs : auto-approbation, édition après décision utilisée comme approbation,
concurrence de décisions, recours d'un autre candidat, double recours pour la
même décision, admission rétroactive/après échéance, contournement du territoire
ou du quota, faux état `open`/fermeture acquittée sans preuve Hub, panne du journal.

### Livraison et frontières B2c

Cinq tables InnoDB propres : version de schéma, examen courant, instantanés
privés exacts des règles et recours à chaque révision, dossiers de participation
et décisions motivées. Installation explicite après B2a ; aucune route, hook,
bootstrap ou migration automatique. L'ordre commun des transactions de session
est préservé. Une admission et sa décision de recours ne sont jamais commises
séparément de leurs journaux.

Propriétaire : soumettre les règles courantes, consulter son examen et son
historique, contester un refus. Candidat : un recours privé par décision de
participation refusée ; aucun organisateur ne lit le texte privé d'un autre
candidat. Administrateur Fans : approuver/refuser/révoquer une révision exacte,
traiter un recours, admettre seulement sous les critères actuellement satisfaits
ou clôturer sans admission en motivant le contexte. Files paginées à vingt et
révisions CAS. Modifier un brouillon invalide sa précédente approbation ; une
proposition modifiée peut être resoumise, sans conserver une validation périmée.

Le [contrat transactionnel éditorial](FANS-EDITORIAL.md#contrat-de-contrôle-transactionnel-additif-b2c)
verrouille l'approbation au moment de la décision ; un retrait concurrent ne
passe pas entre contrôle et admission. Ce contrôle ne valide ni partenariat
commercial, ni images, ni contenu de session. Les règles de session exigent
leur propre examen.

Les textes de recours et instantanés restent privés. Aucune durée réelle, défaut
de vingt-quatre mois ou purge n'est ajouté : conservation [#150](https://github.com/Alternative-LAB/faluss-platform/issues/150)
distincte, à décider avant toute donnée réelle. Aucun outil opérateur de ce lot
n'atteste cette politique à la place de son responsable.

Recette `--test --hof-review` : [preuves en base et limites](../evidence/fans-hof-review/README.md).
Le test de réadmission injecte explicitement une **précondition de suspension
effective** ; ce n'est pas une preuve de fermeture Hub. B3 doit remplacer cette
précondition de recette par un acquittement/lookup primaire attesté, avec tests
de panne et de concurrence. En attendant, les états `opening` et `closing` ne
deviennent jamais `open` ou fermés par une assertion locale.
