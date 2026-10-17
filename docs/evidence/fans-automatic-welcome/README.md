# Bienvenue automatique textuelle — recette fermée

## Résultat et limites

Les [règles produit corrigées](../../modules/FANS-AUTOMATIC-MESSAGES.md) ne
demandent aucun consentement Fan additionnel. Le suivi autorise uniquement la
bienvenue activée ; ce n'est pas une acceptation générale de conversation.
Les modes de contact restent ceux de #235. Aucun site ou flag réel n'est touché.

Ce premier lot raccorde **Bienvenue** au véritable suivi et aux conversations,
notifications, modération et purges existantes. Remerciement est préparé séparément
mais son envoi est encore indisponible, refusé côté serveur. Aucun achat/PF réel,
simulation client ou correction ne peut le déclencher. La pièce jointe reste
indisponible tant que #161 n'est pas vérifiée ; aucune adaptation RustFS ici.

## Preuves acquises

- [Qualité et sources](source-proof.json) : 814 tests/8329 assertions sur la source
  automatique, HoF90/323, PHPStan sans erreur, 26 PHP lintés, deux modules Python
  compilés, scan ciblé, liens et diff vérifiés. Deux dépréciations antérieures.
- Après jonction de main98de07e (#234) :814 tests/8368 assertions, HoF90/323,
  PHPStan0, puis les mêmes137 contrôles WordPress sur une nouvelle instance.
  Les conflits dus au squash de #235 ont été résolus après preuve que les blobs
  messagerie côté main étaient identiques au parent déjà hérité ; aucun nouveau
  travail territorial ou test n'a été écarté.
- SQL/HTTP : 426 lignes PASS, dont les scénarios automatiques réels sur MariaDB.
  Version de texte figée, annulation/désactivation, réabonnements, anciens suivis,
  refus et blocages, suspension, quotas partagés et reprise, rollback après insertion
  du message mais avant résultat durable, réponse perdue, deux workers concurrents,
  purge des textes et maintien de l'unicité. Les primitives WordPress y sont des
  adaptateurs : **ce n'est pas la preuve WordPress**.
- [137 contrôles WordPress](wordpress-checks.json) : installation réelle WordPress
  7.1.2/PHP8.5.4/MariaDB11.8.6 jetable, vrais comptes/cookies/nonces locaux et
  liaisons Identity synthétiques. Hook asynchrone réel, deux suivis HTTP concurrents,
  bienvenue unique, demande puis acceptation explicites, paramètres étrangers et
  images refusés, source économique fermée. Aucun véritable SSO Me n'est testé.
- [Navigateur](browser.json) : formulaires natifs avec JavaScript désactivé pour
  les réglages ; aperçu sans sauvegarde, texte et état conservés, sauvegarde,
  suivi/retrait POST et nonce, fil automatique lisible sans saisie libre,
  largeurs1440/390/320, focus visible, absence de débordement horizontal.

Le banc navigateur remappe uniquement les liens/HTML de l'hôte canonique fictif
vers le loopback. Le POST et son303 sont vérifiés réellement ; un GET supplémentaire
après le303 normalise les URL REST pour la capture, car Playwright ne réintercepte
pas le GET redirigé. Ce n'est pas une preuve TLS, Elementor cible ou appareil réel.
Les premiers essais de capture avec API vers l'hôte fictif ont été rejetés ; ils
ne prouvent pas le rendu. Aucun changement de sécurité applicative pour les faire
passer. Les captures finales ont été examinées personnellement.

## Captures

| État | Ordinateur | Mobile390 | Mobile320 |
| --- | --- | --- | --- |
| Bienvenue : réglage et focus | [1440](automatic-settings-1440.png) | [390](automatic-settings-390.png) | [320](automatic-settings-320.png) |
| Suivre gratuitement | [1440](automatic-follow-1440.png) | [390](automatic-follow-390.png) | [320](automatic-follow-320.png) |
| Bienvenue reçue, sans ouverture générale | [1440](automatic-received-1440.png) | [390](automatic-received-390.png) | [320](automatic-received-320.png) |
| Remerciement préparé, envoi indisponible | [1440](automatic-thanks-unavailable-1440.png) | [390](automatic-thanks-unavailable-390.png) | [320](automatic-thanks-unavailable-320.png) |

La fixture n'a ni présentation/portrait approuvés ni publications ouvertes : le
profil utilise les états graphiques existants, sans identité ou média inventé.
La sidebar, les colonnes ordinateur et les règles de navigation sont conservées.

## Incidents de recette préservés

Un premier passage SQL s'est arrêté avant les nouveaux cas, sur une segmentation
du processus MariaDB dans le socle Profils. Le passage inchangé suivant, sur une
nouvelle base privée, est complet. La cause racine n'est pas présentée comme
corrigée. Une ancienne fixture WordPress a dépassé l'attente de20 secondes lors
de l'arrêt MariaDB ; le processus a ensuite terminé. Aucun délai, environnement,
workflow, test ou runner CI n'a été modifié. Les résultats de contenu et le
nettoyage des ressources sont consignés séparément.
