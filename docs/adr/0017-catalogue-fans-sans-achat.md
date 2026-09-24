# ADR 0017 — Classer les offres Fans avant d'ouvrir les achats

## Décision

Le premier lot store expose deux catégories distinctes et des fiches structurées sans contenu, prix, paiement ou droit. Le serveur refuse toute tentative d'achat sur la seule route actuellement exposée. La catégorie adulte externe reste classable et visible ; toute fiche qui l'associe à un créateur demeure masquée tant que son consentement explicite n'est pas recueilli et vérifié. Le refus d'achat reste inconditionnel. Le détail des routes et des portes figure dans [FANS-STORE.md](../modules/FANS-STORE.md).

## Motif

La validation du prestataire pour les contenus autorisés hébergés et l'acceptation spécifique de la livraison adulte externe sont deux décisions différentes. Les confondre dans un simple flag risquerait d'ouvrir le second parcours par erreur. La séparation des catégories et le refus codé avant l'arrivée des commandes empêchent cette confusion dans le catalogue.

## Conséquences

Le store n'effectue aucune vente. L'approbation administrative du profil ou de la fiche ne vaut pas consentement du créateur à l'association publique avec la catégorie adulte externe. Les futurs services de panier, commande, paiement, remboursement, webhook et droit devront incorporer et tester un refus serveur sur tous leurs chemins, API et actions d'administration comprises. Les tests de l'API actuelle ne couvrent pas ces moteurs absents.
