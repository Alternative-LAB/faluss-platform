# Recette locale jetable — panel de modération Fans

## Environnement et lancement

WordPress 7.1.2, PHP 8.5.4 avec GD/Fileinfo, MariaDB 11.8.6/InnoDB, quatre workers
PHP non privilégiés (`www-data`). HTTP réel sur 127.0.0.1:8113 uniquement ; le
routeur simule HTTPS pour WordPress, **aucune preuve TLS**. Module MU offline,
liaisons SSO et contenus synthétiques, sessions/nonces WordPress réels.

Préconditions identiques à [IMAGE-DISPLAY.md](IMAGE-DISPLAY.md) : base locale
`faluss_text_publications_recipe`, schémas déjà installés, symlink plugin vers la
branche testée. Le script refuse toute autre base/répertoire/port occupé. Il
réinitialise les six tables synthétiques texte/images de cette base jetable.

```sh
python3 tests/Fans/Publications/recipe/moderation-panel-test.py
```

Le script tourne sous root Linux pour préparer un compte SQL socket temporaire
limité à la base jetable et deux dossiers privés 0700 possédés par PHP. HTTP sous
UID `www-data`. Aucun flag de diffusion ouvert, même localement. Textes et
quarantaine sont activés **temporairement uniquement pour cette recette**.

Option navigateur Windows/WSL : `FANS_PANEL_VISUAL=1`, `FANS_PANEL_NODE` chemin
Linux de node.exe, `FANS_PANEL_MODULES` chemin Windows (slashes `/`) du dossier
contenant Playwright. `panel-browser.cjs` utilise Edge headless installé. Les
requêtes sont redirigées uniquement vers le serveur loopback et le cookie
synthétique reste en mémoire ; les demandes externes et le heartbeat/test de
compression WordPress, étrangers au panel, sont bloqués. Pas de trace réseau
exportée. Les captures ne contiennent que les fixtures synthétiques.

## Résultats HTTP

Le 26 septembre 2026, **67 contrôles HTTP réels réussis** :

- 26 textes répartis sur deux créateurs, pages 20 + 6, sans omission ni doublon ;
- administration autorisée/no-store ; créateur et anonyme refusés ; nonce invalide,
  copie d’un formulaire admin par un membre et GET de l’aperçu refusés ;
- PNG privé par POST administratif, en-têtes sans cache partagé, aucune URL de
  fichier ; association approuvée examinable et ancienne révision refusée ;
- double approbation image simultanée : un 200, un 409, une seule trace ;
- édition concurrente du texte : décision ancienne 409, aucun message de réussite ;
- double approbation texte simultanée : un 200, un 409, une seule trace ;
- fiche et journal toujours accessibles après approbation, refusés au créateur ;
- refus avec motif et purge du texte courant, retrait puis décision périmée refusée ;
- suspension par REST : approbation et aperçu fermés, contexte visible et rejet
  toujours possible ; réactivation de la fixture ;
- refus/retrait image : octets inaccessibles et fichiers supprimés ;
- fermeture du flag texte : panel inaccessible.

Les premières passes ont corrigé le harnais, pas les règles métier : recherche
d’un élément après déplacement sur une page suivante, chemin Windows des modules,
choix du navigateur installé et routeur local servant les assets derrière le
symlink. Un test de compression de l’administration WordPress a aussi dû être
exclu du pont Playwright. Ces échecs ne sont pas présentés comme une recette verte. Le parcours navigateur
a trouvé puis validé la correction du masquage DOM de `form.action` par le champ
caché WordPress `action` : l’adaptateur utilise désormais l’attribut URL explicite.

## Preuves visuelles

Edge headless : 1440 × 1000 et 390 × 844, vérification de la couleur réelle du
canvas et absence de débordement du panel mobile. Formulaire de décision exécuté
sans JavaScript avec confirmation serveur. Avec JavaScript : aperçu PNG obtenu
par POST, rendu via blob local, puis retrait de cet aperçu vérifié. Captures du résultat réel :

- [Ordinateur](captures/moderation-desktop.png)
- [Mobile](captures/moderation-mobile.png)
- [Examen d’image](captures/moderation-image.png)

## Retour arrière et limites

Le `finally` révoque/nettoie les images de fixture encore présentes, referme les
flags textes, images et diffusion, restaure les paramètres DB/stockage, détruit
les sessions `recipe_*`, supprime le compte SQL temporaire et le routeur, puis
arrête serveur et workers. Les cookies ne sont pas stockés dans Git ; leur fichier
local est privé 0600 hors webroot.

Cette preuve ne remplace pas un audit d’hébergement ou de cache/CDN réel, une
recette Safari/iPhone physique, un nouveau SSO Me, un audit d’accessibilité complet,
des essais de charge prolongés ou de panne SQL au commit. Les 67 contrôles HTTP
ne sont pas les tests PHPUnit simulés. Les ordonnancements concurrents testés ne
couvrent pas tous les entrelacements. Les [procédures humaines et la rétention](../../../../docs/modules/FANS-MODERATION.md)
restent à décider ; aucune détection automatique infaillible n’est revendiquée.
