# ADR 0014 — Admettre Fans sans charger les modules Me et Hub

## Décision

Ajouter `fans` comme troisième rôle de site et partager seulement l'administration du socle. Chaque module conserve sa liste explicite de rôles ; l'ajout du rôle n'élargit pas automatiquement Federation, Events, Apps Registry ou les modules métier. Les hooks d'activation doivent vérifier le rôle avant tout chargement de schéma ou mutation. Link reçoit cette vérification car son hook ne contrôlait auparavant que le flag.

## Raisons et conséquences

Le registre filtre les modules à l'exécution, mais les hooks d'activation WordPress sont enregistrés avant son chargement. Un flag Link présent sur Fans pouvait donc appeler l'installation du schéma Link. Le garde de rôle ferme ce chemin. Me et Hub conservent leurs listes de modules et leurs conditions de démarrage. Fans n'a encore ni domaine métier, ni stockage, ni bascule de production. Les contrats et portes des lots futurs sont décrits dans [FANS.md](../modules/FANS.md).
