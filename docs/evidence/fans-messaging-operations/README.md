# Audit et préparation opérateur — 1 octobre 2026

Base auditée : 0.11.0, `f6f28a511d30a6e234f77f2c4622a2baf1246a0d`.
[Procédure opérateur complète](../../operations/FANS-MESSAGING-RUNBOOK.md).

## Défauts démontrés et correction

- Aucune préparation opérateur avant attestation/admission dans 0.11.0 :
  l’activation existante exige déjà ces constantes. Les commandes WP-CLI
  `prepare`, `status`, `purge` évitent toute ouverture temporaire, exigent le rôle
  Fans et les deux capacités, sans écrire flag, rôle, secret ou attestation.
- Les tables présentes enregistrent le sous-menu avant son parent Faluss.
  La recette restaure cet ordre dans **sa seule copie jetable**, reproduit le
  refus WordPress 403 puis restaure la priorité 20 et obtient 200.
- Refus du panel à l’administrateur sans habilitation privée avant les en-têtes,
  HTTP 403 et aucune preuve. Purge : code de sortie non nul pour erreur SQL,
  diagnostic non persisté ou lots restant. Horloges de conservation inchangées.

## Environnement et vérifications

- WordPress **7.1.2** réel, Twenty Twenty-Five, PHP **8.5.4**, MariaDB **11.8.6**,
  base neuve sur socket privé, `--skip-networking`, HTTP loopback seul. Mail/HTTP
  sortants bloqués, dépendances Composer physiques conformes au lock.
- **76 contrôles** CLI/SQL/REST/admin : permissions/révocation, préparation sans
  attestation, six tables exactes, conflits de récurrence/non-InnoDB/erreurs SQL,
  erreur du planificateur et du diagnostic, cron sans session, retard de 1 001
  conversations, demande/acceptation/échange, nonce/tiers, signalement et recours,
  fermeture, purge ordinaire et preuve/litige distincts. [Résultats](wordpress.json).
- Suite complète : **309 tests / 4 926 assertions**, deux dépréciations
  préexistantes ; PHPStan zéro erreur. Lint PHP, syntaxe JS, diff-check et scan
  ciblé. La suite SQL/HTTP existante profils/textes/images/messages passe aussi.
- Chromium **154.0.8037.58**, JS désactivé, **1440 × 1000** et **390 × 844** : six
  HTTP 200, sans débordement, aucun formulaire d’envoi après fermeture, recours
  visible, nonce modération présent, finalisation absente durant le recours et
  focus visible. [Résultats navigateur](browser.json).
- [Manifeste de 56 fichiers](runtime-sha256.json) réellement servis, comparés à
  l’arbre de travail avec normalisation CRLF → LF.

## Captures

| État de la fixture | Ordinateur | Mobile |
| --- | --- | --- |
| Admission fermée, conversation conservée | [Conversation](closed-conversation-desktop.png) | [Conversation](closed-conversation-mobile.png) |
| Recours après fermeture | [Recours](closed-appeal-desktop.png) | [Recours](closed-appeal-mobile.png) |
| Modérateur habilité, preuve minimale, recours en cours | [Panel](moderator-proof-desktop.png) | [Panel](moderator-proof-mobile.png) |

Messages/dossiers créés par les vraies API, comptes locaux synthétiques. Le
Créateur sans présentation approuvée demeure honnêtement indisponible côté
identité éditoriale. L’avatar WP administrateur manque (Gravatar sortant bloqué).
Les captures pleine page incluent la barre mobile fixe à sa position au viewport.

## Reproduction et limites

```text
python3 tests/Fans/Profiles/recipe/admission-wordpress.py --source <source-et-vendor-physiques> --core <WordPress-7.1.2> --cli <wp-cli-2.12.0.phar> --test --messaging --keep
node tests/Fans/Messaging/recipe/operations-browser.cjs <capture.json-privé-du-runner> <dossier-captures>
# Toucher STOP dans le répertoire /var/tmp/fans-admission-wp-* créé par ce runner.
```

La CI conserve ses contrôles et ajoute cette recette au job WordPress réel.
Aucun accès à un site/serveur/updater réel. Liaisons SSO synthétiques : aucun
échange Identity Me, recette Elementor, planificateur/sauvegarde cible ou
notification réelle attesté. La politique demeure à valider humainement.
Aucun flag de production touché.
