# Retour SSO et étude de l’invité

## Scénarios avant ouverture

Positifs : démarrer depuis Explorer, le HoF, un profil public ou une page privée
Fans ; effectuer le SSO existant Me ; revenir au chemin initial sans query ni
fragment. Support d’une installation en sous-répertoire. Le membre reçoit
seulement les droits déjà autorisés ; revenir à Créer ne crée pas de profil.

Négatifs : URL externe, `//host`, encodage, antislash, chemin admin/API/callback,
query, fragment, tableau POST, cookie falsifié, cookie d’un autre état, ancien
rejeu et expiration ne doivent pas imposer une destination. Une signature ne
remplace jamais la consommation transactionnelle de l’état SSO. Un administrateur
ne devient pas un membre SSO et n’obtient aucune élévation de privilège.

## Contrat du retour

La destination appartient à une liste fermée de chemins Fans. Le formulaire
`faluss_fans_sso_button` accepte un attribut serveur `return_to` ; l’UI lui passe
le chemin canonique de la page courante. Le démarrage reste un POST avec nonce.
Le callback Me reste exactement celui préenregistré, jamais remplacé par cette
destination. Aucun e-mail, secret ou paramètre OAuth n’est transmis dans le retour.

Un cookie `faluss_fans_sso_return`, Secure/HttpOnly/SameSite=Lax, hôte courant,
durée de dix minutes, contient le chemin encodé et une signature HMAC liée à
l’état SSO aléatoire. La clé vient du sel serveur WordPress. Ce cookie est une
indication de navigation, pas une identité ni un droit. Il est vérifié seulement
après la consommation de l’état et revérifié contre la liste de chemins. Les deux
cookies sont effacés au callback. Une nouvelle tentative remplace le couple ; un
ancien retour ou un cookie associé à un autre état ne fonctionne pas.

Après succès, le serveur redirige vers ce chemin ; les permissions de destination
sont réévaluées normalement (403/404 possibles). Cookie absent/invalide ou client
historique : retour racine inchangé. Échec SSO : notice locale historique, aucune
redirection vers la page privée. Pas de migration, nouveau SMTP, passwordless,
table ou transport Identity.

## Invité — étude, pas une session implémentée

Le visiteur peut explorer et lire le HoF indisponible sans compte. La proposition
de connexion/création de compte passe exclusivement par Me. **Aucune progression
invité n’est actuellement acquise ou récupérable dans ce code** ; aucune promesse
de conservation n’est affichée. L’alias et le badge invités ne sont pas activés.

| Sujet | Proposition à arbitrer | Condition avant implémentation |
| --- | --- | --- |
| Session provisoire | Identifiant opaque aléatoire, cookie propre Fans HttpOnly/Secure ; jamais empreinte navigateur | Durée, consentement, limitation de volume, suppression et rétention |
| Alias visible | `Guest_…` dérivé d’un alias aléatoire distinct de l’identifiant secret ; aucune identité civile suggérée | Unicité, renouvellement, portée des surfaces et modération |
| Badge visible | Libellé neutre « Visiteur », distinct de tout niveau, contribution ou badge gagné | Validation graphique et sémantique ; aucune progression fictive |
| Création de compte | CTA vers le SSO Me avec retour d’origine | Ne pas envoyer l’identifiant de session invité à Me en URL |
| Reprise | Liaison atomique au compte local prouvé après SSO, preuve de possession de session, consommation unique | Protocole attesté des faits à reprendre, conflits, rejouabilité, concurrence, annulations et changement de compte |
| Classement | Aucun rang invité public avant politique approuvée et autorité d’attribution | Décisions du [Classement Fans](FANS-FAN-RANKING.md) et garanties Hub |

Le scénario de reprise doit ultérieurement prouver : une même contribution ne
compte pas pour deux comptes ; deux callbacks ne la reprennent pas deux fois ;
une session volée, expirée, effacée, réinitialisée ou déjà liée échoue fermée ;
une correction authentifiée continue d’affecter le fait après liaison. Aucun
historique n’est fusionné sur simple égalité d’e-mail ou d’alias.

Le paiement invité reste hors de ce contrat. La proposition ne crée aucun droit
de débit, solde ou ledger PF/PC. Les contrats Hub non ratifiés restent bloquants.

## Preuves et limites

Tests isolés des listes de chemins, signatures, état consommé, rejet/rejeu et
formulaires ; captures du shell de connexion. Pas d’accès à Me ou Fans réels,
pas de test OTP/SMTP, pas de preuve du rendu cible WordPress/Elementor. La recette
du propriétaire avant activation doit inclure l’aller-retour navigateur réel et
les cookies HTTPS. Revert du lot : fallback historique à la racine, aucune donnée
métier à restaurer.
