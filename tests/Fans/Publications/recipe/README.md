# Recette locale jetable — textes Fans

**Ne jamais exécuter sur un site existant.** La fixture PHP exige WP-CLI et le nom
de base `faluss_text_publications_recipe`. Le script Python modifie cette base,
crée un trigger de panne temporaire et désactive le flag texte à la fin.
Les comptes, textes et liaisons sont synthétiques. Aucun secret n'est versionné.

## Préparation manuelle explicite

1. Créer une instance WordPress vierge dans `/var/tmp/faluss-text-publications-recipe`,
   une base locale dédiée du nom ci-dessus, un administrateur ID 1, aucune pièce jointe.
   Aucun accès ni import de données de production. Utiliser le plugin de cette branche.
2. URL locale `https://text.local.test`, client SSO fictif et secret aléatoire local
   (43 caractères base64url), rôle `fans`. Activer uniquement les flags SSO, profils,
   et `FALUSS_PLATFORM_FANS_TEXT_PUBLICATIONS` sur cette instance jetable, puis le plugin.
   Installer les permaliens `/%postname%/`. Désactiver cron et les requêtes HTTP externes.
3. Exécuter `wp eval-file <repo>/tests/Fans/Publications/recipe/fixture.php` sur
   cette instance. Elle utilise les contrats publics de profil et prépare uniquement
   les liaisons SSO synthétiques ; elle ne simule pas une preuve réseau Me.
   Les cookies/nonces restent dans `/var/tmp/faluss-text-publications-proof/sessions.json`,
   mode 0600, hors du document root et du dépôt. Ne jamais joindre ce fichier à la PR.
4. Servir WordPress sur `127.0.0.1:8113` avec quatre workers PHP ; router les chemins
   via `index.php`. Le routeur local fixe `HTTPS=on`, `SERVER_PORT=443` et
   `HTTP_HOST=text.local.test` pour la configuration WP. Le transport de cette recette
   reste **HTTP loopback**, pas TLS. Le client transmet explicitement les cookies
   WordPress et `X-WP-Nonce`. Ne jamais exposer ce serveur hors loopback.
5. Lancer `python3 <repo>/tests/Fans/Publications/recipe/rest-test.py` sous un utilisateur
   autorisé à joindre la base locale par socket. Le script suppose le phar WP-CLI local
   `/var/tmp/faluss-v3-wp/wp-cli.phar` ; adapter ce seul chemin sur une autre machine.
   Arrêter ensuite tous les workers et conserver les résultats sans sessions/secrets.

## Résultat initial constaté le 25 septembre 2026 (SHA `9d5f2eb`)

WordPress 7.1.2, PHP 8.5.4, MariaDB 11.8.6 : **45 contrôles réussis**.
Cette preuve précède la pagination. Le script lit désormais les listes dans
`items`, mais n'a pas été rejoué pour la correction de pagination, sans activation
de flag. Les nouveaux tests de pagination sont des tests PHPUnit simulés, pas
une nouvelle recette WordPress/MariaDB.

- Auteur actif/lié uniquement ; nonce obligatoire ; admin non créateur refusé en création.
- Catégorie adulte externe, propriétaire forgé, média, accès verrouillé et HTML refusés.
- Pending absent de la liste et du détail publics ; privé réservé au propriétaire/admin.
- Administration seule pour file, décision et traces ; approbation explicite requise.
- Trigger SQL d'échec du journal : création entièrement annulée, approbation annulée,
  révision pending intacte et pas d'exposition publique ; trigger retiré en `finally`.
- Approbation et réponse publique sur liste blanche, avec `no-store`.
- Deux requêtes HTTP simultanées d'édition à la même révision : une 200, une 409,
  une seule trace nouvelle ; édition cachée et modération périmée refusée.
- Rejet purgeant le texte, hash et acteur conservés, correction soumise à revue.
- Suspension masquant les lectures ; retrait possible malgré suspension ;
  réactivation ne restaurant pas un texte retiré.
- Tables InnoDB ; aucune pièce jointe ; retrait du flag fermant les routes et
  conservant le journal. Le chargement initial de la nouvelle configuration a
  nécessité une nouvelle requête : le script attend au plus cinq secondes pour
  cette relecture, distincte du retrait transactionnel d'un texte.

Limites : pas de preuve navigateur/TLS/CDN, pas de nouvel échange SSO avec Me,
pas de recette médias/Me (inexistants), pas de test de charge, de panne réseau au
commit ni de moteur de détection de contenu interdit. La revue humaine et les
procédures de purge/rétention avant ouverture restent indispensables. Les tests
PHPUnit utilisent des doubles ; ils ne sont pas comptés parmi ces 45 contrôles.
