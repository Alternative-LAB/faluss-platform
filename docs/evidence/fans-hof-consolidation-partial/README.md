# Consolidation — recette partielle au point de pause du 13 octobre

Arbre `4fdb52453b6da61aac1d3a91ab497c661819b7bf`, Hub/Fans WordPress/MariaDB
jetables, clés et identités fictives, HTTP loopback. **598 contrôles achevés**,
puis échec à `B3cr late primary absence never rewinds a newer materialized checkpoint`.
La lecture a conservé `pending / pf_transport_unknown`, après un HTTP 200 puis
une attente HTTP de 8 secondes. La cause code/environnement n'est pas établie.
Aucun contrôle B6 de fraîcheur corrigée, transaction ou nouvelle jonction atteint.

L'arrêt du primaire a dépassé son délai de 20 secondes. Après son arrêt effectif,
zéro worker de ces invocations était actif. Deux fixtures orphelines ont été
supprimées après contrôle de chemin, propriétaire, configuration de socket et
empreinte du code ; neuf copies sources temporaires supprimées. Les logs de
qualité et le rapport expurgé sont conservés. Aucun site ou flag de production.

La suite PHP de cet arbre est verte : **722/7 483**, HoF **84/291**, analyse
statique cible 8.3 ; deux dépréciations historiques. Ces succès ne prouvent ni
la jonction nouvelle ni main consolidé. La dernière correction de budget #202
`69bf2cc` reste à intégrer dans #203 avant la reprise ciblée de la recette.
Les PR restent en brouillon, aucun travail supplémentaire pendant la pause.

[Rapport partiel expurgé](partial-checks.json). Aucun wire, clé, code, cookie,
identité ou contenu privé. Contrats approuvés inchangés ; #150/#161 distinctes.
