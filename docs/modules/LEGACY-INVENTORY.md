# Inventaire des plugins historiques

Le relevé historique n’est plus affiché dans l’interface Faluss depuis la fin de la migration. La classe reste conservée pour les contrôles et audits locaux ponctuels. Elle lit uniquement l’option WordPress `active_plugins` ; elle ne modifie aucun réglage et ne contacte pas l’autre site.

```mermaid
flowchart LR
    Role[Rôle local me ou hub] --> Manifest[Liste des plugins attendus]
    Option[Option active_plugins] --> Inventory[Inventaire local]
    Manifest --> Inventory
    Inventory --> Audit[Contrôle ponctuel, lecture seule]
```

Le manifeste compte huit plugins historiques pour `faluss.me` et huit pour `faluss.com`. Les noms de fichiers ont été vérifiés contre les listes d’extensions actives des deux installations le 21 septembre 2026. Le plugin `faluss-platform` ne fait pas partie de ce total. Les extensions inconnues ne sont pas affichées et les installations WordPress multisites ne sont pas couvertes par ce relevé.

L’état « Actif » signifie uniquement que WordPress liste le plugin comme activé. Il ne prouve ni la santé de ses fonctionnalités, ni sa connexion à l’autre domaine, ni sa compatibilité avec les nouveaux modules. Le retrait d’un ancien plugin exige toujours ses tests de parité, une procédure de migration et une validation explicite.
