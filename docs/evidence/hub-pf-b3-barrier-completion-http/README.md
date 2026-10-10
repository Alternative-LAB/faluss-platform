# B3b4c — preuve du transport signé 1.1 fermé

Recette du 10 octobre 2026 sur la copie Git immuable
`d09196d1278356e95f54a370368a5fb52ce09223`, avant ajout de cette preuve et de
sa description documentaire. Source runtime inchangée depuis la copie.
WordPress 7.1.2, MariaDB 11.8.6, PHP 8.5.4 ; deux bases distinctes, clés,
pairs, achats et identités fictifs. Aucun site ni véritable SSO.

- `run.py --b3-barrier-completion-http` : **357 contrôles**, dont **21 nouveaux**
  de transport 1.1, zéro échec. Le [rapport expurgé](wordpress-checks.json)
  confirme la destruction de la fixture ; aucun corps HTTP, clé ou identité.
- Suite complète : **662 tests / 7 076 assertions**, deux dépréciations
  historiques ; HoF 51 / 105 et PHPStan complet cible PHP 8.3 satisfaits.
- Version/contexte/audience/signature/permission invalides refusés avant nonce.
  Avant échéance primaire : réponse signée de refus ; lookup absent ne clôture
  pas. Huit requêtes concurrentes à nonces distincts produisent un événement.
- Corps perdu après COMMIT : lookup primaire et rejeu sur la même action/clé,
  sans seconde clôture. Réponse d'une autre version/digest/signature refusée.
  Le lookup d'ouverture 1.0 reste valide sans réouverture ; ledger inchangé.

Le test de bornes utilise un fichier privé 0600 d'horloge primaire SQL,
accessible seulement dans l'enclave physique. Le test d'attente réelle des
verrous est la [preuve propriétaire](../hub-pf-b3-barrier-completion-owner/README.md),
pas cette horloge contrôlée. La reprise durable Fans 1.1 et le raccordement B2
ne sont pas revendiqués dans ce lot. Aucun hook de production, migration,
score public, récompense, flag ou admission réseau réelle.

Retour arrière : retirer le dispatch fermé et sa fixture, sans réécrire les
opérations 1.0 ou le ledger. [Contrat approuvé](../../modules/HUB-PF-B3-SESSION-COMPLETION.md).
