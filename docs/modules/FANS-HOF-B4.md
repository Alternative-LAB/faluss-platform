# B4 — projections du corpus exhaustif rapproché

## B4a : composition pure, sans publication

Les [règles F1b R2](FANS-PF-F1B-PROPOSAL.md) et le
[corpus propriétaire B3](HUB-PF-B3-RANKING-CORPUS.md) sont approuvés uniquement
dans le périmètre fermé décrit par #147. `RankingCorpusProjection` transforme
une génération complète déjà authentifiée par l'inbox B3 en résultats privés :

- deux familles séparées, Fans et Créateurs, pour le général persistant ;
- les mêmes familles par catégorie attestée lors de la consommation ;
- des relevés mensuels privés Europe/Paris avec bornes UTC et gestion été/hiver.

La dernière valeur nette rapprochée alimente ces vues sans consommation
supplémentaire. Le mois est celui de la confirmation d'origine, pas celui de
la correction. Les relevés ne comportent aucune place ni classement mensuel.
Le général et les catégories ne sont jamais remis à zéro au changement de mois.

Le calcul réutilise `RankingFacts` : score net décroissant, date d'atteinte
reconstruite à partir des seules contributions encore positives, ordre Hub et
identifiant immuable en dernier ressort. Une attribution annulée ne conserve
aucun avantage d'ancienneté. Le net nul conserve une activité mensuelle privée
à zéro mais n'invente aucune place. Les litiges suivent le net attesté par Hub ;
leur résolution ne crée ni nouvelle consommation ni date réseau.

L'entrée contient manifeste, faits exhaustifs et date de vérification primaire.
Sa cohérence, ses totaux, son ordre et son empreinte sont vérifiés à nouveau.
Une page tronquée, un doublon, un champ PC, une époque contradictoire ou un net
altéré sont refusés. Le calcul ne reconstitue pas un inventaire à partir des
membres connus localement et ne déduit aucun point d'un pack acheté seul.

## Frontière de preuve de B4a

Ce calculateur pur ne vérifie pas une signature et ne possède pas de stockage.
Son appelant doit exclusivement utiliser la génération courante authentifiée
par l'inbox. Il ne suffit pas de lui fournir un tableau nommé « vérifié ».
Les résultats contiennent des références privées et ne sont pas une API publique.

Le sous-lot B4b ci-dessous persiste une génération reconstruisible en transaction, liée à
l'origine, la politique, l'époque, la révision et l'empreinte du corpus. Il doit
refuser la restauration d'une ancienne génération après correction, y compris
quand une ancienne réponse arrive après la nouvelle. Cette propriété relève
de l'inbox et du stockage B4b ; ce calcul pur isolé ne la revendique pas.
La provenance conserve le digest et la date primaire pour ce raccordement.

L'ouverture réelle de l'origine doit également être attestée et enregistrée,
jamais déduite d'une date WordPress. Les lectures B6 appliqueront visibilité,
consentements et état courant avant d'exposer des places. Les sessions restent
un objet distinct en B5. Aucun bootstrap, table, route, cron, flag, migration ou
score public n'est ajouté par B4a. Les anciens H1–H4/F1a et claims restent inchangés.

## Scénarios et retour arrière

Tests purement locaux : un fait pour les deux familles, catégories distinctes,
limites mensuelles avant/après changement d'heure, corrections partielles et
totales, suppression d'ancienneté, litige/résolution, horodatages identiques,
reconstruction déterministe, corpus vide, incomplet, dupliqué ou altéré.
Ils ne constituent pas une nouvelle recette WordPress/MariaDB ou SSO.

Vérification du 7 octobre 2026 sur la copie Git immuable
`1a7107e594842970803dac88c0bbe2e3fb186032` : 8 nouveaux tests / 51 assertions ;
HoF 59 / 156, suite complète 631 / 6 948, zéro échec et deux dépréciations
historiques. PHP 8.5.4 local, PHPStan complet cible PHP 8.3, lint des deux PHP,
liens relatifs, scan ciblé et diff check satisfaits. CI du head exigée avant fusion.

Retour arrière : retirer ces deux fichiers inertes ; aucune donnée ni migration
à annuler. Conservation #150 et RustFS #161 demeurent distincts.

## B4b — persistance fermée et reconstruction

`ClosedRankingProjectionSchema` définit deux tables privées InnoDB : version de
schéma et génération par origine/politique. Installation explicite seulement
dans l'enclave physique Fans jetable. Aucun bootstrap, cron, route, migration
ou flag de site. Le cache dérivé n'est ni ledger PF ni source de vérité.

`ClosedRankingProjectionStore` compose la lecture et la reconstruction sous le
même mutex/transaction que la promotion du corpus dans `ClosedCorpusInbox`.
Le corpus courant est authentifié avec la confiance Hub actuelle et sa fence
primaire. Un transfert en cours ou refusé interdit l'accès au cache précédent.
Aucun HTTP n'est effectué dans cette transaction locale.

Le document canonique conserve origine, politique, époques, révision et digest,
ainsi que l'instant primaire attesté. La lecture exige l'égalité exacte avec
une reconstruction du corpus courant ; une ancienne génération restaurée avec
son propre hash reste refusée. Suppression et remplacement sont atomiques ;
après COMMIT incertain, la lecture primaire retrouve la génération éventuelle.
Une notification peut déclencher la reconstruction, jamais fournir quantité,
ancien reçu ou génération à promouvoir.

La sérialisation locale range les relevés dans une liste avec un champ `month`,
triée par mois civil. Elle ne relâche pas le codec signé Hub, qui refuse une
clé d'objet telle que `2026-10`. Le calcul et la lecture privée conservent leur
index par mois ; ces octets dérivés ne sont ni un reçu ni un nouveau contrat Hub.

Les corrections H4 rapprochées recomposent les deux familles, le général,
les catégories et le mois d'origine. Litige, résolution et annulation totale
ne changent ni consommation ni date d'origine. Une correction incomplète rend
l'origine indisponible ; une correction plus ancienne ne restaure aucun point.
La génération vide explicitement attestée ne fabrique aucun participant.

Scénarios SQL ajoutés : schéma partiel/MyISAM, concurrence, erreur après DELETE,
COMMIT sans acquittement, transfert incomplet, ancienne génération restaurée,
clé Hub révoquée, corpus vide, net corrigé partiel/total, litige/résolution et
correction désordonnée. La [preuve isolée complète](../evidence/fans-hof-b4-cache/README.md)
du 10 octobre joint 576 contrôles WordPress/MariaDB, dont 28 nouveaux B4b.

Limites : ce cache atteste un instant primaire, aucune fraîcheur future implicite.
Chaque lecture recompose la génération complète : coût linéaire borné par la
recette B3, sans garantie de débit de production. Ouverture réelle enregistrée,
gouvernance de visibilité et de consentement, sessions B5 et UI B6 restent des
raccordements distincts. Aucun rang public ou parcours testable sur site annoncé.
Conservation #150 et RustFS #161 demeurent distincts. Retour arrière : retirer
le code fermé et détruire la fixture ; aucune migration de site à inverser.
