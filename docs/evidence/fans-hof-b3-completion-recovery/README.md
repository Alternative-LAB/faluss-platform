# B3b4d — reprise Fans 1.1, preuve isolée

Copie Git immuable `9dba53693178ab6bbc9ff466c31f044cf5d29ac0`, recette du
10 octobre 2026 avant ajout de cette preuve. Deux WordPress 7.1.2 / MariaDB
11.8.6 jetables, PHP 8.5.4, clés/pairs/identités/achats fictifs. Aucun site.

- `run.py --b3-barrier-completion-recovery` : **385 contrôles**, dont
  **28 nouveaux**, zéro échec. Le [rapport expurgé](wordpress-checks.json)
  confirme la destruction de la fixture, sans identités ou corps privés.
- Suite complète **662 / 7 076**, HoF **51 / 105**, PHPStan complet cible
  PHP 8.3 satisfaits ; deux dépréciations PHPUnit historiques.
- Six préparations concurrentes conservent une action/clé/version. Dégradation
  vers 1.0 ou version inconnue refusée ; une demande signée d'une autre version
  ne devient pas un checkpoint durable. Les actions historiques gardent leurs octets.
- Refus primaire avant échéance : pending/closing, jamais clôture attestée.
  À échéance, lookup puis close ; réponse perdue après COMMIT récupérée sur
  la même clé sans nouvel événement. L'ouverture 1.0 se résout avant fermeture
  et son ancien ACK ne réouvre pas. Chaque HTTP suit le checkpoint durable,
  hors transaction et mutex local, confirmé par instrumentation SQL.
- Insertion échouée, COMMIT incertain et mort réelle avant/après COMMIT :
  état/action atomiques et reprise sur les mêmes octets. Clé Hub révoquée et
  substitution de version avec digest local correspondant refusées.
- Trois PHP lintés, quatre Python compilés, huit comparaisons LF, scan ciblé,
  liens documentaires et `git diff --check` satisfaits. Ledger inchangé ; aucune
  table Fans de ledger créée, aucun schéma ou migration modifié.

La version est persistée dans une enveloppe locale distincte ; les anciennes
lignes 1.0 ne sont pas converties. Retour arrière : l'ancien lecteur refuse
les nouvelles actions et ferme la reprise ; restaurer le lecteur compatible
sans changer les clés. Les faits Hub et actions 1.0 restent intacts.

Le fichier privé d'horloge SQL borne la recette réseau ; l'attente réelle est
couverte par la [preuve propriétaire](../hub-pf-b3-barrier-completion-owner/README.md).
Le raccordement B1/B2, les choix applicatifs et le véritable SSO ne sont pas
attestés ici. Aucun classement public, activation, pair réel, récompense ou
politique de conservation réelle. [Contrat](../../modules/HUB-PF-B3-SESSION-COMPLETION.md).
