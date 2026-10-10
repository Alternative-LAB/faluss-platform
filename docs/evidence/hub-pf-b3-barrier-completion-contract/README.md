# Preuve du codec de clôture normale 1.1 — 10 octobre 2026

Copie immuable LF du tree Git `bd7f06c50903bd5c0612f44d26d7e6b7655414bf`,
verrou Composer exact, PHP 8.5.4 local. Aucun site consulté.

- Codec barrières : **37 tests / 89 assertions**, dont cinq tests supplémentaires
  de version explicite, close/lookup, contexte signé, référence historique et
  réponse liée à sa version/action/raison. Les scénarios 1.0 restent verts.
- Suite complète : **662 tests / 7 076 assertions**, zéro échec ; deux
  dépréciations PHPUnit historiques. HoF : 51 / 105.
- PHPStan complet, cible PHP 8.3 : aucune erreur.
- Lint des trois PHP modifiés, comparaison LF des sources avec la copie testée,
  scan ciblé de secrets, liens relatifs et diff check requis avant commit.

Le codec ne valide **pas** une échéance primaire, un type de barrière conservé
en base, un COMMIT ou une panne réseau. Le gateway et la reprise existants
restent en 1.0. Aucun nouveau hook, route, schema, écriture économique ou flag.
La recette SQL/HTTP 1.1 appartient aux sous-lots suivants du
[contrat approuvé](../../modules/HUB-PF-B3-SESSION-COMPLETION.md).
