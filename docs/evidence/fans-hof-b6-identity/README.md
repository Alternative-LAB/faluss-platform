# B6 — preuve de filiation locale

14 octobre 2026, export Git LF isolé du tree source
`0f3438fd44bdb4ec10d1ef9d5e8267fc0703e206`, depuis main28a.
WordPress **7.1.2**, PHP **8.5.4**, MariaDB **11.8.6**, comptes et identités
fictifs. Commande : `admission-wordpress.py --test --hof-b6-visibility`.

[Rapport expurgé](wordpress-checks.json) : **120 contrôles**, code zéro,
dont **46 B6id**, **24 B6v** et **50 B1**. `fixture_removed=true` ; aucun worker,
configuration de comptes ou fichier de session conservé dans cette preuve.

- Filiation compte/profil distincte, primaire et connexion partagés.
- Noms approuvés, consentements séparés, révision en attente/refusée, suspension
  et réadmission. Pas de mapping de navigateur, email, UUID ou chronologie
  de départage dans la projection d'affichage.
- Caches WordPress effectivement primés avant modifications SQL : rôle
  administrateur/éditeur/multiple, rôle absent/ambigu/corrompu et compte
  supprimé ne conservent aucune autorisation. Lien retiré, MyISAM et réplica
  refusés. Pas de COMMIT implicite, rollback vérifié.
- Retrait concurrent du lien réellement en attente du verrou ; après son
  commit la lecture suivante refuse le compte. Restauration fictive du lien
  sans restauration automatique du consentement.

Qualité au tree `9b6bde742a1471f72076bbe4b7c9edcb6e37bd35` : **722 tests /
7 501 assertions**, HoF **84 / 291**, PHPStan cible8.3 zéro erreur, deux
dépréciations historiques. La seule différence runtime depuis la recette est
l'annotation PHPDoc `@phpstan-impure` du lecteur d'état d'erreur SQL ; aucune
instruction exécutable modifiée. PHPStan traite ainsi correctement les lectures
successives d'une propriété wpdb modifiée par chaque requête. Les sources PHP
modifiées sont lintées ; Python compilé, liens relatifs et scan ciblé réussis.

**Limites** : lignes classées synthétiques pour cette recette de filiation ;
pas de corpus Hub ou livraison UI dans ce lot. La preuve signée et économique
est celle de la [consolidation](../fans-hof-main-consolidation/README.md).
La jonction complète, le véritable SSO, les sites, RustFS et la production ne
sont pas déduits de ces tests. Aucun contrat Hub, flag ou schéma nouveau.
