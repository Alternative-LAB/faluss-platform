# B6 — primitive d'affichage filtré

10 octobre 2026, arbre LF immuable `57746eaa01583949133e9a16173f1fc18239d434`,
Composer exact ; WordPress 7.1.2, PHP 8.5.4, MariaDB 11.8.6. Nouveau WordPress,
base/socket privés et comptes fictifs liés localement ; aucun site ni vrai SSO.

```sh
python3 tests/Fans/Profiles/recipe/admission-wordpress.py \
  --source "$SOURCE_ISOLEE" --core "$CORE_JETABLE" --cli "$CLI_JETABLE" \
  --test --hof-b6-visibility --output /var/tmp/fans-hof-b6-checks.json
```

**67/67 contrôles**, 50 gouvernance B1 inchangés puis 17 nouvelles vérifications
de filtrage. Fixture, serveur et base détruits. Rapport [expurgé](wordpress-checks.json).

- Un compte non lié, un alias révoqué et un consentement absent ne rendent
  aucune ligne. Consentement seul et nom en attente ne publient pas de score.
- Alias approuvés + consentements réels produisent des places 1/2 parmi les
  visibles ; le score supérieur masqué ne révèle ni rang ni identité.
- Révision en attente/rejetée conserve uniquement le nom déjà approuvé ;
  retrait de consentement ou révocation masque et recalcule les places.
- Présentation Créateur retirée/en attente ou profil suspendu masque ;
  réadmission recontrôle l'accord et les champs approuvés. Accord classement
  Créateur distinct et révocable, aucun journal économique ou table de score.

PHPUnit complet **648 tests / 7 102 assertions**, HoF 76/259 dont sept tests
nouveaux/20 assertions ; PHPStan cible 8.3, zéro échec et deux dépréciations
historiques. Trois PHP lintés, deux Python compilés, égalité LF des cinq fichiers
exécutables, scan ciblé et contrôle du diff. Une erreur de typage PHPDoc signalée
par PHPStan a été corrigée, puis la suite entière rejouée avec succès.

Les entrées de classement sont synthétiques : ces contrôles prouvent le
filtrage des véritables états locaux, **pas** leur composition avec corpus Hub,
origine ouverte, fraîcheur, choix d'intention, ni API/UI B6 complète. Aucun
score public, flag, classement actif, média, site ou release ; pas de capture
visuelle pour cette primitive. Les captures suivront le raccordement des vues.
