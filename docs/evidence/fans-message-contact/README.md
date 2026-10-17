# Modes de contact — preuves de branche

Lot préalable aux [messages automatiques](../../modules/FANS-AUTOMATIC-MESSAGES.md),
sur base main `cd42356eb62391c8e7f53af3ddc985fade889c14`. Aucun déclencheur
automatique ou économique n'est déclaré fonctionnel par ce lot de contact.

## Vérifications acquises

- [WordPress/MariaDB](wordpress.json) : **101 contrôles réussis**, dont25 cas de
  mode de contact ; vrais services, sessions/cookies WordPress et nonce REST,
  identités locales fictives distinctes. Ouverture directe par défaut, restriction
  volontaire, une seule édition concurrente gagnante, préservation des refus,
  demandes en attente, blocages et conversations acceptées. Rétention et recours
  restent testés avec admission fermée. WordPress7.1.2/PHP8.5.4/MariaDB11.8.6.
- Recette SQL/HTTP existante `Profiles/recipe/run.py --messages --http` réussie :
  schéma absent refusé, installation idempotente, texte/nonce/permissions/quotas,
  retour SQL en erreur et rollback. Il s'agit d'un adaptateur WordPress et de
  vraie MariaDB, **pas** de la preuve WordPress ci-dessus.
- [Qualité](quality.json) :814 tests PHP/8 264 assertions, dont90/323 HoF,
  PHPStan sans erreur avec cible PHP80300 ; deux dépréciations historiques.
  Une première analyse a relevé une annotation de retour insuffisante, corrigée
  avant ce résultat. Aucun contrôle n'a été assoupli.
- [Navigateur](browser.json) : formulaires natifs **sans JavaScript**, entrée
  depuis Messages, changement Ouvert/Sur demande, sauvegarde et relecture
  serveur, focus visible et absence de débordement en1440/768/390/320px.
  Huit captures examinées personnellement, ci-dessous. La recette conserve les
  envois globaux fermés ; les réglages restent modifiables. Seules les URL de
  liens/formulaires de l'hôte synthétique sont adaptées à l'origine loopback,
  jamais les contrôles ou réponses métier.

Les [sources exactes](source.json) distinguent l'arbre de qualité et celui des
nouveaux tests physiques ; leurs sources applicatives sont identiques après
normalisation CRLF/LF (l'export Git Windows conserve parfois CRLF). La CI
du futur head, sa revue et le futur main intégré restent des preuves séparées.
Ni SSO Me central, appareil physique, Elementor cible, site réel, attestation
humaine, flag de production, achat, rétention #150 ou stockage privé #161 validés.

## Captures

| État | Ordinateur1440 | Tablette768 | Mobile390 | Mobile320 |
| --- | --- | --- | --- | --- |
| Ouvert | [Voir](contact-open-1440.png) | [Voir](contact-open-768.png) | [Voir](contact-open-390.png) | [Voir](contact-open-320.png) |
| Sur demande | [Voir](contact-request-1440.png) | [Voir](contact-request-768.png) | [Voir](contact-request-390.png) | [Voir](contact-request-320.png) |
