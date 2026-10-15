# B6 — barrières actuelles dans la transaction propriétaire

Lot de composition depuis #210, sans nouveau contrat Hub. Le transport et
les checkpoints de relecture #209 restent inchangés ; pas de schéma, API,
flag, cron, migration de site ou écriture économique supplémentaire.

Une livraison peut devoir vérifier plusieurs barrières de la même origine,
avec corpus/cache/identité/visibilité, sous une seule transaction. Les façades
ordinaires restent propriétaires de leurs BEGIN/COMMIT. Le nouveau contrat
`withAcknowledgementInTransaction` exige **transaction active et mutex exact
d'origine détenu par cette connexion primaire**, puis vérifie à nouveau les
preuves, signatures, confiance et états. Il ne démarre, valide, annule ou
libère aucune transaction/verrou appartenant à l'appelant.

`ClosedBarrierPrimaryRead::withCurrentAcknowledgementInTransaction` reprend
les mêmes contrôles de challenge courant. Aucun ACK ancien ne devient actuel
parce qu'un verrou est tenu ; le checkpoint doit avoir été préparé et confirmé
par HTTP avant cette section. Un BEGIN seul ou le mutex d'une autre origine
ne constitue ni cette preuve ni une autorisation. Le callback doit rester dans
la transaction et conserver le mutex ; leur présence est vérifiée avant/après.

L'ordre de composition est sessions → origine/barrières → corpus/cache.
Toutes les demandes HTTP et reprises sont achevées hors ces verrous. Les
conditions de participation, territoire et visibilité restent à composer dans
le fournisseur B6 ; ce sous-lot n'accorde pas de lecture publique ou privée.

Scénarios WordPress/MariaDB jetables ajoutés à `--b6-barrier-refresh` : lecture
courante équivalente, absence de transaction, absence de mutex, autre origine,
rollback commun du callback et commit uniquement par le propriétaire extérieur.
La garde physique refuse le chemin avant SQL sur un WordPress ordinaire.

Le [rapport de branche](../evidence/fans-hof-b6-barrier-transaction/README.md)
réussit 432 contrôles, dont six nouveaux B6bt ; ressources supprimées.

Compatibilité : mêmes contrôles réutilisés dans les façades existantes, ledger,
claims, SSO, interfaces et navigation inchangés. Retour arrière : retirer les
futurs appels de composition et revert, sans supprimer de données/checkpoints.
Aucune publication/activation ; #150 et RustFS #161 restent distincts.
