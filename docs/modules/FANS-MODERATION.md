# Panel de modération Fans — administration locale

## Périmètre implémenté

Sous-page **Faluss → Modération Fans**, uniquement sur le rôle Fans lorsque le
module texte est disponible (flags SSO/profils/textes et schémas existants).
La vue images exige en plus la quarantaine disponible. Aucun nouveau flag,
schéma, moteur de décision, cron ou droit de diffusion. Le flag distinct
`FALUSS_PLATFORM_FANS_IMAGE_DELIVERY` reste fermé : la modération ne l’active pas.
Pas d’interface créateur, upload, teaser Me, paiement ou release dans ce lot.

Le panel est un adaptateur WordPress des API existantes, sans accès direct aux
tables des domaines. La capacité **manage_options** est vérifiée pour le menu,
chaque page et l’aperçu ; les permissions REST sont encore exécutées par
`rest_do_request`. Le rôle administratif ne requiert pas un profil créateur SSO.

## Parcours réel

- Textes : page de 20 `pending` au plus, curseur existant, plus anciens en premier,
  corps échappé en texte, UUID du créateur, activité du profil, dates UTC, état et
  révision. Aucun e-mail, identité centrale ou compte vérifié n’est déduit de ces
  données. Un profil inactif reste dans la file, avec refus possible mais sans
  option d’approbation. La permission finale appartient toujours au serveur.
- Images : liste privée existante, 20 éléments, **tous états**, ordre UUID ; ce
  n’est pas une nouvelle file chronologique pending. Les images retirées/rejetées
  restent dans la liste pour leur journal, sans aperçu ni nouvelle décision.
- Image associée : affichage d’un bouton d’examen seulement si le contrat privé
  du texte retourne une référence encore éligible. L’ouverture relit la révision
  du texte et l’association ; la route Images contrôle de nouveau droits, profil,
  état et stockage. Une association périmée est refusée, jamais remplacée en silence.
- Avec JavaScript, les images sont affichées à la demande dans le panel : fetch
  authentifié/no-store puis URL blob en mémoire, révoquée à la fermeture, à
  pagehide, au masquage de l’onglet et avant une décision. Pas de polling ni cache
  navigateur ajouté. Sans JavaScript, elles sont examinées dans un nouvel onglet
  par **POST authentifié** à
  `admin-post.php`, action `faluss_fans_preview`. Identifiant et nonce sont dans
  le corps du formulaire, jamais un lien vers un fichier, un jeton dans une URL
  ou un chemin de stockage. Réponse PNG de quarantaine uniquement après succès
  de l’API administrative existante ; CSP sandbox, nosniff, CORP same-origin.
- Approbation : motifs existants `allowed_text` / `allowed_image`. Refus :
  `prohibited_content` / `needs_revision`. Un choix explicite est obligatoire.
  Aucun champ libre de notes ou nouveau motif. Le refus purge le contenu courant
  selon le moteur concerné. L’approbation texte peut le rendre public selon la
  politique existante ; l’approbation image n’ouvre pas le flag de diffusion.
- Journal repliable : révision, acteur WordPress, action, motif, date UTC.
  Textes : 100 dernières traces au maximum ; images : journal existant complet.
  Une fiche administrative par UUID reste accessible après sortie de la file,
  avec état courant et journal ; aucune lecture publique de cette fiche.
  Pas de reconstitution du texte ou des octets supprimés, ni archive de preuve.

La navigation et les décisions fonctionnent **sans JavaScript**. Formulaires POST
avec nonce `fans_moderation` / `fans_preview`, puis nonce REST interne lié au compte
WordPress courant. Un nonce n’accorde jamais une capacité. Révisions et états
restent contrôlés transactionnellement par les moteurs existants, sans réécriture
de leurs invariants. Un double envoi n’ajoute pas une seconde décision sur la même
révision. Le message de succès n’est rendu qu’après HTTP 200 du moteur ; `409`
demande de relire, `400/403/404/503` ne sont pas transformés en réussite.

Après POST, le panel rend la réponse et la file depuis le début. Un rechargement
peut rejouer le POST et produire un conflit, sans nouvelle mutation ; le lien
« Recharger la file » utilise GET. Après perte de réponse, relire le journal avant
toute nouvelle décision. `image_cleanup_required` peut signifier une révocation
déjà commitée avec suppression physique échouée : ne pas affirmer son succès
complet. L’API administrative de nettoyage reste disponible, sans nouveau bouton
de purge globale dans ce panel.

## Confidentialité et limites de concurrence

Pages privées et aperçus : `Cache-Control: private, no-store`,
`CDN-Cache-Control: no-store`, `Surrogate-Control: no-store`, aucune persistance
localStorage, cookie de contenu, URL publique ou cache partagé ajouté. Aucun
appel à une police externe : Outfit variable embarquée sous SIL OFL, provenance
`google/fonts`, `ofl/outfit/Outfit[wght].ttf`, licence dans `assets/fonts/`.
Styles limités à `.faluss-moderation`, fond #FFFDF5, cartes blanches ; aucun
changement global de l’administration ou du thème WordPress.

Le navigateur et l’administrateur reçoivent réellement les octets. Fermer l’onglet
après inspection ; une copie ou capture déjà téléchargée ne peut pas être rappelée.
Une requête déjà autorisée peut se terminer pendant un retrait/suspension. Les
lectures de l’association et des octets sont deux opérations séparées : aucune
promesse de snapshot global ou de contrôle continu d’un onglet ouvert. Recharger
avant de décider ; les versions du texte et de l’image restent indépendantes.
La pagination est sans doublon/omission sur jeu inchangé ; ce n’est pas un snapshot
de file sous modifications. Repartir du début pour retrouver les éléments modifiés.

L’hébergeur doit exclure pages admin, POST et routes privées de tout cache/proxy,
ne pas journaliser les corps/nonces/cookies, protéger sessions et postes des
modérateurs. Les tests locaux ne constituent pas une attestation d’absence
d’alias HTTP, de cache intermédiaire ou de sauvegarde publique en production.

## Procédures humaines restant à définir

Ce panel permet l’examen manuel et les décisions techniques déjà prévues ; il ne
détecte pas automatiquement tous les contenus adultes/interdits, ne vérifie ni
identité légale ni droits d’auteur, consentements ou majorité. Avant ouverture :

- charte de contenus, guide de décision, formation et protection des modérateurs ;
- habilitations, retrait d’accès, responsabilité de l’acteur et éventuelle double
  revue (aucune séparation des pouvoirs ni verrou d’assignation ici) ;
- escalade des contenus suspects, incidents, signalements, contestations, délais
  de traitement/purge et procédure de réexamen après erreur ;
- règles de conservation des journaux, fichiers, sauvegardes et éventuelles
  preuves légales, sans conserver automatiquement les contenus interdits ;
- surveillance des échecs de nettoyage et reprise par l’API existante, hébergement
  attesté et tests de cache réels ; aucun SLA, notification ou purge automatique.

## Activation et retour arrière

Installation du code ne change aucun flag ni donnée. Sur l’instance jetable,
les flags textes/quarantaine peuvent être temporairement activés pour recette ;
diffusion reste false. Après recette : tous fermés et workers arrêtés.
En production, conserver les flags fermés. Fermer le flag texte retire le panel
et son action d’aperçu ; fermer la quarantaine rend sa vue indisponible. Un retour
au commit précédent retire l’interface sans migration ni suppression de traces.

Voir la [recette locale](../../tests/Fans/Publications/recipe/MODERATION-PANEL.md),
les [textes](FANS-PUBLICATIONS.md), les [images privées](FANS-IMAGES.md) et les
[conditions distinctes de diffusion](FANS-IMAGE-DELIVERY.md).
