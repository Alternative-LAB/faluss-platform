# Écart de parité Portal sur le gain et l'échéance

## Détection

Le 22 septembre 2026, une bascule contrôlée de Portal sur `faluss.com` a
comparé le shortcode historique et le module Platform avec les quatre comptes
liés existants. Les manifests Portal et les catalogues Events étaient
identiques, mais le rendu membre Platform était plus court.

Pour un membre lié représentatif, la comparaison normalisée des nonces et des
chemins d'assets a isolé deux écarts :

- le formulaire de gain quotidien de 20 PF était remplacé par une icône non
  interactive ;
- la ligne « Prochaine échéance » de l'abonnement disparaissait. L'ancien
  rendu affichait toutefois une date courante lorsque `expires_at` était nul
  pour un abonnement gratuit ; Platform supprime cette fausse échéance.

## Mesure immédiate

La bascule a été annulée : `FALUSS_PLATFORM_PORTAL` a été remis à `false` et
`faluss-portal` réactivé. Le rendu historique contient de nouveau le formulaire
de gain ; `/mon-faluss/` répond HTTP 200. Les autres modules déjà
basculés restent actifs.

## Suite

L'issue GitHub #26 suit la projection du montant du gain via Token Engine et la
caractérisation de l'absence d'échéance lorsque l'abonnement n'en fournit
aucune. La bascule Portal reste bloquée jusqu'à un test de parité du rendu et
une nouvelle validation de production.
