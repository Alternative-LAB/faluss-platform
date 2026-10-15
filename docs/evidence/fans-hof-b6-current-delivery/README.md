# B6 — recette conjointe et vues des trois objets

## Résultats sur la branche de #212

Source exécutée : `fc899b4ce697d9d2fbbccad89f91e2e507004ce4`, arbre
`7a2255ae4a8503db7f1d0b5eb9f0598214a42a8a`, export LF immuable.
WordPress **7.1.2**, PHP **8.5.4**, MariaDB **11.8.6**.

- [Recette conjointe](wordpress-checks.json) : **769/769**, failed0, dont
  **42 B6dl**, **18 B6join**, **18 B6tx**, **28 B6pr**, **28 B4b** et
  **16 B5b** ; nettoyage final confirmé par `fixture_removed=true`.
- [Navigateur sur les routes réelles](browser-checks.json) : **48/48** cas
  (huit vues × six largeurs), zéro débordement et erreur JavaScript signalée,
  Outfit chargé, focus visible, règles de session utilisables. Navigation
  clavier, toucher, filtres GET et retour navigateur réussis.
- Qualité : **725 tests /7 610 assertions**, HoF **84/291**, PHPStan complet
  cible8.3 sans erreur ; deux dépréciations historiques. PHP modifiés lintés,
  Python compilés, scan ciblé et diff-check satisfaits.
- [CI du même head](https://github.com/Alternative-LAB/faluss-platform/actions/runs/38767612544)
  réussie, ainsi que les contrôles JavaScript, archive et gouvernance. Elle
  utilise PHP8.3.35 et ne lance pas la capture navigateur facultative.

Ces résultats précèdent uniquement les ajouts de preuves/documentation et la
clarification de deux libellés d'aide/rapport Python. Aucun résultat du futur
main n'est revendiqué ici. Le rapport brut est conservé sans modifier ses valeurs.

Le [premier contrôle navigateur](before-overflow-checks.json), source
`80ddecc82cb89e894567430683ebbe77ab9869d5`, a échoué dans un cas sur48 :
invité320px, grille élargie à354px. La colonne implicite prenait la largeur
intrinsèque du formulaire SSO. Le correctif impose `minmax(0,1fr)` dans la
grille HoF, sans masquage global de débordement ni changement du shell.
Le résultat réussi ci-dessus provient d'une nouvelle paire complète après
correction, pas d'une simple nouvelle analyse des anciennes images.

## Environnement et portée

Deux WordPress/MariaDB privés jetables, modules Hub et Fans réels ; identités,
achats, clés, profils, consentements et contributions fictifs. Les captures
utilisent les routes WordPress réelles et des cookies de session distincts.
Leur transport HTTP loopback est adapté exclusivement dans le MU-plugin de
recette après validation de l'enclave physique. Aucun site cible, connexion
centrale Me ou appareil physique n'est couvert par cette preuve.

Le navigateur est Chrome 151.0.7922.34, avec Outfit chargé. Les dimensions
émulées sont 320, 375, 390, 430, 768 et 1 440 px, avec toucher mobile émulé.
Le contrôle CDP Windows/WSL passe par un relais binaire loopback privé ; il ne
réécrit aucune requête application, identité ou permission.

## Protocole de vérification

La recette existante `tests/TokenEngine/recipe/run.py --b6-current-delivery`
inclut H0–H3, corrections, corpus, B4/B5 et jonction B6. Elle vérifie la même
consommation et sa correction dans les familles Créateur/Fan et la session,
ainsi que consentement, suspension, source incomplète et fermeture.

La capture facultative est appelée par `FALUSS_B6_UI_CAPTURE_COMMAND`, pendant
que la paire de cette recette est vivante. Elle lit un fichier de capacité
temporaire privé, jamais ajouté aux preuves, et exerce les vrais GET avec les
cookies locaux. Le serveur et ce fichier sont retirés avant destruction de la
paire. Les exports HTML de `FALUSS_B6_UI_EXPORT` sont des replays distincts ;
aucune preuve de connexion n'est déduite de ces exports.

Les sources PHP/CSS de l'export de qualité et de recette sont identiques aux
sources de présentation soumises à la revue. La mise à jour ultérieure de la
documentation et des libellés de rapport ne change pas les assertions exécutées.
Les contrôles du head final et de main intégré restent des portes distinctes.

## Écarts avec les planches V2

- Pas de portrait ou de photographie de session dans les DTO autorisés de ce
  lot : noms approuvés, pictogramme et traitement graphique sobres.
- Un seul Créateur et un seul Fan contribuent à la scène capturée : aucune
  carte, session, place ou identité dupliquée pour remplir la planche.
- Pas de courbe d'évolution, gain de place, présence, score ou récompense inventé.
- Le relevé est mensuel et privé ; les classements restent persistants ; la
  session conserve ses règles et dates propres.

## Galerie examinée

Les seize vues normales ci-dessous et le focus sont des captures des routes
réelles de la paire jetable ; ordinateur1440px, mobile390px. Les images montrent
trois points issus de la consommation confirmée ; la correction ultérieure à
deux points est vérifiée dans la même recette. Les noms sont des fixtures.
Le mois du contrôle natif suit la langue du navigateur de recette ; ses bornes
métier et le texte applicatif restent Europe/Paris et français.

| Vue | Ordinateur | Mobile |
| --- | --- | --- |
| HoF invité | [Capture](screenshots/visitor-hof-1440.png) | [Capture](screenshots/visitor-hof-390.png) |
| HoF Fan | [Capture](screenshots/fan-hof-1440.png) | [Capture](screenshots/fan-hof-390.png) |
| HoF Créateur | [Capture](screenshots/creator-hof-1440.png) | [Capture](screenshots/creator-hof-390.png) |
| Classement Créateurs | [Capture](screenshots/creator-ranking-1440.png) | [Capture](screenshots/creator-ranking-390.png) |
| Classement Fans | [Capture](screenshots/fan-ranking-1440.png) | [Capture](screenshots/fan-ranking-390.png) |
| Relevé privé Fan | [Capture](screenshots/fan-statement-1440.png) | [Capture](screenshots/fan-statement-390.png) |
| Relevé privé Créateur | [Capture](screenshots/creator-statement-1440.png) | [Capture](screenshots/creator-statement-390.png) |
| Session | [Capture](screenshots/fan-session-1440.png) | [Capture](screenshots/fan-session-390.png) |

[Focus clavier](screenshots/fan-ranking-focus-1440.png).
[Ancienne carte unique trop large](screenshots/visitor-hof-before-80ddecc-1440.png).

État vide : [replay ordinateur](screenshots/visitor-empty-replay-1440.png) /
[replay mobile](screenshots/visitor-empty-replay-390.png) des vrais templates,
distincts des captures de routes authentifiées. [Six largeurs sans échec](empty-replay-checks.json).
Le replay mobile est une capture pleine page : la barre fixe reste au bas du
viewport initial, au milieu de l'image longue. Les captures mobiles des vraies
routes ci-dessus sont limitées au viewport. [Empreintes des fichiers](SHA256SUMS).

## Limites fonctionnelles conservées

Formulaires de gouvernance, alias/consentements et intentions serveur complètes
restent à raccorder. La fraîcheur est prouvée à l'instant du corpus, sans
promesse d'actualisation continue en production. Admission réelle, producteur
d'achat, SSO Me, conditions de territoire/pays, #150 et RustFS #161 sont des
portes séparées. Fusion et publication ne rendent pas ces scores activables
sur les sites. Aucun workflow, préparation, cache, délai, runner ou contrôle
requis n'a été modifié, supprimé, désactivé ou assoupli.
