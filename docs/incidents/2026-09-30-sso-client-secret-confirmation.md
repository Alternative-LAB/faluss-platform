# Création et rotation d’un secret SSO — incident du 30 septembre 2026

## Cause et reproduction

Base : `7aaecf3d19ad4dafd5799470b142109ba61f8d89`, version 0.9.0.
La trace fournie est reproduite sur un **vrai WordPress 7.1.2 jetable**, PHP 8.5.4,
MariaDB 11.8.6/InnoDB, avec le plugin complet, rôle Me et Identity activés dans la
fixture. Base et socket dédiés, HTTP loopback uniquement, réseau HTTP sortant
WordPress bloqué. Aucun accès à faluss.me, fans.faluss.me ou à leur configuration.

`admin-post.php` charge les callbacks mais ne construit pas l’écran d’administration.
La confirmation incluait `admin-header.php` sans initialiser `$hook_suffix`.
`admin_enqueue_scripts` recevait donc `null`, refusé par le paramètre `string`
de `DashboardModule::enqueueAssets()`. Création et rotation renvoyaient HTTP 500.

Les deux écritures précédaient cet affichage : après l’échec, le client et son hash
existaient ; après la rotation, le hash précédent était déjà remplacé. Aucun secret
brut n’était enregistré pour être relu. Le secret perdu **ne peut pas être récupéré**.

### Case « officiel Faluss.com » : distinction nécessaire

La reproduction, y compris avec `first_party=1` forcé dans le POST d’un client Fans,
enregistre **`first_party=0`** ; la case historique ressort décochée. L’apparence
cochée signalée sur le site n’a donc pas été reproduite. Aucune conclusion n’est
tirée sur sa base réelle, qui n’a pas été consultée.

Le correctif rend explicitement **décochée et désactivée** la case d’un client
existant dont les URI ne sont pas exactement la seule URI
`https://faluss.com/faluss-identity/callback`. Même une valeur historique/manuelle
anormale `1` ne présente plus Fans comme officiel. Enregistrer sa fiche remet le
marqueur à `0`, sans changer le secret. Le serveur continue d’ignorer toute case
forgée pour Fans. Aucune migration ni correction automatique d’autres clients.

## Correctif

- Le Dashboard ignore les hooks `null`, vides ou étrangers.
- La confirmation initialise le menu, le hook et l’écran WordPress natifs.
- Toute la réponse, y compris ses hooks d’en-tête/pied de page, est préparée dans
  un buffer mémoire **avant l’INSERT/UPDATE**. Une exception d’affichage produit
  le retour d’erreur existant sans création ni rotation.
- La réponse contenant le secret est envoyée seulement après réussite SQL. Un
  échec SQL n’expose pas un secret non enregistré. Pas d’option, transient, URL,
  e-mail ou journal contenant le secret brut. Réponse privée `no-store` et
  `Referrer-Policy: no-referrer` ; lien de retour explicite sans secret.
- Autorisation administrateur et nonce conservés ; refus de permission HTTP 403.
  Rotation refusée si le schéma Identity n’est pas prêt.

Une rupture de connexion **après** le commit SQL peut encore perdre l’affichage :
aucun protocole HTTP ne garantit que l’administrateur a reçu/copié le secret. La
reprise reste une rotation explicite, jamais une récupération du hash.

## Recette et limites

`tests/Identity/recipe/sso-admin-wordpress.py` installe un WordPress et une base
éphémères ; `--expect-bug` contre l’archive 0.9.0 reproduit la trace exacte. Le même
script contre le correctif vérifie :

- création et rotation HTTP 200 ; secret présent une seule fois et absent au retour ;
- échange sur le **vrai endpoint token** avec code PKCE court semé pour la recette,
  secret courant accepté, ancien secret HTTP 401, code rejoué HTTP 400 ;
- nonce invalide, invité, abonné WP sans capacité et client inexistant refusés ;
- erreurs d’en-tête, pied de page et écriture SQL : aucun client ajouté, aucun hash
  remplacé, aucun secret affiché ; le secret précédent reste utilisable ;
- Fans toujours non officiel ; anomalie `first_party=1` simulée, affichage inéligible
  et réparation par sauvegarde ; client exact Faluss.com légitime préservé ;
- aucun secret brut dans les journaux de la fixture.

Ce test n’est pas un trajet SSO réseau entre les sites ni une preuve du thème,
d’Elementor ou des extensions de production. La CI ajoute cette recette sur
WordPress 7.1.2 et PHP 8.3 avec archives de test épinglées et empreintes vérifiées.
Les contrôles déjà requis restent inchangés. Les fixtures et outils de test sont
exclus du ZIP publié. [Captures expurgées](../evidence/sso-admin-secret/README.md).

## Reprise sûre du client déjà créé — à effectuer par le propriétaire

1. Mettre à jour Faluss Platform via **l’updater WordPress existant**, vers la version
   corrective publiée par GitHub. Vérifier sa version avant toute nouvelle rotation.
   Ne pas remplacer manuellement le plugin, ne pas recréer un second client.
2. Dans **Réglages → Clients SSO Faluss**, retrouver le client Fans existant et
   conserver son **identifiant**. Vérifier son nom, les scopes nécessaires et
   l’unique URI `https://fans.faluss.me/faluss-fans/sso/callback`.
3. Enregistrer cette fiche : la case « officiel Faluss.com » doit être décochée et
   désactivée. Cette sauvegarde remet `first_party` à `0` sans changer le secret.
4. Cliquer **une seule fois** sur « Générer un nouveau secret ». Vérifier la page de
   confirmation, copier le secret directement dans le stockage serveur sécurisé
   du client Fans existant, puis utiliser le lien « Retour aux clients SSO ».
   Ne pas recharger/rejouer le POST ; ne pas envoyer le secret dans une conversation,
   une capture, Git, une URL ou des options WordPress exportables.
5. La rotation invalide immédiatement le secret précédent. Mettre à jour la
   configuration du seul client Fans concerné, sans modifier les autres clients,
   URI ou clés/salts Identity. Reprendre ensuite la recette SSO déjà prévue, sans
   modifier de flag dans le cadre de cette réparation.
6. Si la confirmation échoue encore, ne pas répéter les créations/rotations en
   boucle. Relever le statut et la trace expurgée, vérifier le retour à la liste.
   Si aucun secret n’a pu être copié malgré un commit réussi, seule une nouvelle
   rotation après diagnostic permet d’en obtenir un utilisable. Ne pas tenter de
   récupérer ou de réutiliser un hash de base comme secret.
