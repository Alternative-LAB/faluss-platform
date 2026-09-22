# Double bootstrap Portal pendant `plugins_loaded`

## Détection

Le 22 septembre 2026, la revue préalable à la bascule Portal a montré que ses
quatre adaptateurs interprètent un hook `plugins_loaded` en cours comme un hook
terminé. Le défaut a été détecté avant l'activation du module en production.

## Impact potentiel

`PortalModule` démarre à la priorité 20. Manifest, Apps Registry, Events Catalog
et Events Runtime exécuteraient immédiatement leur enregistrement tout en
laissant leurs callbacks programmées aux priorités 30, 40, 50 et 70. La source
Apps Registry `faluss-hub` serait alors enregistrée deux fois, ce qui
verrouillerait toutes les lectures membre. Les gardes Events absorbent les
appels redondants ; le provider de manifest refuse sa seconde inscription.

## Correction

Chaque bootstrap distingue désormais trois états : avant le hook, pendant le
hook et après sa fin. Pendant le hook, il laisse WordPress exécuter uniquement
la callback de la priorité prévue. Après sa fin, il exécute immédiatement sans
ajouter de callback devenue inutile.

La bascule Portal reste suspendue jusqu'à fusion, déploiement et validation du
correctif suivi dans l'issue GitHub #24.
