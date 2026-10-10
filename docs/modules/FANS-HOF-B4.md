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

## Frontière de preuve et suite B4b

Ce calculateur pur ne vérifie pas une signature et ne possède pas de stockage.
Son appelant doit exclusivement utiliser la génération courante authentifiée
par l'inbox. Il ne suffit pas de lui fournir un tableau nommé « vérifié ».
Les résultats contiennent des références privées et ne sont pas une API publique.

B4b doit persister une génération reconstruisible en transaction, liée à
l'origine, la politique, l'époque, la révision et l'empreinte du corpus. Il doit
refuser la restauration d'une ancienne génération après correction, y compris
quand une ancienne réponse arrive après la nouvelle. Cette propriété relève
de l'inbox et du futur stockage B4b ; ce calcul pur isolé ne la revendique pas.
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
