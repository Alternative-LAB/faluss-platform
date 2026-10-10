# B3b3 — transport privé des barrières, accord fermé

Le propriétaire Hub ALB-Origine a approuvé explicitement
[`hub.purchased-pf.ranking-barriers/1.0.0`](https://github.com/Alternative-LAB/faluss-platform/issues/147#issuecomment-6024856427)
et sa persistance de reprise dans cette conversation le 6 octobre 2026.
Cet accord concerne exclusivement deux instances Hub/Fans jetables et des
identités, pairs, clés et données fictifs. Il n'autorise aucun site, admission
réseau de production, migration automatique, flag ou opération réelle.

## Forme approuvée

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
