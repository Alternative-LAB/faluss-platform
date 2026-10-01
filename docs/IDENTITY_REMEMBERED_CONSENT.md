# Accords d’applications mémorisés — Identity

## Comportement

Sur le rôle `me`, Faluss Identity conserve l’accord explicite du membre pour un client tiers et **l’ensemble exact** des permissions demandées. Aucune permission implicite : demander `identity.email` après `identity.basic`, ou revenir à un ensemble réduit, présente un nouvel écran d’accord. L’accord le plus récent remplace le précédent pour ce couple membre/client.

La mémorisation ne connecte pas un visiteur non authentifié : le passwordless et la session Identity active restent indispensables. Un profil suspendu est refusé. Les vérifications d’URI exacte, de PKCE S256, de client confidentiel, de nonce, de code à usage unique et de délai de 60 secondes restent en place.

La configuration complète est liée à l’accord : nom, secret haché, URI, scopes autorisés, marqueur officiel, date de modification et révision aléatoire renouvelée lors de **chaque** sauvegarde ou rotation dans l’administration. Même une sauvegarde identique, ou un aller-retour dans la même seconde, invalide l’accord précédent et les codes non échangés pour ce client. Le nonce de confirmation est également lié à cette configuration : une ancienne page ouverte ne peut pas approuver une configuration modifiée en arrière-plan.

Fans reste un client tiers, `first_party=0`. Le mécanisme historique réservé au callback exact Faluss.com reste distinct ; sa politique n’est pas étendue à Fans. Les champs éditoriaux Fans ne sont pas transmis par cet accord.

## Révocation par le membre

Lien **Gérer mes autorisations d’applications** depuis l’écran de consentement, l’éditeur de profil Identity et ses champs dans le Studio. La page `/?faluss_identity_apps=1` affiche les applications, les permissions et la date UTC du dernier accord explicite. Elle ne présente aucun UUID membre, secret ni jeton.

Chaque révocation est un POST avec nonce lié au client et à la session WordPress du propriétaire. L’identité cible est résolue côté serveur, jamais reçue du formulaire. L’accord et les codes non échangés sont supprimés atomiquement ; une réutilisation sélectionnée avant la révocation est revérifiée sous verrou avant émission d’un code.

La révocation **ne ferme pas les sessions locales déjà ouvertes**, ne supprime pas les données métier et n’exécute pas un logout fédéré. Cette limite est affichée dans la page. Un nouvel accord explicite peut ensuite être donné. La page privée renvoie `no-store` et redirige un visiteur vers le login Identity existant.

## Stockage et mise à niveau

- Deux tables InnoDB additionnelles, préfixées par WordPress : `faluss_identity_consents` (Faluss ID, client, empreinte de configuration, scopes, date UTC) et `faluss_identity_consents_clients` (révision opaque par client). Aucun mot de passe, e-mail ou secret brut.
- Option technique non autoloadée `faluss_identity_consent_schema=1`. Aucun changement du schéma historique Identity v6, de ses tables ou des données Fans.
- Création idempotente à l’activation habituelle du module Identity, ou au début de `init` après mise à jour si le marqueur de schéma est absent. Elle ne dépend plus d'une visite d'administration. Aucun DDL dans une transaction d’autorisation. Un schéma déjà marqué mais défectueux n'est pas réparé silencieusement. Si l'accord ne peut pas être persisté, son approbation répond 503 sans délivrer de code ; le membre n'est plus invité à répéter un accord non mémorisé à chaque connexion.
- Vérification du moteur InnoDB, des colonnes et des clés primaires. Un stockage défectueux n’autorise jamais la réutilisation ; après installation, il bloque également la modification de configuration pour éviter une invalidation partielle.
- Verrou du client puis de la demande, accord et code dans une transaction ; configuration et révision dans une transaction séparée. Une panne d’écriture annule intégralement l’opération. Les accords restent privés jusqu’à leur révocation/remplacement ; ils ne sont ni exportés dans les claims ni copiés dans Fans.
- L’empreinte contient une version de la sémantique des permissions (`identity-consent-v1`). Toute future extension des claims couverts par ces scopes doit changer cette version et ses tests.

Retour arrière : restaurer la version précédente du plugin rétablit la demande explicite à chaque connexion tierce ; les tables additionnelles restent inertes et ne gênent pas le schéma historique. Avant de réactiver cette fonctionnalité après une période de retour arrière, un administrateur doit purger les accords mémorisés, car les anciennes versions ne renouvellent pas les révisions de configuration. Cette opération relève de la recette d’exploitation, pas de cette livraison.

## Scénarios et preuves

Recette WordPress 7.1.2 / MariaDB 11.8.6 / PHP 8.5.4 / Chrome 154, HTTPS sur boucle locale avec certificat jetable ; aucun site cible ni serveur externe contacté. Les comptes sont créés uniquement dans la base jetable. Les redirections vers le callback de test sont inspectées sans être suivies. Les captures utilisent le thème WordPress par défaut, pas le thème de production de faluss.me.

`tests/Identity/recipe/consent-wordpress.py` prépare l’installation, `consent-browser.cjs` contrôle les vrais handlers HTTP et fournit les captures 1440 × 900 et 390 × 900 :

1. Premier accord, code utilisable, PKCE erroné et rejeu refusés, marqueur Fans toujours nul.
2. Réutilisation sans écran pour le même membre ; visiteur vers passwordless, suspendu refusé, autre membre soumis à son propre accord, refus sans stockage.
3. Extension et réduction des scopes, nonce invalide, permissions exactes.
4. Sauvegarde administrateur réelle, configuration identique mais nouvelle révision, formulaire périmé, client inactif puis réactivé.
5. Rotation réelle du secret, ancien secret refusé, nouveau secret utilisable après nouvel accord.
6. Page membre, nonce/propriétaire vérifiés, révocation, invalidation des codes non échangés, nouvelle demande explicite.
7. Panne de persistance injectée : ni code ni accord partiel.

`consent-sql.php`, exécuté par la recette WordPress/MariaDB **déjà présente en CI**, couvre l’idempotence, chaque champ de configuration, le changement entre sélection et émission, la révocation entre sélection et émission, le rollback accord/code, le rollback configuration/révision et le refus de MyISAM. Il utilise les services de production et une base jetable, sans API simulée. Les contrats historiques Identity/passwordless restent exécutés.

Captures et synthèse navigateur : [evidence/identity-consent](evidence/identity-consent). Les dépréciations PHP 8.5 des outils WP-CLI et des fallbacks header/footer du thème bloc sont documentées ; elles ne sont pas des fatales du plugin. La recette cible, l’installation et l’activation restent à la charge du propriétaire.
