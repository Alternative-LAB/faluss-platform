# B3 complément approuvé : corpus exhaustif propriétaire

**Contrat approuvé par ALB-Origine, propriétaire Hub, uniquement en recette jetable fictive.** Proposition exacte [#147, commentaire 6021094562](https://github.com/Alternative-LAB/faluss-platform/issues/147#issuecomment-6021094562) ; [accord explicite](https://github.com/Alternative-LAB/faluss-platform/issues/147#issuecomment-6021402556). B3 déjà approuvé continue : ordre/contexte/reçus 0.3, snapshots 2.0 par membre, transport fermé et barrières de fermeture.

### Dépendance précise

H4/F1a ne connaissent que les membres dont Fans a rapproché un snapshot. Même avec l'ordre B3, réunir ces membres ne prouve pas que tous les faits éligibles ont été lus. Un classement général, par catégorie ou de session exact demande une attestation exhaustive du propriétaire Hub. Ni les comptes WordPress, ni les premiers reçus reçus, ni un curseur Fans ne peuvent donner cette autorité.

### Recommandation de contrat additive

Ajouter **`hub.purchased-pf.ranking-corpus/1.0.0`**, lecture serveur privée explicitement choisie. Elle est séparée des snapshots privés par membre 1.0/2.0 et ne les remplace pas.

| Élément | Contrat approuvé |
| --- | --- |
| Autorité / périmètre | Hub primaire ; tous les faits **0.3 confirmés** liés au pair Fans propriétaire et à une origine admise précise. Anciennes attributions sans contexte exclues explicitement, jamais promues rétroactivement. Aucun compte, achat seul ou solde déduit comme contribution. |
| Permission | Nouvelle permission **`pf.ranking.corpus`**, accordée au seul serveur Fans propriétaire de l'origine, avec admission explicite. Ni `wallet.read`, `pf.snapshot`, ni une délégation d'un membre n'accordent la lecture globale. Aucun endpoint public, navigateur, ou changement des permissions Federation. |
| Demande | Pair/audience/origine/politique exacts, nonce signé à usage unique, clé durable et contenu canonique identique en reprise. Aucun paramètre de membre permettant d'élargir l'autorité d'une délégation Fan. |
| Manifeste signé | Version, ID du corpus, émetteur, audience propriétaire, origine/politique, époque d'ordre Hub, époque/révision monotone de snapshot, instant primaire UTC6, nombre de faits/pages et SHA-256 canonique complet. Périmètre exhaustif explicite, y compris corpus vide. |
| Fait | Attribution/consommation/Fan/Créateur opaques, contexte 0.3 et son empreinte, confirmation UTC6 et ordre Hub immuables, quantité d'origine, net/suspension/annulation rapprochés, filiation aux allocations et dernières révisions complètes H4. Zéro net reste présent pour attester une annulation ; aucune ancienne preuve ne peut le rétablir. |
| Données exclues | E-mail, noms, médias, montant, prix, référence de paiement, PC, disponible, wallet ou revenu. Les identifiants sont privés serveur-à-serveur ; les noms/consentements publics restent propriété Fans. |
| Exhaustivité | Hub énumère lui-même les consommations liées à cette origine ; aucune liste de membres fournie par Fans. Vérification de toutes les liaisons, ordres, débits et corrections H4, et de tous les lots nécessaires à leur rapprochement. Un trou, une époque incohérente, une filiation absente ou un fragment H4 non terminé rend **tout ce corpus indisponible**. |
| Pagination | Matérialisation atomique, pages immuables de 100 faits avec curseurs opaques liés au manifeste. Jamais pagination d'une fenêtre économique changeante. Empreintes, nombre de pages, unicités et chaîne complète vérifiés à la réception. |
| Fence primaire | Après réception complète, Hub reconstruit le périmètre courant sous les verrous propriétaires et compare son empreinte/vecteur à la matérialisation. Nouvelle consommation/correction entre les pages et la fence : `superseded`, aucun classement partiel. |

### Architecture recommandée

- Garder le ledger officiel et les propriétaires H1–H4. Le nouveau schéma ne contient que métadonnées de corpus matérialisé, pages et reprise ; **aucun ledger/solde PF parallèle**, aucune opération d'achat, débit ou remboursement ajoutée.
- Utiliser le verrou propriétaire global H1/H2 déjà commun aux nouvelles consommations/corrections, puis les lignes requises en ordre canonique. Ne pas prendre un verrou membre après un verrou global si cela inverse l'ordre existant. Une primitive propriétaire dédiée de **lecture de corpus** vérifie ce verrou et sa transaction ; elle n'usurpe pas une délégation Fan et n'appelle pas des transactions membres imbriquées.
- Les corrections sont relues par le propriétaire H4, sans en recopier les règles dans Fans. La lecture globale ne signe jamais un corpus pendant un rapprochement partiel. Le triplet date/ordre/époque d'origine reste inchangé.
- Fans stage les pages privées et les remplace atomiquement seulement après la fence. Projections reconstruites depuis cette génération complète ; événements/reçus servent uniquement de signal de relecture. Clé inconnue après réponse perdue : lookup primaire, même clé, jamais un autre corpus artificiellement plus récent.
- La fence atteste **l'instant de vérification**, pas une promesse de fraîcheur future. Avant une lecture déclarée exacte, rapprocher la dernière génération et son acquittement courant ; Hub inaccessible, nouvelle lecture en cours ou conflit : état indisponible. L'exploitation continue, son dimensionnement et les services de production restent une étape distincte, sans TTL présenté comme preuve d'absence de correction.

### Conséquences produit, tests et limites

Cette extension permet un classement exhaustif de l'origine et la reconstruction exacte général/catégories/sessions. Une correction en cours peut temporairement suspendre la lecture exacte du périmètre ; elle ne supprime aucune contribution et ne décide aucune sanction produit. Aucun participant fictif, historique non attesté ou rang partiel n'est ajouté. L'ordre, les plafonds, visibilité et places uniques restent ceux de F1b R2.

Recette autorisée : deux Hub/Fans WordPress/MariaDB jetables, données fictives uniquement. Couvrir un membre inédit après le premier rapprochement, corpus vide, plus de 100 faits, multi-lots/multi-dimensions, pages manquantes/altérées, corrections partielles/totales et litiges, événements désordonnés, fermeture concurrente, consommation/correction entre pages et fence, réponse perdue, clés/audiences/origines/permissions incorrectes et restauration contradictoire. Rejouer les anciens H1–H4/F1a ; aucun ancien contrat ou claim réécrit.

**Accord reçu : ce contrat de lecture exhaustive et sa permission dédiée, uniquement en recette fermée.** Aucun achat réel, pair de production, site, migration automatique, flag, score public ou déploiement. Conservation #150 et RustFS #161 restent distincts. L'accord n'atteste aucune exploitation de production ni politique de conservation.

## Sous-lots et état de preuve

1. **B3c2a, cette PR** : validateur canonique du corpus, permission reconnue uniquement si explicitement présente, domaines Ed25519 distincts et tests de refus. Aucun schéma, stockage, route ou admission. Un validateur ne prouve pas la complétude de données réelles : elle doit être attestée par le propriétaire Hub.
2. **B3c2b1, #174** : primitive propriétaire de lecture exhaustive sous verrou global, composition de chaque fait 0.3 avec le net H4. Mesurer le temps de détention des verrous, les attentes des consommations et corrections, puis la reprise d'une lecture pendant un rapprochement ; publication des seuls résultats expurgés et limites de dimensionnement de la recette. **B3c2b2, lot courant** : quatre tables de métadonnées uniquement, matérialisation atomique, pages immuables de 100 et vérification primaire finale, lookup/reprise par clé durable et refus d'une génération devenue obsolète. La preuve réseau signée et l'inbox Fans restent B3c2c.
3. **B3c2c1, #176** : codec signé de lecture propriétaire, contexte exact par origine/politique/opération/clé, réponses liées à la requête et validateurs de pages/fences/refus. Aucun HTTP, nonce admis en SQL ou inbox dans ce sous-lot. **B3c2c2, lot courant** : transport HTTP physiquement fermé, nonce SQL distinct, clés de nœuds distinctes, refus et reprise après perte de réponse. **B3c2c3** : inbox Fans avec remplacement atomique après réception de toutes les pages et fence primaire ; ce transport seul ne prouve pas cette réception exhaustive.

### Transport fermé B3c2c2

**ClosedCorpusClient** effectue une seule lecture POST, sans redirection, cookie
ou seconde clé automatique. Le propriétaire de la reprise doit **persister la
clé avant HTTP** ; l'inbox qui automatisera cette garantie reste B3c2c3. Après
résultat inconnu, lookup sur le primaire avec la même clé et un nouveau nonce
signé ; réponse liée aux champs exacts, nonce et empreinte de la demande.
L'absence de résultat signé valide n'est ni un corpus vide ni une preuve de
non-commit.

**ClosedCorpusGateway** authentifie la demande et son contexte avant dispatch.
**ClosedCorpusAdmission** consomme une seule fois le nonce du pair, sous le
verrou global propriétaire puis bail de lecture. L'expiration est revérifiée
après attente du verrou ; l'admission est le point de validation du contexte.
Un nonce admis reste consommé même si la lecture ultérieure échoue : la reprise
utilise la même clé durable, un nouveau nonce et un lookup. Les deux tables
**token_engine_pf_b3ch_schema/nonces** contiennent uniquement métadonnées,
empreintes et dates. Elles ne réutilisent pas un nonce Fan historique et ne
créent aucune entrée économique. Aucun calendrier de purge réel n'est défini
par ce lot ; toute la base fictive est supprimée à la fin de la recette.

Les classes ne sont appelées par aucun bootstrap, hook public, cron ou UI.
Le MU loader **de test**, copié explicitement, ne crée sa route que dans
l'enclave physique H3 : racine POSIX privée, bail privé, primaire MariaDB
sur socket privé et PHP CLI/server en loopback. Copier les constantes ou ce
loader sur un WordPress ordinaire ne suffit pas à ouvrir une route. Fans ne
peut appeler que l'URL exacte de recette sur **127.0.0.1**, sans destinataire
fourni par un navigateur. Authentification par les signatures et la permission
globale dédiée, sans détourner la session SSO d'un membre.

Les délais de quotas historiques sont accélérés **sur la connexion SQL de
recette**, par un fichier privé contrôlé par le test ; aucune date n'est
acceptée depuis HTTP. Les signatures conservent leur horloge réelle et leur
durée de 60 secondes. Les temps et quantités fictifs ne prouvent ni le
dimensionnement de production, ni TLS, ni Faluss Identity SSO, ni une politique
de conservation. Rollback : retirer le loader de recette et supprimer son
enclave jetable ; aucune migration ou réparation de site n'est proposée.

Les transports 0.3, snapshots membres 2.0 et barrières restent des sous-lots B3 parallèles à intégrer, avec leurs propres domaines. B4/B5 ne déclareront aucun classement exhaustif tant que le corpus propriétaire complet n'est pas rapproché.

### Forme canonique du corpus 1.0

Le manifeste contient `contract`, `corpus_id`, `issuer`, `audience`, `origin_id`, `policy_version`, `ordering_epoch`, `epoch`, `revision`, `last_order`, `created_at`, `scope`, `fact_count`, `page_count`, `original_pf`, `cancelled_pf`, `suspended_pf`, `net_pf` et `full_sha256`. Identifiants UUID v4 opaques ; entiers décimaux en chaînes canoniques jusqu'à 9007199254740991 ; instant primaire UTC6. `scope` vaut exactement `all_confirmed_ranked_0.3_including_zero`. `last_order` est le sommet propriétaire global vérifié : des trous entre les seuls faits d'une origine peuvent correspondre aux autres origines, sans simuler une séquence locale.

Chaque fait garde attribution, consommation, filiation du débit, membre et Créateur, pair propriétaire, date/ordre/époque, contexte et son SHA, quantité d'origine et net courant. Ses une à 32 allocations sont triées par ID de lot ; uniquement filiation H4 (révision, état, identifiant/empreinte de preuve) et quantités, sans référence d'achat ni disponible. Même lot = même propriétaire et dernière révision/état/preuve dans tous les faits. Le net d'un lot en litige vaut zéro ; un lot entièrement annulé garde ses faits à zéro. L'état et l'empreinte de la preuve restent H4, jamais reconstruits depuis les dates de réception.

Les faits sont triés par ordre Hub strictement croissant, avec confirmations UTC6 non décroissantes et contexte conforme au manifeste. L'empreinte complète porte sur le tableau canonique **de tous** les faits. Pagination de 100, une page vide pour un corpus vide ; curseur opaque et manifeste exact sur chaque page. La réception complète vérifie aussi les unicités entre pages, totaux, dernières révisions par lot et empreinte globale. Lire les pages individuellement ne prouve pas l'exhaustivité.

Domaines distincts : `faluss.hub-purchased-pf.corpus-context/1`, `corpus-request/1` et `corpus-response/1`. Requêtes bornées à 49 152 octets, contexte à 32 768, réponse à 4 MiB pour couvrir une page de 100 faits avec dix sessions et 32 allocations chacun ; aucun agrandissement d'un ancien domaine. Une réponse trop grande sera refusée, jamais tronquée. Le seuil de matérialisation totale et le dimensionnement seront documentés par le lot propriétaire, sans annoncer une capacité de production depuis cette borne de transport.

### Codec de transport B3c2c1

`CorpusTransport` admet uniquement `start`, `lookup`, `page` et `finish`.
Champs communs : `read_id` opaque du collecteur durable Fans, `origin_id`,
`policy_version`, `corpus_id` et `cursor`. Ces deux derniers sont vides pour
start/lookup ; page exige les deux UUID de la génération ; finish n'admet
aucun curseur. Aucun paramètre de membre, liste de comptes, offset ou opération
économique. L'origine doit toujours être admise par le store propriétaire,
pas déduite d'un contexte envoyé par le navigateur.

Le contexte Fans signé et la requête reprennent ces champs identiques, avec
pair/audience/contrat exacts, nonce, instants UTC et empreinte de la même clé
durable. Durée au plus 60 secondes, refus des clés inconnues/révoquées et des
contextes expirés. La seule permission globale est `pf.ranking.corpus` :
snapshot, wallet ou délégation d'un membre ne l'accordent jamais. La validation
du nonce n'est pas son admission à usage unique ; celle-ci sera persistée en
SQL avant opération par le lot HTTP fermé.

La réponse Hub signée reprend exactement la demande et son SHA-256 complet.
Start/lookup fournissent la première page immuable ou l'absence certaine au
lookup ; page fournit seulement le curseur demandé ; finish valide le manifeste,
son digest et `verified_at` primaire. Refus et résultat incertain contiennent
seulement un motif canonique, aucune page. Une signature de page ne prouve
pas encore la complétude entre pages ni la fraîcheur continue.

Les bornes du **payload signé** restent inchangées. L'enveloppe externe canonique
ajoute base64 et métadonnées : 66 048 octets pour une requête, 5 593 088 pour une
réponse, afin de transporter réellement un payload de 4 MiB. Aucune ancienne
limite ou domaine Federation/PF élargi. Pas de route, admission, hooks, schéma,
clé ou flag de production dans ce sous-lot pur ; réseau et remplacement Fans
restent à prouver séparément avec clés de nœuds distinctes en isolation.
