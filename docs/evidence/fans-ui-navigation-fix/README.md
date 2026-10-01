# Fans — correction navigation, compositeur et session locale

## Périmètre et environnement

Base examinée : `48af3b184cfaeaa8e10c6185f127569ae845981b` (0.12.0).
Recettes **isolées**, WordPress 7.1.2, Twenty Twenty-Five, PHP 8.5.4,
MariaDB 11.8.6, Chrome 154. Copies physiques du plugin et de vendor, dépendances
comparées au lock. Aucun site, secret, compte ou flag de production consulté.
Les noms et images de recette sont synthétiques. Aucune capture ne prouve le rendu
Elementor de fans.faluss.me ; installation et recette cible restent à l’exploitant.

## Causes reproduites et changements

- Sidebar 0.12.0 : cases 58 px sauf Shop 66 px. La règle tardive replaçait le label
  dans le flux ; son retour à la ligne changeait la hauteur même lorsqu’il était
  masqué. Cases 64 × 58, pas vertical 66 px ; 48 px sur écran ordinateur très bas.
  Label flottant au survol/focus uniquement, actif graphique, nom accessible.
  Sur tactile, premier toucher affiche le nom, second active le lien.
- Logo : copie exacte de FANS-SV-XS.svg, sans cercle ni lettre substituée.
- Image renvoyait vers l’ancienne page et perdait le texte. Le compositeur conserve
  désormais le brouillon en mémoire et ouvre le choix/dépôt privé sur place.
  Seules les images approuvées sont sélectionnables ; dépôt, aperçu, association
  et modération utilisent les vraies API existantes.
- À scrollY 1504, le verrou de scroll sur body faisait sortir la sidebar sticky
  du viewport (y = -1504). Le verrou porte sur le viewport, avec restauration de
  l’état précédent et du scroll ; un seul état d’historique et une seule superposition.
- /app/creator/creer garde une fonction autonome : gestion des publications,
  archives et formulaire HTML sans JS. Les quatre anciennes cartes sont retirées.
  Galerie indépendante /app/creator/images ; anciennes sélections image redirigées
  en 302 GET ou 307 POST, ancien fragment #fu-images redirigé par le navigateur.
  Sans JS, ce fragment ancien reste sur la gestion des publications, dont le lien
  explicite ouvre la galerie. Callback et API SSO inchangés.
- Les quatre commentaires internes demandés ont été retirés des rendus partagés.
  Les instructions d’action et les contrôles métier sont conservés.

La création du texte et l’association de l’image sont deux transactions existantes.
Si le retrait concurrent d’une image empêche l’association, le texte enregistré
reste en attente, le résultat partiel est annoncé et le renvoi est verrouillé.
Aucune publication n’est présentée comme annulée ou publique à tort. La galerie
ne transforme jamais directement un fichier privé en asset public.

## Session, déconnexion, changement de compte

Nouvelle session locale liée : 28 800 secondes après la connexion, sans prolongation
par visite. Le cookie persistant expire exactement comme sa signature et le jeton
WordPress, sans les douze heures de grâce ajoutées habituellement au cookie persistant.
Les sessions déjà ouvertes ne sont pas prolongées. Admin WordPress et Me inchangés.

POST admin-post.php?action=faluss_fans_logout : nonce et membre lié requis.
wp_logout détruit le jeton courant ; les cookies WordPress et de flux SSO sont
effacés. Retour public Explorer 303, ou réponse JSON du même contrôle pour JS.
Les autres navigateurs et sites ne sont pas déconnectés.

Les pages privées restent no-store. La couche navigateur retire leur DOM et leurs
aperçus Blob à la déconnexion, à l’échéance et à la sortie ; BroadcastChannel/storage
avertissent les autres onglets sans identité ni jeton. Un onglet masqué est caché ;
son retour vérifie /faluss-fans/v1/session avec nonce REST avant affichage.
Le retour bfcache recharge le serveur. Sans JS, le POST natif et l’invalidation
serveur fonctionnent ; aucune application ne peut effacer une copie faite auparavant
par l’utilisateur ni contrôler un cache tiers qui ignore no-store.

Identity n’implémente pas de sélecteur ni prompt=select_account. Après déconnexion
locale, l’aide invite explicitement à ouvrir Me, s’y déconnecter volontairement,
puis choisir le compte voulu avant de revenir. Aucun logout central automatique.
Une session Me ouverte peut reconnecter la même identité avec son consentement mémorisé.

## Diagnostic du refus SSO

| Recette réelle locale | Résultat |
| --- | --- |
| Flux propre unique, autorisation Me + échange + callback Fans | Succès |
| Deux flux avec cookie navigateur commun, ancien callback d’abord | Ancien refusé ; son effacement du cookie fait aussi refuser le nouveau |
| Deux flux avec cookie commun, nouveau callback d’abord | Nouveau accepté ; ancien refusé |
| Cookie rejoué après déconnexion ou jeton expiré en base | Page privée et API refusées |
| Session administrateur | Bouton SSO masqué, comportement attendu |

Les flux partagent un cookie unique de vérification. Cette cause est reproduite
dans la recette ; elle **ne démontre pas la cause sur le site cible**.
Le protocole reste fermé et inchangé. Réessayer dans un seul onglet/flux après
fermeture des retours précédents ; ne communiquer ni callback complet, code ou cookie.
La session Me initiale est préparée par fixture : aucun envoi OTP n’est testé.

## Vérifications et captures

- [Mesures avant](before.json), [scénarios UI](browser.json),
  [HTTP/session](session-http.json), [navigateur/session](session-browser.json).
- 320, 390, 700, 701, 1024 et 1440 px : Fan/Créateur, toutes les cases, noms
  au focus/survol, absence de déplacement du contenu et de débordement.
- Publication → Image → retour, texte inchangé, scroll, cycles de fermeture/réouverture,
  Échap, historique arrière/avant, navigation latérale, premier/deuxième toucher.
- Vrai upload privé → attente → décision de modération → aperçu autorisé → sélection
  → publication et association en attente ; invités et autre propriétaire refusés.
  Retrait concurrent = résultat partiel honnête, pas de renvoi duplicatif.
- Connexion HTTPS réelle : cookie signé = échéance cookie = jeton serveur = huit heures.
  Pas de renouvellement par visite ; fermeture/réouverture réelle du processus Chrome.
- GET/nonce forgé refusés ; POST natif et JS acceptés ; ancien cookie refusé serveur,
  autre onglet et retour navigateur sans vue privée. Me reste connecté.
- Les assertions automatisées ne remplacent pas la recette cible, Safari physique,
  les extensions Elementor ou les caches tiers.

| État | Ordinateur | Mobile |
| --- | --- | --- |
| Créateur normal | [1440](creator-normal-1440.png) | [390](creator-normal-390.png) |
| Créateur focus | [1440](creator-focus-1440.png) | [390](creator-focus-390.png) |
| Créateur survol | [1440](creator-hover-1440.png) | [390](creator-hover-390.png) |
| Fan normal | [1440](fan-normal-1440.png) | [390](fan-normal-390.png) |
| Fan focus | [1440](fan-focus-1440.png) | [390](fan-focus-390.png) |
| Publication | [1440](publication-1440.png) | [390](publication-390.png) |
| Image | [1440](images-1440.png) | [390](images-390.png) |
| Retour brouillon | [1440](return-1440.png) | [390](return-390.png) |
| Déconnexion près de la cloche | [1440](session-fan-1440.png) | [390](session-fan-390.png) |

[Retour public et changement de compte](logout-390.png).
Les vues avant/après utilisent les mêmes dimensions 1440 × 900 et 390 × 900 ;
la recette session utilise 390 × 844 pour le contrôle additionnel mobile.

## Reproduction locale

Recette UI : tests/Fans/Profiles/recipe/admission-wordpress.py --backoffice --test --keep,
schéma images installé et image PNG de recette ; API locale, sessions dans un fichier
privé. Lancer tests/Fans/Ui/recipe/navigation-composer-wordpress.cjs avec
BASE (127.0.0.1 seulement), SESSION, UPLOAD et OUT. Les tests modifient uniquement
leurs profils/images/publications synthétiques ; ne jamais les lancer sur un site réel.

Recette session : session-wordpress.py --source COPIE_PHYSIQUE --core COEUR_LOCAL
--cli WP_CLI crée deux WordPress, une base MariaDB privée sans réseau et un proxy
HTTPS 127.0.0.1:443. Le port doit être libre. Tous les appels curl forcent loopback
et vérifient le certificat local ; seul le POST token passe entre ces deux fixtures.
Aucun hosts/DNS système modifié. session-http.py --root DOSSIER_JETABLE --cli WP_CLI
produit checks.json (publiable) et browser.json (cookies privés, ne pas publier).
session-browser.cjs utilise ce dernier via SESSION ; Chrome résout uniquement
fans.local.test vers loopback, bloque les autres origines et ignore le certificat
auto-signé de ce navigateur local. Les échanges curl conservent la validation TLS.
Créer STOP dans le dossier jetable pour terminer ses processus ; retirer ensuite
les fichiers privés de cookies, clés et profils de navigateur.

Aucune migration de données. Retour arrière par le paquet précédent sans toucher
aux tables ; les cookies déjà émis conservent leur durée jusqu’à expiration ou
révocation. Les flags fermés le restent.
