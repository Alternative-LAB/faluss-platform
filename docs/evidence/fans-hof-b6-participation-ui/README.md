# B6 — participation et recours : recette native acquise

Source747f5d27481c019e2b4d333dad63729fdb2f7282, arbre59006ff8698f82614ea43afa6f16f5d608b7dd9c. [Empreintes des sources](source-proof.json), [contrat et limites](../../modules/FANS-HOF-B6-PARTICIPATION-UI.md). Les preuves seules sont ajoutées ensuite ; contrôles du head final puis main intégrés distincts.

## Résultats

| Vérification | Résultat |
|---|---|
| [WordPress/MariaDB physiques](physical-checks.json) |882 contrôles, zéro échec, dont155 B6 ; fixture_removed=true |
| [Navigateur réel sur routes jetables](browser-checks.json) | Chrome151.0.7922.34 ; deux parcours,91 mesures de layout,16 PNG, zéro échec |
| [Qualité locale](quality-proof.json) |810 tests/8219 assertions, HoF90/323, PHPStan0 ciblant80300 ; deux dépréciations historiques |
| Environnement | WordPress7.1.2/PHP8.5.4/MariaDB11.8.6 ; identités, comptes et données de recette fictifs |

Six largeurs :320,375,390,430,768 et1440px. Clavier desktop et toucher mobile émulé, contrôle des noms accessibles, du focus de chaque contrôle, d'Outfit et des débordements. Navigation Créateur à huit accès conservée. Toutes les16 captures ci-dessous ont été examinées personnellement.

Le parcours desktop soumet le consentement, obtient l'admission d'organisateur, se retire puis vérifie le retour navigateur. Le parcours mobile exerce refus, recours, décision favorable réelle du modérateur, admission, perte de réponse de fermeture après COMMIT puis reprise de la même action, retrait et retour navigateur. Les images mobiles cadrent le contrôle utile par défilement natif, sans recadrage ou retouche d'image.

Les vérifications HTTP couvrent en plus les acteurs exclus, nonce/consentement/règles altérés, décisions concurrentes, dossiers historiques, vingt et une demandes réelles sur deux pages et conservation du curseur après POST303GET. Le cycle croisé suspend et réadmet réellement une session : GET inerte, ancienne admission non confirmée pour le nouveau parent, fermeture incertaine puis admission future sous consentement inchangé et retrait.

## Captures

| État | Ordinateur | Mobile |
|---|---|---|
| Demande volontaire | [1440](screenshots/participation-join-1440.png) | [390](screenshots/participation-join-390.png) |
| Demande en attente | [1440](screenshots/participation-requested-1440.png) | [390](screenshots/participation-requested-390.png) |
| File organisateur | [page1440](screenshots/creator-participation-queue-1440.png), [contrôles1440](screenshots/creator-participation-controls-1440.png) | [390](screenshots/creator-participation-queue-390.png) |
| Refus et recours | Sans objet pour ce parcours desktop | [refus390](screenshots/participation-refused-390.png), [en examen390](screenshots/participation-appeal-pending-390.png) |
| Panel modérateur | [dossier examiné1440](screenshots/moderator-participation-decided-1440.png) | [décision390](screenshots/moderator-participation-pending-390.png) |
| Admission confirmée | [1440](screenshots/participation-admitted-1440.png) | [390](screenshots/participation-admitted-390.png) |
| Réponse de retrait incertaine | Sans objet pour ce parcours desktop | [390](screenshots/participation-closing-390.png) |
| Retrait attesté | [1440](screenshots/participation-withdrawn-1440.png) | [390](screenshots/participation-withdrawn-390.png) |

## Adaptation de transport et échecs conservés

La recette existante sert le loopback en HTTP mais réécrit les liens HTML en HTTPS pour satisfaire les gardes applicatives. Le retour303 du panel gardait HTTPS : le navigateur de capture refusait cette origine. Le helper externe, hors CI, normalise uniquement Location303 de l'exact même hôte/port loopback et chemin/app/admin ; POST, nonce, cookies, corps, statut et autorisations proviennent toujours du vrai WordPress. Cette unique adaptation est tracée dans redirectTransport du rapport. Aucune garde HTTPS de production, aucun préparateur, budget ou workflow modifié. Cette preuve ne valide donc pas TLS ou le véritable SSO.

Les échecs de recette précédents restent dans les notes : retour navigateur rejouant le POST (corrigé dans l'application par303GET), attente manquante après tap du lien de recours (helper), puis incohérence HTTP/HTTPS de Location. Un faux positif du helper tabulait de l'unique bouton vers body ; le contrôle final inspecte désormais tous les vrais contrôles après passage clavier. Aucun résultat incomplet n'est présenté comme réussite globale.

Le diagnostic local conserve huit avertissements WordPress CLI SERVER_NAME absent dans general-template.php:4122, sans erreur fatale ; aucun changement d'environnement pour les masquer. Ils ne sont pas une preuve de défaut de site cible.

## Écarts et périmètre restant

La DA Fans sombre/menthe/Outfit et les composants existants sont réutilisés. Ces formulaires de gestion ne reproduisent pas les classements photographiques fictifs de la planche ; aucun portrait, rang ou point supplémentaire n'est fabriqué. Les listes utilisent les seules présentations approuvées ; les comptes de recette portent volontairement des noms fictifs explicites.

Les territoires et choix d'intention ne sont pas encore raccordés. Aucun véritable SSO Me, thème/Elementor cible, appareil ou clavier physique, achat, score public, flag, migration de site ou publication. Le cycle croisé de réadmission est prouvé par HTTP natif ; les deux parcours photographiés couvrent les étapes listées, pas une recette TLS réelle. #150 et RustFS #161 distinctes.
