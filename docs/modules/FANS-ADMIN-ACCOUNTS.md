# Contexte privé des comptes Fans

`FansAccountDirectory` expose aux seuls détenteurs locaux de `manage_options`,
sur le rôle Fans et avec schéma SSO valide, une projection du compte WordPress
et de sa liaison SSO. `CreatorProfileService::administration()` fournit le lien
propriétaire privé du domaine Profils. Ces méthodes ne sont pas des API publiques.

La carte commune apparaît dans les files et fiches natives d'admission,
présentations, publications, images et signalements. Les signalements conservent
l'exigence supplémentaire `moderate_faluss_fans_messages` ; leurs comptes signalant
et auteur sont résolus séparément. Aucune conversation ordinaire n'est exposée.

- E-mail : valeur **locale** WordPress, pas une nouvelle preuve d'identité Me.
- Faluss ID : liaison enregistrée ; absence, erreur ou compte devenu privilégié
  sont signalés. Le compte technique n'est jamais présenté comme un handle.
- Handle : absent du contrat actuel, affiché comme non fourni.
- Statut : Fan ou statut réel du profil Créateur.
- Nom et portrait : seulement présentation déjà approuvée, profil public actif,
  image encore autorisée et dérivé activé. Aucun Gravatar ni portrait en attente.

Les approbations d'admission, présentation, publication et image revérifient la
liaison membre côté service avant mutation ; une liaison absente renvoie `409`.
Rejeter, retirer et suspendre restent possibles. Les journaux et transactions
existants restent l'autorité ; aucune nouvelle table ni habilitation implicite.

La recherche locale par e-mail ou Faluss ID échappe les jokers SQL, limite la
requête à 191 octets et pagine les comptes liés par ID WordPress (20 maximum).
Elle ne contacte pas Identity et ne permet pas de modifier un compte global Me.
La projection privée est destinée au back-office ; aucun e-mail ou Faluss ID
n'est ajouté aux contrats publics de profil, publication ou messagerie.

Retour arrière : revert du code ; aucun changement de schéma ou de flag.
Preuves : [recette isolée](../evidence/fans-admin-accounts/README.md).
