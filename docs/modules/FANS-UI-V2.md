# Fans UI V2 — matrice et premier lot

La planche Fan compte huit écrans et la planche Créateur dix écrans. Elles fixent
la composition visuelle, jamais les données ni les capacités. Les routes ci-dessous
sont des chemins WordPress sous `faluss-fans/`, distincts des routes REST et du
retour SSO `faluss-fans/sso/callback`. Le préfixe `fan` ou `creator` désigne le
shell demandé ; le droit Créateur est déduit du profil local lié au SSO, pas d'un
rôle WordPress privilégié. Le profil public est une seule route canonique commune.

## Matrice avant implémentation

| Planche | Écran | Route retenue | Accès | API ou capacité présente | Livrable du lot 1 | État fermé si absent |
| --- | --- | --- | --- | --- | --- | --- |
| Fan 01 | Hall of Fame — accueil | `/faluss-fans/fan/hof` | Membre Fans lié | Simulateur HoF v3 hors runtime seulement | Shell, navigation | Aucune session, aucun score ni rang affiché |
| Fan 02 | Session HoF | `/faluss-fans/fan/hof/session` | Membre Fans lié | Aucune session persistante | Shell | Session indisponible, aucune attribution PF |
| Fan 03 | Classements | `/faluss-fans/fan/classements` | Membre Fans lié | Aucun classement persistant | Shell | Aucun rang ou score inventé |
| Fan 04 | Profil créateur public | `/faluss-fans/creators/{creator_id}` | Membre Fans lié | `GET /creators/{creator_id}`, profils actifs uniquement | Détail structuré via REST | 404 ou erreur et aucun profil suspendu ; suivi/PC fermés |
| Fan 05 | Messagerie | `/faluss-fans/fan/messages` | Membre Fans lié | Aucun moteur de messages | Shell | Aucun fil ni envoi factice |
| Fan 06 | Accueil Fans | `/faluss-fans/fan/accueil` | Membre Fans lié | Profils structurés publics seulement ; pas de flux assemblé | Shell, lien vers Explorer | Aucun faux flux, chiffre ou récompense |
| Fan 07 | Explorer | `/faluss-fans/fan/explorer` | Membre Fans lié | `GET /creators`, catégories fermées, au plus 20 profils actifs | Liste et filtres via REST, états vides/erreurs | Si API indisponible, message clair ; pas de portraits ni de recherche simulée |
| Fan 08 | Espace personnel | `/faluss-fans/fan/espace` | Membre Fans lié | Aucun moteur PC/progression | Shell | Aucun solde ni claim PF renommé PC |
| Créateur 01 | Hall of Fame — accueil | `/faluss-fans/creator/hof` | Membre lié avec profil créateur | Simulateur v3 hors runtime | Shell | Aucun point HoF, session ni rang inventé |
| Créateur 02 | Session HoF | `/faluss-fans/creator/hof/session` | Même accès | Aucune session persistante | Shell | Session indisponible |
| Créateur 03 | Classements | `/faluss-fans/creator/classements` | Même accès | Aucun classement persistant | Shell | Aucun score ou rang inventé |
| Créateur 04 | Profil public | `/faluss-fans/creators/{creator_id}` | Même route canonique que Fan 04 | `GET /creators/{creator_id}` | Détail public, uniquement si actif | Le profil propre `pending`/`suspended` ne devient pas public |
| Créateur 05 | Messagerie | `/faluss-fans/creator/messages` | Même accès | Aucun moteur de messages | Shell | Aucun fil ni envoi factice |
| Créateur 06 | Accueil Fans | `/faluss-fans/creator/accueil` | Même accès | Profil propriétaire via `GET /creators/me`, mais pas de flux agrégé | Shell | Aucun résumé chiffré inventé |
| Créateur 07 | Explorer | `/faluss-fans/creator/explorer` | Même accès | `GET /creators` | Même composant Explorer, shell Créateur | Filtres limités aux catégories prises en charge |
| Créateur 08 | Progression | `/faluss-fans/creator/progression` | Même accès | Simulateur v3 hors runtime | Shell | Aucun wallet, montant PF, revenu, score ou rang inventé |
| Créateur 09 | Créer | `/faluss-fans/creator/creer` | Même accès | Publication texte modérée seulement ; pas de formulaire dans ce lot | Quatre choix distincts, tous fermés dans ce lot | Publication, prestation, service et produit sans action factice |
| Créateur 10 | Ma boutique | `/faluss-fans/creator/boutique` | Même accès | Catalogue structuré administrateur ; achat refusé 403/503 | Shell | Aucune commande, réservation, panier ou prix |

La navigation Créateur inclut en plus **Mon profil** à
`/faluss-fans/creator/mon-profil` : espace de gestion distinct de la route
publique de l'écran 04. Le lot 1 y montre uniquement un état fermé ; un lot
ultérieur pourra lire `GET /creators/me` avec nonce. Aucun formulaire éditorial
de profil n'existe dans le contrat actuel.

## Admission et scénarios du lot 1

Le shell est opt-in par `FALUSS_PLATFORM_FANS_UI=true`, sur le seul rôle de site
`fans`, après SSO Fans prêt. Le flag est fermé par défaut. Les routes de gestion
Créateur exigent un profil local propre, y compris `pending` ou `suspended` ;
ce statut ne donne jamais accès à un profil public ni à une vente. Un visiteur
non lié ne voit aucun shell privé. Les lectures Explorer et profil public
réutilisent les routes REST existantes ; les erreurs et retraits remplacent les
cartes, sans conserver une réponse périmée.

Sur un plugin déjà actif, la mise en place contrôlée du flag demande une
réactivation pour enregistrer les règles de réécriture. Le lot ne change aucune
configuration de site. Retirer le flag coupe l'enregistrement des routes et
conserve les données des modules existants ; une désactivation contrôlée du
plugin rafraîchit les règles. Aucun schéma ou migration n'est ajouté.

Scénarios positifs : un membre lié ouvre chaque route Fan ; un créateur lié
ouvre les huit accès de sa sidebar ; Explorer affiche les seuls profils actifs
répondus par l'API ; un profil actif s'ouvre par son UUID public ; le clavier
atteint chaque lien et indique la destination active. Sur mobile, les huit
accès Créateur restent nommés et visibles.

Scénarios négatifs : flag/rôle/SSO absents, membre anonyme ou non lié et accès
Créateur sans profil ne révèlent aucun shell ; `pending`/`suspended` et UUID
inconnu ne fournissent pas de profil public ; API désactivée, erreur réseau,
réponse mal formée et liste vide montrent des états distincts sans faux contenu ;
les destinations sans moteur ne proposent aucune mutation ; aucun chemin ne
transforme PF historiques en PC ni un score HoF en PF ou en revenu.

## Ordre des PR suivantes

1. Accueil Fans et profil public enrichis uniquement après contrats de contenu,
   images approuvées et diffusion effectivement actifs, avec retraits/suspensions.
2. Création de publications textuelles : formulaire, admission, modération,
   brouillons et gestion serveur existants ; les trois autres types restent
   fermés jusqu'à leur propre moteur.
3. Gestion du profil Créateur et catalogue structuré, dans leurs permissions ;
   boutique sans transaction tant que commande, réservation et paiement manquent.
4. HoF, classements et progression après moteur persistant et décisions PF/PC
   ratifiées ; messagerie après contrat de contenu, blocage et signalement.

Ce découpage ne change aucun flag de production, table, paiement ou contrat Hub.

## Recette navigateur locale

`tests/Fans/Ui/recipe/router.php` fournit un serveur de test pour le shell,
les permissions simulées et l'API de profils avec UUID synthétiques. Depuis la
racine du dépôt, lancer `php -S 127.0.0.1:8765 -t .
tests/Fans/Ui/recipe/router.php`, puis `node tests/Fans/Ui/recipe/browser.cjs`
avec Playwright dans `NODE_PATH`. Les [captures](../evidence/fans-ui-v2/README.md)
montrent les vues réellement livrées. La recette vérifie Chrome ordinateur et
mobile, navigation clavier, route active, filtres, profil public, état vide,
erreur API et refus d'accès. Elle ne prouve pas l'intégration sur WordPress réel,
la liaison Me en réseau, Elementor, Safari physique ou les droits en production.
