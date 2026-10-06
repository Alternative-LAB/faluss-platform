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
2. **B3c2b** : primitive propriétaire de lecture exhaustive sous verrou global, composition de chaque fait 0.3 avec le net H4, matérialisation/page/fence. Mesurer le temps de détention des verrous, les attentes des consommations et corrections, puis les reprises d'un corpus obsolète ; publication des seuls résultats expurgés et limites de dimensionnement de la recette.
3. **B3c2c** : transport HTTP physiquement fermé et inbox Fans avec remplacement atomique après réception de toutes les pages et fence primaire. Tests des permissions, signatures, audiences, origines, clés, réponses perdues et refus d'une génération incomplète.

Les transports 0.3, snapshots membres 2.0 et barrières restent des sous-lots B3 parallèles à intégrer, avec leurs propres domaines. B4/B5 ne déclareront aucun classement exhaustif tant que le corpus propriétaire complet n'est pas rapproché.

### Forme canonique du corpus 1.0

Le manifeste contient `contract`, `corpus_id`, `issuer`, `audience`, `origin_id`, `policy_version`, `ordering_epoch`, `epoch`, `revision`, `last_order`, `created_at`, `scope`, `fact_count`, `page_count`, `original_pf`, `cancelled_pf`, `suspended_pf`, `net_pf` et `full_sha256`. Identifiants UUID v4 opaques ; entiers décimaux en chaînes canoniques jusqu'à 9007199254740991 ; instant primaire UTC6. `scope` vaut exactement `all_confirmed_ranked_0.3_including_zero`. `last_order` est le sommet propriétaire global vérifié : des trous entre les seuls faits d'une origine peuvent correspondre aux autres origines, sans simuler une séquence locale.

Chaque fait garde attribution, consommation, filiation du débit, membre et Créateur, pair propriétaire, date/ordre/époque, contexte et son SHA, quantité d'origine et net courant. Ses une à 32 allocations sont triées par ID de lot ; uniquement filiation H4 (révision, état, identifiant/empreinte de preuve) et quantités, sans référence d'achat ni disponible. Même lot = même propriétaire et dernière révision/état/preuve dans tous les faits. Le net d'un lot en litige vaut zéro ; un lot entièrement annulé garde ses faits à zéro. L'état et l'empreinte de la preuve restent H4, jamais reconstruits depuis les dates de réception.

Les faits sont triés par ordre Hub strictement croissant, avec confirmations UTC6 non décroissantes et contexte conforme au manifeste. L'empreinte complète porte sur le tableau canonique **de tous** les faits. Pagination de 100, une page vide pour un corpus vide ; curseur opaque et manifeste exact sur chaque page. La réception complète vérifie aussi les unicités entre pages, totaux, dernières révisions par lot et empreinte globale. Lire les pages individuellement ne prouve pas l'exhaustivité.

Domaines distincts : `faluss.hub-purchased-pf.corpus-context/1`, `corpus-request/1` et `corpus-response/1`. Requêtes bornées à 49 152 octets, contexte à 32 768, réponse à 4 MiB pour couvrir une page de 100 faits avec dix sessions et 32 allocations chacun ; aucun agrandissement d'un ancien domaine. Une réponse trop grande sera refusée, jamais tronquée. Le seuil de matérialisation totale et le dimensionnement seront documentés par le lot propriétaire, sans annoncer une capacité de production depuis cette borne de transport.
