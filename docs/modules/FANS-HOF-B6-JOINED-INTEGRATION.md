# Jonction fermée des barrières et projections — en validation

Cette recette de consolidation utilise le gateway de barrières signé et la
lecture réelle du corpus/cache sur les mêmes WordPress/MariaDB jetables.
Le primaire Hub, les permissions dédiées, l'ACK et la transaction locale sont
réels ; les identités, preuves et clés sont fictives. Aucun nouveau contrat Hub.

Le routeur est exclusivement un MU-plugin de recette sous bail POSIX isolé,
avec marqueur local, clés de test et écoute loopback. Aucun endpoint n'est
ajouté au plugin distribué ou à un site ordinaire.

Scénarios prévus : absence d'ACK, HTTP signé, même origine, rollback commun,
COMMIT du cache dans la transaction de barrière, confiance révoquée et fermeture
locale avant ACK de fermeture. Aucun mapping SSO réel ni score public testé.

Arbre de vérification `4fdb52453b6da61aac1d3a91ab497c661819b7bf` : suite PHP
**722/7 483**, HoF **84/291**, analyse statique cible 8.3 sans erreur ; deux
dépréciations historiques. Deux PHP lintés, deux Python compilés, scan ciblé,
égalité LF, YAML et diff vérifiés. Recette SQL arrêtée en échec avant les nouveaux contrôles : 598 vérifications achevées. Résultat
consigné dans ../evidence/fans-hof-consolidation-partial. Aucun succès des nouveaux contrôles annoncé.

Le point de pause précis et les SHA sont conservés dans le suivi local.
La PR #203 reste en brouillon ; aucune fusion ou publication pour cette pause.

## Reprise et correction de la recette

L'horloge partagée corrigée passe **28 contrôles de relecture primaire** et
**18 contrôles transactionnels** sur le tree `67e05e54`. La jonction suivante
refuse une préparation de fixture incomplète ; `lookup_operation` obligatoire
a été ajouté sans modifier le contrat. La tentative au tree `4f6e6b25` achevé
712 contrôles s'arrête encore avant l'ACK réseau. Sa fixture essayait d'adopter
une origine précédemment enregistrée directement par Hub avec une autre clé
et un autre contexte. La règle de reprise interdit cette adoption ; aucune
modification de la persistance ou des contrats n'en est déduite.

La recette ouvre désormais une **nouvelle origine fictive par le vrai client
et gateway signé avant lecture du corpus**, sans toucher l'origine précédente.
Elle vérifie un corpus exhaustif vide, un pack fictif sans point, puis une seule
consommation officielle fictive alimentant les projections Fan/Créateur et sa
dernière correction partielle. La transaction authentifiée ne livre qu'une
origine encore `active` : la façade de gouvernance expose aussi `closing` et
`closed`, et son consommateur doit les refuser pour cette livraison.

Les nouvelles étapes doivent encore passer ensemble. B1/B2 complet, mapping
SSO, barrières de participation et pays, choix d'intention et UI publique restent
distincts ; aucun classement de site, vrai achat, remboursement monétaire ou
nouvelle permission/contrat Hub annoncé. Les diagnostics publient uniquement
états et motifs techniques, jamais clés, wire, identités ou contenu privé.
