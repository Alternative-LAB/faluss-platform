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
| Fan 01 | Hall of Fame — accueil | `/faluss-fans/fan/hof` | Invité, Fan lié, Créateur lié | Simulateur HoF v3 hors runtime seulement | Lecture publique et navigation ; état indisponible | Aucune session, aucun score, rang ou point affiché |
| Fan 02 | Session HoF | `/faluss-fans/fan/hof/session` | Membre Fans lié | Aucune session persistante | Shell | Session indisponible, aucune attribution PF |
| Fan 03 | Classements | `/faluss-fans/fan/classements` | Membre Fans lié | Aucun classement persistant | Shell | Aucun rang ou score inventé |
| Fan 04 | Profil créateur public | `/faluss-fans/creators/{creator_id}` | Invité, Fan lié, Créateur lié | `GET /creators/{creator_id}`, profils actifs uniquement | Fiche structurée via REST, sans nom ni portrait approuvé | HTTP 404 sur la page et l’API si absent, suspendu ou retiré ; suivi/PC fermés |
| Fan 05 | Messagerie | `/faluss-fans/fan/messages` | Membre Fans lié | Aucun moteur de messages | Shell | Aucun fil ni envoi factice |
| Fan 06 | Accueil Fans | `/faluss-fans/fan/accueil` | Membre Fans lié | Profils structurés publics seulement ; pas de flux assemblé | Shell, lien vers Explorer | Aucun faux flux, chiffre ou récompense |
| Fan 07 | Explorer | `/faluss-fans/fan/explorer` | Invité, Fan lié, Créateur lié | `GET /creators`, catégories fermées, au plus 20 profils actifs | Fiches structurées et filtres via REST, états vides/erreurs | Découverte provisoire déclarée ; aucun UUID visible, nom, portrait ou recherche simulée |
| Fan 08 | Espace personnel | `/faluss-fans/fan/espace` | Membre Fans lié | Aucun moteur PC/progression | Shell | Aucun solde ni claim PF renommé PC |
| Créateur 01 | Hall of Fame — accueil | `/faluss-fans/creator/hof` | Invité, Fan lié, Créateur lié ; shell selon la liaison réelle | Simulateur v3 hors runtime | Même lecture publique et état indisponible | Aucun point HoF, session ni rang inventé |
| Créateur 02 | Session HoF | `/faluss-fans/creator/hof/session` | Créateur lié avec profil local | Aucune session persistante | Shell | Session indisponible |
| Créateur 03 | Classements | `/faluss-fans/creator/classements` | Créateur lié avec profil local | Aucun classement persistant | Shell | Aucun score ou rang inventé |
| Créateur 04 | Profil public | `/faluss-fans/creators/{creator_id}` | Même route publique que Fan 04 | `GET /creators/{creator_id}` | Fiche publique, uniquement si active | Le profil propre `pending`/`suspended` ne devient pas public |
| Créateur 05 | Messagerie | `/faluss-fans/creator/messages` | Créateur lié avec profil local | Aucun moteur de messages | Shell | Aucun fil ni envoi factice |
| Créateur 06 | Accueil Fans | `/faluss-fans/creator/accueil` | Créateur lié avec profil local | Profil propriétaire via `GET /creators/me`, mais pas de flux agrégé | Shell | Aucun résumé chiffré inventé |
| Créateur 07 | Explorer | `/faluss-fans/creator/explorer` | Invité, Fan lié, Créateur lié ; shell selon la liaison réelle | `GET /creators` | Même composant Explorer, navigation publique seule pour l’invité | Filtres limités aux catégories prises en charge |
| Créateur 08 | Progression | `/faluss-fans/creator/progression` | Créateur lié avec profil local | Simulateur v3 hors runtime | Shell | Aucun wallet, montant PF, revenu, score ou rang inventé |
| Créateur 09 | Créer | `/faluss-fans/creator/creer` | Créateur lié avec profil local | Publication texte modérée seulement ; pas de formulaire dans ce lot | Quatre choix distincts, tous fermés dans ce lot | Publication, prestation, service et produit sans action factice |
| Créateur 10 | Ma boutique | `/faluss-fans/creator/boutique` | Créateur lié avec profil local | Catalogue structuré administrateur ; achat refusé 403/503 | Shell | Aucune commande, réservation, panier ou prix |

La navigation Créateur inclut en plus **Mon profil** à
`/faluss-fans/creator/mon-profil` : espace de gestion distinct de la route
publique de l'écran 04. Le lot 1 y montre uniquement un état fermé ; un lot
ultérieur pourra lire `GET /creators/me` avec nonce. Aucun formulaire éditorial
de profil n'existe dans le contrat actuel.

## Admission et scénarios du lot 1

Le shell est opt-in par `FALUSS_PLATFORM_FANS_UI=true`, sur le seul rôle de site
`fans`, après SSO Fans prêt. Le flag est fermé par défaut. Les routes de gestion
Créateur exigent un profil local propre, y compris `pending` ou `suspended` ;
ce statut ne donne jamais accès à un profil public ni à une vente. Explorer,
la lecture HoF et la fiche publique sont accessibles sans connexion SSO. Un
visiteur voit seulement Explorer et HoF ; Fan et Créateur connectés voient leur
navigation respective. Explorer et le profil réutilisent les routes REST
existantes ; HoF affiche un état fermé sans moteur. Pour le profil,
la page publique vérifie aussi le profil actif avant son rendu et répond 404
si le profil est inexistant, suspendu ou retiré. Les erreurs et retraits après
chargement remplacent les cartes, sans conserver une réponse périmée.

L’API ne fournit ni nom public ni portrait. Les UUID restent uniquement dans
les liens et attributs techniques. Explorer présente donc des fiches de catégorie
explicitement provisoires, sans prétendre former un catalogue de personnes
identifiables. Aucun nom, initiale personnelle ou portrait n’est inventé.

Sur un plugin déjà actif, la mise en place contrôlée du flag demande une
réactivation pour enregistrer les règles de réécriture. Le lot ne change aucune
configuration de site. Retirer le flag coupe l'enregistrement des routes et
conserve les données des modules existants ; une désactivation contrôlée du
plugin rafraîchit les règles. Aucun schéma ou migration n'est ajouté.

Scénarios positifs : l’invité ouvre les deux routes Explorer, les deux routes
HoF et la fiche active avec une navigation à deux entrées ; le Fan lié ouvre
Explorer, HoF, le profil et ses espaces personnels ; le Créateur lié ouvre
Explorer, HoF, le profil et les huit accès de sa sidebar. Explorer affiche les
seuls profils actifs répondus par l’API ;
un profil actif s’ouvre via son lien technique. Le clavier atteint chaque lien
et indique la destination active. Sur mobile, les huit accès Créateur restent
nommés et visibles.

Scénarios négatifs : flag/rôle/SSO absents ferment le module ; l’invité et le
compte WordPress non lié ne voient aucun espace personnel, tandis que le Fan
sans profil n’accède pas à la gestion Créateur ;
`pending`/`suspended`, profil retiré et UUID inconnu donnent 404 sur la page
elle-même et sur l’API ; API désactivée, erreur réseau,
réponse mal formée et liste vide montrent des états distincts sans faux contenu ;
la session HoF, les classements et les espaces personnels restent soumis au SSO ;
les destinations sans moteur ne proposent aucune mutation ; aucun chemin ne
transforme PF historiques en PC ni un score HoF en PF ou en revenu.

Sur les pages Fans, la barre d’administration WordPress est masquée aux membres
ordinaires, sans changer leur préférence sur les autres pages. Les comptes
dotés de `manage_options` conservent la barre et leurs outils WordPress.

## Ordre des PR suivantes

1. Identité provisoire `Guest_…`, récupération après création de compte et
   retour SSO vers la page d’origine, après contrat de session et de reprise.
   Aucun pseudo, badge de progression ou contribution provisoire n’est créé
   dans ce lot.
2. Accueil Fans et profil public enrichis uniquement après contrats de contenu,
   images approuvées et diffusion effectivement actifs, avec retraits/suspensions.
3. Création de publications textuelles : formulaire, admission, modération,
   brouillons et gestion serveur existants ; les trois autres types restent
   fermés jusqu'à leur propre moteur.
4. Gestion du profil Créateur et catalogue structuré, dans leurs permissions ;
   boutique sans transaction tant que commande, réservation et paiement manquent.
5. HoF, classements et progression après moteur persistant et décisions PF/PC
   ratifiées ; messagerie après contrat de contenu, blocage et signalement.

Ce découpage ne change aucun flag de production, table, paiement ou contrat Hub.
Le paiement invité reste un chantier contractuel distinct.

## Recettes navigateur locales

La recette réelle `tests/Fans/Ui/recipe/real-wp.py` copie le cœur WordPress
7.1.2 local et le plugin de cette branche dans une base MariaDB jetable. Elle
requiert WSL root, `php`, `mariadb`, `openssl`, Chrome/Playwright et les assets
locaux `/var/tmp/faluss-v3-wp/wordpress` et `wp-cli.phar`. Elle active
SSO, profils et UI **dans cette instance seulement**, avec des liens SSO et profils
synthétiques ; les autres flags restent fermés. Un proxy HTTPS local permet les
cookies WordPress réels. Chrome vérifie séparément invité, compte WordPress
non lié, Fan lié, Créateur lié et administrateur, les permissions, la barre
d’administration, la lecture HoF invitée, les statuts HTTP de page et REST,
les assets CSS/JS et le parcours Explorer → profil public en ordinateur et
mobile. La recette détruit son site et sa base à la fin. Commande :
`wsl.exe -u root -- python3 /mnt/c/Users/dylan/OneDrive/Documents/ChatGPT/FALUSS-fans-ui-v2/tests/Fans/Ui/recipe/real-wp.py`.
Le chemin du checkout et les exécutables Node/Playwright peuvent être adaptés
par `FANS_UI_NODE` et `FANS_UI_NODE_PATH` dans WSL.

La recette synthétique `router.php` + `browser.cjs` garde les états vide et
erreur API, le focus clavier et les huit liens Créateur. Les [captures réelles
et synthétiques](../evidence/fans-ui-v2/README.md) séparent ces deux preuves.
Ni la liaison Me en réseau, ni Elementor, Safari physique, les droits en
production ou une validation visuelle humaine finale ne sont prouvés ici.
