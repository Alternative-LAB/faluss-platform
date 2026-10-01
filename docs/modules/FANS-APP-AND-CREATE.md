# Entrée `/app` et interaction Créer

Sur le module UI Fans déjà ouvert, `/app` résout le rôle serveur : invité vers
Explorer public, membre lié sans profil vers l'accueil Fan, propriétaire d'un
profil vers l'accueil Créateur. Un profil en attente/suspendu ne reçoit aucun
droit d'écriture supplémentaire ; les services restent l'autorité.

Routes humaines : `/app/fan/{écran}`, `/app/creator/{écran}`,
`/app/creators/{id}`. Les anciennes routes `/faluss-fans/…` correspondantes
redirigent en 302 sans cache (307 pour préserver un POST existant). Seuls les
paramètres de sélection connus sont repris. Une ancienne URL de retour SSO
scellée reste admise et bénéficie ensuite de cette redirection. Le callback
`/faluss-fans/sso/callback`, les actions admin-post et les REST restent inchangés.
Le shortcode sans destination explicite prépare désormais `/app` ; un compte
déjà lié reçoit un lien visible vers son application, pas un nouveau POST SSO.
La liste blanche de retour, sa signature liée au state, PKCE et les nonces
restent contrôlés. Aucune redirection vers une origine fournie par le navigateur.

Mise à niveau : règles additives et flush WordPress doux une seule fois,
marqueur technique `faluss_fans_ui_routes=app-v1`, uniquement si le module UI
est disponible. Aucun flag de production changé. Réserver le chemin `/app`
dans la configuration de pages lors de la recette cible.

## Création

Le rail garde ses huit accès. Avec JavaScript, Créer ouvre un sélecteur dans
le contenu assombri ; le rail reste visible et non assombri. Le dialogue retient
le focus, propose une fermeture, Échap et un retour navigateur ; la fermeture
restitue le focus au déclencheur. Les animations respectent la réduction de
mouvement. Sur mobile, la navigation reste visible sous la zone du dialogue.
Sans JavaScript, le lien Créer ouvre toujours sa route de gestion existante.

Publication : formulaire natif réel avec nonce, clé d'idempotence et modération
existante. Émojis Unicode natifs sans téléchargement externe. L'association
d'une image déjà approuvée reste une seconde action depuis la publication
enregistrée ; le compositeur l'annonce, il ne prétend pas téléverser un média.
Prestation, Produit et Service présentent des champs distincts, mais aucun
formulaire d'envoi ni endpoint commercial. Produit expose titre, description,
photos et prix ; ces deux derniers contrôles restent indisponibles. Aucun
brouillon commercial n'est conservé par ces aperçus.

Accueil, Explorer et HoF conservent leurs bannières. Les autres pages ont un
en-tête compact avec le même conteneur. Le texte d'attente HoF ne décrit plus
le moteur interne. Les dimensions du bouton SSO, le label actif/survol/focus et
le conteneur stabilisé de la livraison précédente sont conservés.

## Preuves

[Captures et compte rendu WordPress isolé](../evidence/fans-app-create/README.md).
Ces preuves ne sont pas une recette Elementor ou SSO du site cible.
