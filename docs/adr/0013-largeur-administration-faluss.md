# ADR 0013 — Utiliser la largeur disponible dans l'administration Faluss

## Décision

Les conteneurs de page Faluss ne fixent plus de largeur maximale globale. Ils
occupent la largeur fournie par `#wpcontent`, avec une marge droite régulière.
Les champs, formulaires et textes gardent leurs limites locales lorsqu'elles
améliorent la lecture.

Cette règle s'applique à l'administration commune, à Identité visuelle, au
Catalogue, au Token Engine Connector, au Token Engine historique et aux écrans
Subscriptions chargés par Platform.

Les feuilles historiques Token Engine et Subscriptions restent inchangées pour
préserver leurs contrats de parité. Platform charge `assets/admin-full-width.css`
pour appliquer les règles de largeur sur leurs conteneurs sans modifier les
assets historiques.

## Raisons

Les plafonds précédents de 1180px, 1240px, 1120px et 960px produisaient une
grande colonne vide sur les écrans larges. La largeur disponible est déjà
bornée par l'interface d'administration WordPress et reste responsive sur les
petites fenêtres.

Les versions d'assets auparavant fixées à `0.1.0` empêchaient aussi de garantir
que la nouvelle CSS soit servie après une mise à jour. Elles utilisent désormais
`FALUSS_PLATFORM_VERSION`.

## Vérification

La validation couvre la syntaxe PHP, PHPStan et les tests du projet. Les
écrans utilisent les mêmes classes de page ; une vérification visuelle doit
confirmer le rendu sur un écran large et une fenêtre mobile après publication.
