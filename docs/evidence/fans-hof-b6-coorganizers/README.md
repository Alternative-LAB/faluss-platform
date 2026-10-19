# B6 — invitations privées et réponses de coorganisation

## Source et portée

Recette conjointe sur l'arbre `ce012d707c2f72b13e5fad9b68305d1fc1c616a7`,
commis ensuite sans changement en `be2b7439806a0b82cd7621cf837e10a90a1338be`.
La consolidation suivante c348663 reprend en base le correctif nonce #228,
validé séparément490/0. Les octets de ce composant sont identiques à l'export
testé : [source-proof.json](source-proof.json). Les contrôles du head final et
du main intégré restent distincts ; cette preuve ne les remplace pas.

WordPress7.1.2/PHP8.5.4/MariaDB11.8.6 jetables, utilisateurs et liens Identity
fictifs, cookies WP réels. Aucun véritable SSO Me, thème/Elementor cible,
appareil physique, opération réelle, production ou admission de pair.

## Résultats

| Contrôle | Résultat |
|---|---|
| Qualité consolidée | 803 tests / 8074 assertions, HoF84/291, PHPStan0 ; deux dépréciations historiques |
| Hub/Fans physiques | 858 contrôles / 0 échec, 131 B6, dont 12 nouveaux cas de coorganisation ; fixture_removed=true |
| Navigateur | 128 agencements aux largeurs320/375/390/430/768/1440, zéro échec automatique |
| Parcours natifs | Deux cycles complets conservés + acceptation au clavier1440px et refus tactile390px |
| Captures | 32 produites ; huit nouvelles captures de coorganisation examinées personnellement |

[Rapport physique](physical-checks.json), [navigateur](browser-checks.json) et
[empreintes](sha256.json). Les 24 captures du cycle issues de la même exécution
sont conservées avec les nouvelles, sans les présenter comme huit nouveaux
parcours. La recette précédente du cycle et sa revue visuelle restent
[documentées séparément](../fans-hof-b6-session-lifecycle/README.md).

Cette recette corpus-joined n'exécute pas B3beh : elle ne constitue pas une
nouvelle preuve du correctif nonce. Le head final devra passer les recettes
requises et la vérification du code intégré.

### Scénarios natifs

- Invité, Fan et Créateur non associé refusés en GET/POST, nonce invalide ou d'un
  autre utilisateur, curseur malformé et cible suspendue refusés sans mutation.
- Deux invitations sur une révision : une seule acceptée et l'autre409 ; même
  contrôle pour deux réponses. Pas de révision renouvelée silencieusement.
- Invitation visible dans la liste privée du destinataire ; avant acceptation,
  aucun pouvoir d'organisateur ou de participant ; après acceptation, invitation
  possible mais règles/cycle réservés au propriétaire et aux permissions existantes.
- Refus encore accessible après suspension ; aucune acceptation implicite.
- Vingt-deux Créateurs supplémentaires approuvés, pagination réelle jusqu'à
  chaque candidat éligible, exclusion des rôles existants, lectures sans écriture.
- Retour SSO canonique testé pour le même écran/curseur, état scellé et sous-répertoire ;
  paramètres, état, date, catégorie ou encodage invalides refusés. Pas d'échange SSO réel.
- Parcours navigateur avec propriétaires/destinataires dans des contextes séparés,
  réponse native clavier et tactile, puis relecture de l'état enregistré côté propriétaire.

La concurrence de clients HTTP ne transforme pas le serveur PHP de recette en
preuve de parallélisme des workers ; les recettes SQL B2 existantes restent la
preuve transactionnelle du domaine. Aucun contrat Hub ou champ de score ajouté.

## Captures de coorganisation examinées

| État | Ordinateur | Mobile390px |
|---|---|---|
| Vue du propriétaire | [1440px](creator-session-coorganizers-1440.png) | [390px](creator-session-coorganizers-390.png) |
| Invitation du destinataire | [1440px](coorganizer-session-invited-1440.png) | [390px](coorganizer-session-invited-390.png) |
| Réponse explicite | [Acceptation clavier](coorganizer-session-accepted-1440.png) | [Refus tactile](coorganizer-session-refused-390.png) |
| Relecture du propriétaire | [1440px](creator-session-coorganizers-after-1440.png) | [390px](creator-session-coorganizers-after-390.png) |

DA, bandeau, Outfit, composants, conteneurs et navigation Fans conservés. Noms
seulement approuvés, sans UUID visible ou identité inventée. Sur mobile, les
captures sont des viewports en haut de page : les boutons sous le pli sont
atteints par défilement et ont été actionnés réellement au toucher dans le
parcours. Cela limite la preuve photographique de ces contrôles, même si les
mesures d'absence de débordement et les actions natives réussissent. Des vues
centrées sur les actions seront utiles dans la recette visuelle finale B6.
Le panneau de cycle vide avant approbation est l'écart de densité déjà signalé,
à traiter dans la finition B6 ; aucun changement global du shell.

## Limites et reprise

L'acceptation concerne la coorganisation, jamais une participation automatique.
Admission, territoire et choix d'attribution restent à raccorder aux attestations
existantes. Aucun classement de site annoncé disponible, aucun wallet ou montant.
Conservation150 et RustFS161 restent distincts. Revert des adaptateurs/vues si
nécessaire, en conservant rôles/journaux ; pas de schéma ou purge à inverser.
