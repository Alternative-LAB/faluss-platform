# B3b3 — transport privé des barrières, accord fermé

Le propriétaire Hub ALB-Origine a approuvé explicitement
[`hub.purchased-pf.ranking-barriers/1.0.0`](https://github.com/Alternative-LAB/faluss-platform/issues/147#issuecomment-6024856427)
et sa persistance de reprise dans cette conversation le 6 octobre 2026.
Cet accord concerne exclusivement deux instances Hub/Fans jetables et des
identités, pairs, clés et données fictifs. Il n'autorise aucun site, admission
réseau de production, migration automatique, flag ou opération réelle.

## Forme approuvée

Extension 1.1 de clôture normale approuvée séparément le 10 octobre :
[contrat et découpage](HUB-PF-B3-SESSION-COMPLETION.md). Le défaut 1.0 et ses
validateurs restent inchangés ; le codec 1.1 seul n'ouvre aucun parcours.

`BarrierTransport` définit `register`, `close` et `lookup`, cette dernière avec
une cible explicite register/close. Champs exacts : `contract`, `kind`, `issuer`,
`audience`, `operation`, `action_id`, `origin_id`, `policy_version`,
`operation_key`, `lookup_operation`, `object`, `nonce`, `issued_at`, `expires_at`,
`context`. L'action opaque est durable ; aucune nouvelle action ou clé ne doit
contourner un résultat incertain.

L'objet réutilise strictement le descripteur `RankingBarrier::descriptor()` ou
la référence `closeReference()`. Le contexte signe les mêmes champs d'action,
les empreintes de l'objet et de la clé, le nonce et les instants exacts. Validité
maximale de 60 secondes ; pair exact `fixture.fans` vers `fixture.hub`.
Permissions `pf.ranking.context.register` ou `.close` ; lookup exige également
`pf.lookup`. Une permission économique ou historique ne vaut pas admission.

Les domaines Ed25519/JCS sont distincts :
`faluss.hub-purchased-pf.barrier-context/1`, `barrier-request/1`,
`barrier-response/1`. Limites : contexte 32 KiB, requête/réponse 48 KiB,
enveloppe externe 66 048 octets. Aucun ancien domaine ou budget n'est augmenté.

La réponse lie l'action complète, l'empreinte de l'objet, le nonce et le digest
de la requête. Résultat propriétaire : opération, référence/version/digest,
instant primaire UTC6 `effective_at`, motif de fermeture si applicable et état
`active`, `closed` ou `superseded`. Une réponse close active est invalide.
Seul lookup peut retourner `not_found`. `unknown` et `refused` transportent un
motif canonique, jamais un montant, score, profil ou contenu de session.

## Périmètre livré et dépendances

Ce premier sous-lot livre le codec pur et ses tests. Il n'installe aucune table,
ne reconnaît aucun pair réel, n'admet aucun nonce et n'enregistre aucune route.
Les contrats 0.2/0.3, les claims historiques et le ledger sont inchangés.

Les lots suivants raccordent le codec au propriétaire et à la reprise Fans :

- Admission SQL du nonce, fraîcheur après les verrous et avant l'écriture,
  liaison de la clé au contenu exact. Une référence close seule ne contient
  pas l'origine : Hub devra vérifier cette origine/politique depuis le
  descripteur propriétaire de la version, dans la transaction. La signature
  d'une origine fournie ne remplace pas ce contrôle.
- Requêtes privées no-store, loopback exact, sans cookies ni redirection,
  enclave physique contrôlée ; aucun accès GET ou simple flag de site.
- Action/clé/requête persistées avant HTTP. Ouverture `opening` jusqu'à
  l'acquittement primaire ; fermeture `closing` et nouveaux choix Fans fermés
  dès la demande. Lookup conserve la même action/clé après panne.
- Un ancien acquittement ne peut réouvrir une version fermée ou supersédée.
  Les faits confirmés avant fermeture restent conservés ; les corrections
  propriétaires continuent de s'appliquer.

Les tests réseau et SQL à deux instances restent à obtenir : nonce concurrent,
origines étrangères, expiration derrière les verrous, réponse perdue après
COMMIT register/close, lookup, concurrence confirmation/fermeture et reprise
locale. Le test de codec ne prouve ni ces garanties transactionnelles ni le
véritable SSO. Conservation #150 et RustFS #161 restent distinctes.

## Vérifications du codec

Scénarios positifs des trois opérations, comparaison exacte de tous les champs,
altération du contexte/requête/réponse, audience, expiration, clés inconnues ou
révoquées, mauvaises permissions/domaines, référence et motif différents,
absence primaire uniquement sur lookup et refus des données économiques.
Les clés sont générées uniquement dans les processus de test.

Recette du 7 octobre 2026, copie Git immuable
`2b2cf3d44a08cf418b350e0f0664074719ea1657`, dépendances du verrou exact :
32 tests ciblés / 70 assertions ; suite complète 655 tests / 6 967 assertions,
zéro échec, deux dépréciations historiques. PHP 8.5.4, PHPStan complet avec
cible PHP 8.3, syntaxe des trois PHP, liens et scan ciblé satisfaits.
La CI du head exact reste requise avant fusion. Aucun WordPress/MariaDB ou
échange HTTP nouveau n'est revendiqué pour ce validateur pur.

Retour arrière : revert des classes/domaines nouveaux, sans migration ou
donnée persistante à supprimer. Voir le
[registre propriétaire B3](HUB-PF-B3-CLOSED.md) et le
[contrat B3 approuvé](HUB-PF-B3-RANKING-PROPOSAL.md).

## B3b3b — contexte dans la transaction propriétaire

`ClosedBarrierContext` est la composition interne qui suit l'authentification
du codec ; il ne vérifie pas une signature et ne doit pas recevoir un contexte
de navigateur. Les méthodes propriétaires acceptent ce contexte en complément
du contrat SQL historique, qui reste inchangé lorsqu'il est absent.

La clé est liée au digest canonique du contrat, de l'action opaque, de l'origine,
de la politique, de l'opération cible et de l'objet exact. Lookup conserve cette
liaison ; un autre nonce ou instant ne change pas l'action. Une clé historique
ne peut être adoptée par ce transport ni l'inverse. La colonne de digest
existante suffit : aucune table ou migration nouvelle dans ce sous-lot.

Pour close et son lookup, la version exacte du descripteur est lue et verrouillée
dans la transaction. Son empreinte, son propriétaire, sa référence et ses
origine/politique doivent correspondre. L'origine annoncée dans une référence
close pauvre n'est donc jamais une preuve à elle seule. Une ancienne version
fermée peut toujours être retrouvée ; le résultat propriétaire reste fermé
ou supersédé, sans rouvrir cette version.

La fraîcheur est vérifiée avant l'attente, après le verrou propriétaire et
avant la décision COMMIT. Une expiration pendant un verrou de ligne ou après
l'insertion de l'événement provoque le rollback de tous les effets préparés.
Le point de contrôle précède la décision COMMIT ; il ne promet pas une limite
de durée de la persistance physique de MariaDB. L'ancien comportement SQL sans
contexte n'acquiert pas artificiellement une autorité réseau.

Scénarios écrits avant recette : quatre opérations/cibles positives et expirées,
rejeux concurrents, même clé autre action, incompatibilité de clé historique,
origine/politique étrangères, expiration derrière le mutex propriétaire et
après insertion de l'événement, lookup et reprise avec la même action.
Le ledger doit rester byte-identique. Le nonce réseau dédié, le gateway HTTP
et la persistance Fans opening/closing restent les raccordements suivants.
Aucune recette de site ou véritable SSO n'est revendiquée.

Recette obtenue : **251 contrôles WordPress/MariaDB satisfaits, dont 28 nouveaux** ;
suite PHP complète 655 tests / 6 976 assertions et analyse statique sans erreur.
[Preuves et limites](../evidence/hub-pf-b3-barrier-context/README.md).

## B3b3c — admission SQL des nonces de transport

`ClosedBarrierTransportSchema` possède deux tables privées distinctes des nonces
historiques et du corpus. Installation explicite dans l'enclave Hub jetable,
jamais au bootstrap. Le schéma InnoDB exact est exigé ; un état partiel n'est
ni adopté ni réparé automatiquement. Aucune durée de conservation ou purge
réelle n'est introduite.

Après authentification des deux signatures par le futur gateway,
`ClosedBarrierAdmission` revalide forme, pair propriétaire, permission dédiée,
nonce, empreinte, action et validité. Un mutex par pair/nonce sérialise les
rejeux identiques sans prendre de verrou économique. La validité est relue
après l'attente et après insertion, avant la décision COMMIT ; une expiration
à ces étapes annule l'admission. Le registre ne modifie ni barrière ni ledger.

L'admission est une transaction distincte de register/close/lookup. Si son
acquittement est perdu, le même nonce réseau ne peut être réadmis. Une nouvelle
enveloppe fraîche reprend la même action et clé métier ; le propriétaire
retrouve alors l'opération éventuelle. La clé métier ne doit jamais être
remplacée pour contourner un timeout. Le journal de nonces ne conserve ni
objet complet, identité de membre, clé en clair, signature ou contenu privé.

Scénarios : huit admissions simultanées, autre action sous un nonce connu,
nouvelle enveloppe sous la même action, permissions anciennes insuffisantes,
lookup sans permission cible ou lookup, schéma partiel/MyISAM, échec d'insertion,
COMMIT sans acquittement, expiration derrière mutex et après insertion. Le
ledger officiel doit rester byte-identique. La recette SQL n'est pas une
preuve d'authentification HTTP ; gateway et reprise Fans opening/closing
restent les étapes suivantes du contrat déjà approuvé.

Recette obtenue : **272 contrôles WordPress/MariaDB satisfaits, dont 21 nouveaux** ;
655 tests PHP / 7 000 assertions et PHPStan complet sans erreur.
[Preuves et limites](../evidence/hub-pf-b3-barrier-admission/README.md).

## B3b3d — gateway privé et recette HTTP

`ClosedBarrierGateway` authentifie les deux signatures et le pair exact, puis
admet le nonce distinct avant l'opération propriétaire. Le contexte frais
reste contrôlé dans la transaction Hub. La réponse signée lie l'action,
l'objet, la requête et le nonce ; un résultat incertain reste `unknown`.
Les opérations ne changent aucune quantité PF.

Le MU-adaptateur existe uniquement sous `tests/`, hors archive livrée. Il exige
l'enclave physique sur chaque lecture, une politique de pair 0600 et un POST
privé. Aucun hook du plugin, route de site ou admission réelle n'est ajouté.
Les [preuves HTTP](../evidence/hub-pf-b3-barrier-http/README.md) distinguent
303 contrôles isolés du véritable SSO et du client Fans encore à raccorder.
La persistance durable opening/closing est décrite ci-dessous ; la concurrence
réseau avec la consommation et le raccordement à la gouvernance B1/B2 restent
des sous-lots distincts du contrat déjà approuvé.

## B3b3e — reprise durable du client WordPress Fans

`ClosedBarrierInboxSchema` possède quatre tables InnoDB privées : version du
schéma, versions de descripteurs, actions immuables et requêtes signées. Aucun
ledger, cron, hook ou installation automatique. L'installation explicite exige
l'enclave physique Fans ; constantes copiées, schéma partiel ou MyISAM ne
permettent pas d'ouvrir ce transport sur un site ordinaire.

`ClosedBarrierInbox` persiste l'action et sa clé aléatoire avant tout HTTP. Une
ouverture reste `opening` jusqu'à l'acquittement primaire signé. Une fermeture
crée l'action et passe immédiatement à `closing` dans une même transaction :
aucun nouveau choix ne doit être proposé dans cet état. Si register est encore
incertain, sa même action est rapprochée avant d'envoyer close. Le code ne
remplace jamais une clé pour contourner un timeout.

`ClosedBarrierClient` avance de un à seize échanges par appel (quatre par
défaut), uniquement vers le MU-adaptateur de recette sur loopback. Il ne suit
aucune redirection et n'envoie aucun cookie. Chaque requête est enregistrée
avant livraison, hors transaction et hors mutex pendant le réseau. Après un
résultat incertain, reprise par lookup primaire ; une absence attestée permet
le rejeu de la même opération sous la même clé. Les erreurs de signature ou de
transport restent incertaines, jamais assimilées à un refus Hub authentifié.

Une fermeture queued ne devient effective que sur acquittement register puis
close, ou sur leur lookup. Un ancien résultat register ne réouvre ni closing
ni closed. Les preuves persistées sont revérifiées avec la confiance courante ;
clé Hub révoquée, ancienne ligne active isolée ou preuve liée à une autre
action ferment l'accès. Une étiquette SQL `active` sans preuve ne suffit pas.
Après refus définitif de register, la fermeture reste bloquée localement et
requiert la résolution de gouvernance, sans fausse déclaration de fermeture.

Scénarios préalables : préparations concurrentes, fermeture pendant opening,
ancien acquittement retardé, corps HTTP réellement perdu après register/close,
erreur après insertion d'action/requête, perte de COMMIT local, arrêt des
processus avant/après COMMIT, reprise avec les mêmes clés, confiance révoquée et
restauration contradictoire. Les quantités et écritures PF restent inchangées.
Recette complète : **336 contrôles WordPress/MariaDB satisfaits, dont 33
nouveaux de reprise** ; 657 tests PHP / 7 048 assertions et analyse statique
sans erreur. [Preuves expurgées](../evidence/hub-pf-b3-barrier-recovery/README.md).

Ce client attend des champs composés par un serveur de confiance ; ce n'est
pas un endpoint membre. Le raccordement à l'origine B1, aux transitions de
session/territoire/participation B2 et à la sélection d'attribution reste à
implémenter et tester. Aucun véritable SSO, ouverture économique, fraîcheur de
production ou capacité de classement sur les sites n'est revendiqué. Aucun
nouveau contrat Hub n'est introduit. Retour arrière : retirer ces composants
inertes et détruire la fixture ; aucune migration de site à annuler.
Conservation #150 et RustFS #161 restent distinctes.
