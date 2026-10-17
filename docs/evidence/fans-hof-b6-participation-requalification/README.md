# B6 — preuve du cycle session / participation

Source `8fc73c2bb8f4bae5be8109dbf0b014f097cd7271`, arbre
`50a0b31959d39040f382cb8adecad392aa239201`, export physique immuable
`/var/tmp/fans-hof-exact-yi0s4n4g`. [Empreintes des sources](source-proof.json).
Les ajouts suivants sont les preuves et leur documentation ; les vérifications
du head final puis du `main` intégré ne sont pas remplacées par ce rapport.

## Résultats obtenus

| Vérification | Résultat |
|---|---|
| [WordPress/MariaDB physiques](physical-checks.json) | 542 contrôles, zéro échec, dont15exécutions B6pq ; `fixture_removed=true` |
| [Qualité](quality-proof.json) | 809 tests/8142 assertions, HoF90/323, PHPStan0 avec cible80300 ; deux dépréciations historiques |
| Vérifications ciblées | 5PHP lintés,2Python compilés,7sources comparées,6liens, scan de secrets et diffcheck réussis |
| Environnement local | WordPress7.1.2,PHP8.5.4,MariaDB11.8.6 ; instances et données fictives |

Arguments strictement identiques à ceux du job existant :
`--b3-barrier-http --b3-barrier-completion-recovery --b3-barrier-acknowledgement --b1-origin-ack --b3-session-lifecycle`.
Les suites et assertions antérieures restent exécutées. Aucun workflow,
préparateur d'environnement, délai, cache ou runner modifié. Les nouveaux
workers et l'injection de faute sont des tests applicatifs.

## Scénarios du croisement

- Reprendre une admission dont le parent n'a pas changé ne crée aucune version
  ou événement primaire supplémentaire.
- Une session suspendue ne peut requalifier un participant. Après sa véritable
  réadmission, l'ancienne admission ne qualifie pas le nouveau parent.
- Invité, Fan et membre non lié ne peuvent effectuer cette requalification.
- Une réponse de fermeture réellement perdue après COMMIT Hub reste incertaine
  avec la même action. Le lookup primaire récupère son résultat.
- Une erreur d'insertion de version annule atomiquement la nouvelle date, la
  révision et l'audit ; les anciens descripteurs restent identiques.
- Un COMMIT local réellement effectué mais dont le résultat est perdu conserve
  une seule nouvelle version. Sa reprise utilise l'action déjà créée.
- La nouvelle date d'admission vient du serveur et concerne uniquement les
  contributions futures ; les règles consenties ne changent pas.
- Un ancien ACK ne retire ni ne réécrit l'admission suivante.
- Un retrait après fermeture attestée de l'ancien contexte se termine sans
  seconde fermeture Hub et sans nouvelle admission.
- Les reprises concurrentes du propriétaire et du participant produisent un
  seul retrait de contexte et une seule admission future.
- Le contrôle final conserve les octets du ledger officiel et confirme l'absence
  de ledger parallèle Fans.

Le motif `session_suspended` et le contrat1.0 existants sont utilisés sans
extension. Aucun score, rang, avantage irréversible ou opération PF n'est ajouté.
[Empreintes des preuves](sha256.json).

## Limites

Pas de véritable SSO Me, pair réel, site WordPress cible, thème/Elementor cible,
appareil physique, achat ou activation. Le raccordement de cette primitive à
l'interface se vérifie après #231 ; les captures de ce formulaire ne prouvent
pas à elles seules le présent cycle. L'ancienne ouverture jamais attestée garde
les limites de reprise documentées dans #230. Conservation #150 et RustFS #161
restent distinctes. Aucune publication n'est déclenchée par ce rapport.
