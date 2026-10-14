# 0020 — Paralléliser les recettes WordPress sans perdre leurs assertions

- **Statut** : accepté
- **Date** : 2026-10-14
- **Périmètre** : issue #213, prérequis de la réactualisation de #205

## Contexte

Le job nommé Identity admin-post dure 98 minutes alors que le scénario Identity dure sept secondes. Il accumule des recettes Fans et PF, dont les options avancées réexécutent les prérequis. Cette organisation ralentit chaque contribution et multiplie les mêmes assertions.

## Décision

Séparer Identity, l’administration Fans et cinq recettes PF terminales sur des runners distincts. Conserver les arguments exacts des recettes de référence, notamment une variante de fin de session locale dont 37 assertions ne sont pas couvertes par les autres variantes. Ne modifier aucun script applicatif ni scénario de recette.

Comparer les rapports à l’inventaire des 1 413 assertions uniques de la CI réussie 38531600985. Exiger tous les rapports, des compteurs cohérents et toutes les assertions de référence. Un statut d’intégration stable agrège les suites et cette vérification, sans accepter un échec ou un job ignoré.

Centraliser les outils épinglés dans une action composite locale, partager le bytecode CLI au sein du job avec revalidation immédiate et configuration de fixture exclue, puis annuler les exécutions obsolètes d’une même PR. Les serveurs HTTP gardent leurs options explicites désactivant OPcache. Les contrôles existants et la protection distante restent en place. La preuve indépendante vient des rapports de l’ancien workflow réussi et de la comparaison avec les nouveaux rapports, et non d’une simple affirmation du nouveau workflow.

## Conséquences

Le chemin critique suit la recette la plus longue, avec un objectif de 15–20 minutes à confirmer par mesure. Les runners utilisent des fixtures indépendantes. L’inventaire explicite rend visible une perte ou un renommage de scénario et doit évoluer avec justification lorsque les tests évoluent.

Le changement est réversible par une PR et ne touche pas la production. La procédure, les groupes et le contrat de couverture sont documentés dans [CI.md](../CI.md).

## Alternatives

- Ne paralléliser que les 21 invocations PF conserve presque toutes les répétitions et augmente le coût des runners.
- Ne garder que le corpus terminal perd des scénarios de transport distincts et les cas locaux de fin de session.
- Ignorer les recettes sur les PR documentaires sans contrôle de sélection validé ferait évoluer les garanties au-delà du présent objectif.
