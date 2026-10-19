# B6 — invitations de coorganisation, raccordement privé vérifié en isolation

Sous-lot d'interface indépendant du transport de participations. Il réutilise
`SessionRoles` et les [règles B2 approuvées](FANS-HOF-B2.md), sans nouveau contrat
Hub. **Recette HTTP et navigateur acquise sur instances jetables** : [preuves, captures et limites](../evidence/fans-hof-b6-coorganizers/README.md).

La session privée affiche les seuls rôles rendus par `SessionService::inspect`.
Une invitation apparaît dans la liste propriétaire du destinataire, sans lui
conférer de pouvoir avant acceptation. Un organisateur accepté peut inviter
pendant le brouillon, avec un profil actif et une présentation approuvée. Le
propriétaire conserve ses pouvoirs distincts sur les règles et le cycle.

Les candidats viennent du contrat public `EditorialService::discovery` avec
pagination réelle. Les profils déjà associés comme organisateurs sont retirés
des choix, sans confondre une page vide avec une absence globale. Noms approuvés
seulement ; une présentation retirée ou suspendue devient indisponible, sans
repli sur un UUID, un login ou une donnée Identity. L'invitation revalide
l'éligibilité côté service, y compris après un changement concurrent du profil.

Chaque POST conserve nonce et révision exacts. Acceptation et refus sont
explicites ; un Créateur suspendu peut encore refuser selon le service B2.
Une réponse périmée impose une relecture avant une autre action. Aucun GET,
défilement ou changement de page n'accepte une invitation. Ni participation
automatique, ni score, ni ouverture Hub ne découlent de l'acceptation.

Les dates, composants et conteneurs Fans existants sont conservés, sans
modification du shell ou de la sidebar. L'ancienne route préserve le curseur
public des candidats. Le retour SSO accepte ce seul curseur canonique lié à
la session privée courante ; HMAC, nonce, échange, PKCE et durées de session
restent inchangés. Ce chemin n'accorde aucun droit sur la session sélectionnée.

Scénarios écrits avant le code : nonces et identités distinctes, absence de
pouvoir implicite, invitations/réponses concurrentes, historique après refus,
suspension, exclusion des profils non éligibles, plus de vingt candidats,
pagination sans écriture, anciennes routes et retour SSO. La concurrence des
requêtes HTTP ne doit pas être présentée comme un serveur PHP multithread ; les
recettes SQL B2 gardent leur périmètre transactionnel propre.

Les participations et leurs barrières d'admission, les territoires et les
intentions restent des raccordements distincts. Aucun site, migration,
activation, achat ou changement de flag. Conservation #150 et RustFS #161
restent séparées. Retour arrière : retirer les vues/adaptateurs et préserver
les rôles/journaux B2 ; aucune purge ou modification de schéma.
