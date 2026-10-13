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
