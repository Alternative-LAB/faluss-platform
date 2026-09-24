# Désactivation après une mise à jour scriptée

## Résumé

Le 24 septembre 2026, la validation de la première livraison privée `0.2.0` a
appelé directement `Plugin_Upgrader::upgrade()` depuis PHP sur `faluss.me` et
`faluss.com`. L'archive a été authentifiée, téléchargée et installée, mais
Faluss Platform est resté inactif après le remplacement.

Les deux sites sont restés disponibles. Le plugin a été réactivé immédiatement,
la licence et les données ont été conservées et les contrôles HTTP sont restés à
`200`. Les sauvegardes précédant l'installation se trouvent hors des volumes
WordPress dans `/root/faluss-production-backups/2026-09-24-private-updater` sur
l'hôte d'administration.

## Cause

Hors cron, `Plugin_Upgrader::upgrade()` désactive silencieusement une extension
active avant de remplacer son répertoire. `active_after()` gère uniquement le
mode maintenance. Dans l'interface, `Plugin_Upgrader_Skin::after()` délègue la
réactivation à une nouvelle requête `activate-plugin`. Le lanceur PHP de
validation appelait uniquement l'upgrader avec une skin automatique et ne
reproduisait pas cette seconde requête.

Le serveur de mises à jour, le ZIP, Plugin Update Checker et l'authentification
par licence ont fonctionné comme prévu. L'incident concernait le lanceur de test,
pas la publication ni le client de détection.

## Correctif

Toute mise à jour scriptée doit désormais :

1. relever `is_plugin_active()` et `is_plugin_active_for_network()` avant
   l'installation ;
2. créer une sauvegarde restaurable du répertoire du plugin ;
3. vérifier le résultat de `Plugin_Upgrader::upgrade()` ;
4. terminer le processus d'upgrade, puis appeler `activate_plugin()` dans un
   nouveau processus si le plugin était actif, en conservant sa portée réseau et
   avec le mode silencieux utilisé par une mise à jour ;
5. tenter cette restauration d'état même après un échec d'upgrade, une fois le
   paquet temporaire WordPress restauré, puis conserver l'échec initial ;
6. vérifier dans un troisième processus la version, la licence, le chargement du
   client et l'état actif attendu ;
7. contrôler les parcours HTTP des deux sites après l'opération.

Le lanceur ne doit ni écrire directement dans `active_plugins`, ni simuler
`DOING_CRON`. Des processus séparés empêchent aussi de valider la nouvelle
version alors que les classes de l'ancienne sont encore chargées en mémoire.

Le suivi est conservé dans l'issue GitHub #37.

## État final vérifié

- Faluss Platform `0.2.0` actif sur `faluss.me` et `faluss.com` ;
- licence valide conservée dans les deux bases WordPress ;
- Plugin Update Checker présent dans le paquet publié ;
- `faluss.me`, `faluss.com` et `/mon-faluss/` répondent avec HTTP `200` ;
- conteneurs WordPress, bases et serveur de mises à jour sains.
