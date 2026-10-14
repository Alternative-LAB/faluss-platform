# Consolidation fermée B1/B2/B3

Arbre immuable LF `1d19453a66b6c969c97388404b2d4bf0637da9fe` : WordPress 7.1.2,
PHP 8.5.4, MariaDB 11.8.6, bases privées InnoDB et HTTP loopback Hub/Fans.
Deux signataires distincts, identités et preuves fictives, enclave physique.

`run.py --b1-origin-ack --b3-session-lifecycle` : **455/455 contrôles**, dont
18 B1, 37 B2/B3 et une vérification de jonction. La même origine préparée dans
Fans est enregistrée/acquittée par Hub puis utilisée par les sessions. Sa
fermeture intervient ensuite, sans effacement ni substitution du registre.
Toutes les vérifications de panne, reprise, annulation, clôture à échéance,
réadmission et anciens acquittements des recettes individuelles sont conservées.

Suite PHP : **670 tests / 7 170 assertions**, HoF **59 / 137** ; PHPStan complet
cible 8.3 sans erreur, deux dépréciations historiques. Six PHP lintés, trois
Python compilés, dix fichiers exécutables/CI égaux après normalisation LF,
quinze liens relatifs, scan ciblé de secrets et `git diff --check` réussis.

Les tentatives précédentes ont échoué au démarrage du primaire jetable avant
les contrôles ; le délai de disponibilité MariaDB du harnais a été borné à
60 secondes, sans modifier un timeout du plugin ou un contrat réseau.
Le rapport réussi confirme le nettoyage final des fixtures et workers.

Les preuves de #199 et #200 restent les exécutions de leurs branches ; cette
recette est la nouvelle preuve de jonction. Aucun vrai SSO, utilisateur réel,
site, achat, score public, dimensionnement de production ou livraison B6
raccordée n'est attesté. Le corps #200 est repris sans son ancien commit de
merge non conforme, avec comparaison des fichiers métier identiques. Aucun
historique de sa branche n'est réécrit. #150 et #161 restent distinctes.

[Rapport expurgé](wordpress-checks.json) : seuls contrôles, totaux, versions et
limites. Aucun wire, clé, cookie, UUID de recette, contenu privé ou payload.
