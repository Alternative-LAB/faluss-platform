# Messagerie Fans — procédure opérateur

Audit du code **0.11.0**, base `f6f28a511d30a6e234f77f2c4622a2baf1246a0d`.
Les commandes `wp faluss fans-messaging` sont ajoutées par le correctif accompagnant
ce document ; elles ne sont **pas présentes dans le ZIP 0.11.0**. Installer la
version officielle contenant ce correctif avant les étapes ci-dessous.
Le correctif rétablit également l’accès au panel de modération : en 0.11.0,
la présence des tables de messagerie enregistre son sous-menu avant le parent
Faluss et provoque un refus WordPress 403 même avec les deux habilitations.

Cette procédure est destinée à l’exploitant de `fans.faluss.me` : aucune étape
n’a été exécutée sur ce site pendant l’audit. Aucun flag, secret, compte réel ou
attestation n’a été modifié. [Politique et traitements](../modules/FANS-MESSAGING.md).

## 1. Conditions et décisions humaines, avant ouverture

Nommer un responsable d’exploitation et un modérateur titulaire, avec suppléant
pour absence ou conflit d’intérêts. Un modérateur partie à une conversation ne
peut pas examiner son propre dossier. `manage_options` est un droit WordPress
étendu : n’habiliter qu’un administrateur de confiance, jamais un membre SSO.
Un administrateur ordinaire n’a pas accès aux preuves sans l’habilitation dédiée.

Décisions à consigner et valider par les personnes responsables :

| Décision | Précisions indispensables |
| --- | --- |
| Notifications | Quels événements : demande, réponse, décision, recours, décision définitive ? Quels destinataires, canal, contenu minimal, preuve de délivrance, relance et traitement des échecs ? |
| Recours | Canal accessible aux deux parties, information sur la décision, délai et point de départ prouvé, délai de traitement, examinateur compétent et gestion des conflits, escalade et définition de la décision définitive. |
| Dossiers et litiges | Responsable des réexamens ; alerte avant les 30 jours opérationnels ; autorité de conservation de litige, motifs, contrôle et renouvellement explicite de 1 à 90 jours. |
| Exploitation | Planificateur fiable, alertes, astreinte, sauvegardes/exports/réplicas, délai d’effacement et application des purges lors d’une restauration. |
| Abonnements | Accepter ou non une ouverture limitée aux demandes ordinaires : l’ouverture directe Max/abonné Créateur reste indisponible faute de projection serveur attestée. Ne pas annoncer cet avantage comme livré. |

Le code ne fournit **ni notifications externes ni délai de recours automatique**.
La visibilité de l’état dans « Signalements et recours » ne prouve pas une
notification reçue. Un canal manuel peut être retenu seulement s’il est décidé,
accessible, exploitable et tracé avant activation. Aucun nouveau SMTP/passwordless
sur Fans. Les sauvegardes ne sont pas effacées par les `DELETE` applicatifs.

Ne définir `FALUSS_FANS_MESSAGING_POLICY_ATTESTED=true` qu’après cette validation
humaine datée. La commande de préparation et un résultat technique vert ne
constituent **jamais** cette attestation.

## 2. Vérifier l’installation et habiliter le modérateur

Les commandes suivantes sont des exemples Linux à adapter par l’opérateur au
chemin WordPress, au binaire WP-CLI et à l’utilisateur système de PHP. Elles ne
contiennent aucun secret. En multisite, sélectionner le blog Fans avec `--url`.

```sh
WP_ROOT=/chemin/wordpress
WP_CLI=/chemin/wp
MODERATOR_ID=123 # remplacer par l’identifiant WordPress local de l’administrateur désigné
"$WP_CLI" --path="$WP_ROOT" --url=https://fans.faluss.me core version
"$WP_CLI" --path="$WP_ROOT" --url=https://fans.faluss.me plugin get faluss-platform --fields=name,status,version
"$WP_CLI" --path="$WP_ROOT" --url=https://fans.faluss.me user get "$MODERATOR_ID" --fields=ID,user_login,roles
"$WP_CLI" --path="$WP_ROOT" --url=https://fans.faluss.me user add-cap "$MODERATOR_ID" manage_options moderate_faluss_fans_messages
"$WP_CLI" --path="$WP_ROOT" --url=https://fans.faluss.me --user="$MODERATOR_ID" eval 'if (!current_user_can("manage_options") || !current_user_can("moderate_faluss_fans_messages")) { WP_CLI::error("Habilitation incomplète"); } WP_CLI::success("Deux capacités vérifiées");'
```

Le compte doit déjà avoir le rôle `administrator` (ou être super-administrateur
approprié en multisite) pour accéder aux écrans WordPress : la protection Fans
redirige les rôles ordinaires même avec des capacités ajoutées. Ne pas convertir
un compte SSO en administrateur pour cette recette. Journaliser la désignation,
son auteur, son périmètre et la date dans le registre d’exploitation ; la décision
de chaque dossier est ensuite journalisée par le plugin. Pour révoquer :

```sh
"$WP_CLI" --path="$WP_ROOT" --url=https://fans.faluss.me user remove-cap "$MODERATOR_ID" moderate_faluss_fans_messages
```

Vérifier ensuite le refus de lecture du panel/REST. Ne retirer `manage_options`
ou un rôle administrateur que selon le périmètre autorisé ; ces droits peuvent
être hérités d’un rôle et servir à d’autres responsabilités. Ne pas ajouter la
capacité dédiée au rôle `administrator` entier.

## 3. Préparer et vérifier les schémas, admission fermée

Vérifier le rôle `fans`, le plugin actif, le SSO configuré et le schéma de liaison,
les profils et leur schéma. Ne jamais imprimer les constantes du secret SSO.
Les flags existants SSO/profils/UI doivent être autorisés par l’exploitant ; aucune
commande ci-dessous ne les écrit. Laisser l’admission messagerie fermée, et
l’attestation absente ou fausse tant que la validation humaine manque.

```sh
"$WP_CLI" --path="$WP_ROOT" --url=https://fans.faluss.me --user="$MODERATOR_ID" faluss fans-messaging status
"$WP_CLI" --path="$WP_ROOT" --url=https://fans.faluss.me --user="$MODERATOR_ID" faluss fans-messaging prepare
```

`prepare` crée seulement les tables manquantes et vérifie celles présentes,
sans réparer/remplacer une table non conforme ni effacer des données. DDL additive,
non atomique entre les six créations : en cas d’échec partiel, conserver les
tables et diagnostiquer avant une reprise. Sauvegarde contrôlée avant DDL ; aucun
export privé dans Git ou un ticket public.

| Groupe | Tables sous le préfixe WordPress réel | Option attendue |
| --- | --- | --- |
| Ordinaire | `faluss_fans_dm_threads`, `faluss_fans_dm_messages`, `faluss_fans_dm_blocks` | `faluss_fans_messages_schema_version = "1"` |
| Preuves | `faluss_fans_dm_reports`, `faluss_fans_dm_report_events`, `faluss_fans_dm_legal_holds` | `faluss_fans_message_reports_schema_version = "1"` |

`schemas_ready:true` vérifie **InnoDB, colonnes exactes, nullabilité et index** des
six tables, pas seulement les options. Attendre `sso_ready:true`,
`profiles_ready:true`, `hourly_event:true`. `retention_healthy` reste faux avant la
première purge. `prepare` ne crée aucune conversation et ne valide aucune politique.
Si une erreur `*_schema_failed`, `*_prerequisites` ou `*_schedule_conflict` apparaît,
arrêter l’ouverture ; diagnostiquer privilèges SQL, schéma/option et événement
existant. Ne pas supprimer des tables pour contourner le refus.

## 4. Installer une exécution horaire fiable et la surveiller

L’événement WordPress est `faluss_fans_messages_retention`, récurrence `hourly`,
intervalle 3 600 secondes, aucun argument. `prepare` vérifie sa création ; une
récurrence différente n’est pas remplacée silencieusement. L’événement seul ne
prouve pas une exécution fiable : WP-Cron dépend du trafic.

Préférer un planificateur système exécutant les événements échus chaque minute,
avec verrou, journal technique privé et alerte sur échec. Exemple **crontab de
l’utilisateur système du site**, à adapter après validation des chemins/droits :

```cron
* * * * * /usr/bin/flock -n /var/lib/faluss/fans-cron.lock /usr/local/bin/wp --path=/chemin/wordpress --url=https://fans.faluss.me cron event run --due-now >> /var/log/faluss/fans-cron.log 2>&1
```

Créer au préalable les répertoires de verrou et de journal appartenant à cet
utilisateur, avec accès restreint et rotation du journal. Aucun cookie, secret ou
contenu de message dans ces journaux. Ne pas ajouter `--skip-plugins` : le callback
de purge doit être chargé. En multisite, couvrir séparément les autres blogs et
leurs crons avant de désactiver WP-Cron basé sur trafic. Définir
`DISABLE_WP_CRON=true` uniquement après vérification du planificateur externe.
Cette procédure ne donne pas l’autorisation d’interrompre les autres tâches WP.

Vérification initiale puis après fermeture des envois :

```sh
"$WP_CLI" --path="$WP_ROOT" --url=https://fans.faluss.me cron event list --hook=faluss_fans_messages_retention --fields=hook,next_run_gmt,recurrence
"$WP_CLI" --path="$WP_ROOT" --url=https://fans.faluss.me cron event run faluss_fans_messages_retention
"$WP_CLI" --path="$WP_ROOT" --url=https://fans.faluss.me --user="$MODERATOR_ID" faluss fans-messaging status --require-healthy
```

Contrôler un nouveau `purge.checked_at` après le déclenchement, puis à nouveau après
un cycle horaire **réel du planificateur**, sans intervention manuelle. Attendre
`error:""`, `more:false`, `retention_healthy:true`. Un `wp cron event run` réussi
seul ne détecte pas une erreur interne SQL : le diagnostic est indispensable.
Ajouter une vérification indépendante toutes les heures de `status --require-healthy`
(code de sortie non nul → alerte à l’exploitant) ; la dernière purge doit dater de
moins de deux heures. Cette commande ne purge pas et ne répare pas le cron.

Pour résorber un retard, une commande contrôlée avec sortie fiable existe :

```sh
"$WP_CLI" --path="$WP_ROOT" --url=https://fans.faluss.me --user="$MODERATOR_ID" faluss fans-messaging purge
```

Chaque passage est borné à dix lots de cent conversations et cent dossiers. Une
erreur SQL, un diagnostic non enregistré ou `more:true` donne un code non nul.
`more:true` peut aussi signaler un dernier lot exactement plein : relancer une
fois puis vérifier. Pour un grand retard, répéter sous supervision jusqu’à
`more:false`, sans boucle infinie ni ignorer les erreurs. Ne pas traiter tous les
codes non nuls comme un simple retard : une erreur SQL exige un diagnostic.

Ordinaire : douze **mois calendaires UTC** après le dernier message, sans
prolongation par lecture/rejeu/modération. Preuve : douze mois après décision
définitive explicite, recours compris, sauf conservation de litige motivée
encore active. Les dossiers ouverts ne sont pas automatiquement clôturés ;
réexaminer les échéances dans Faluss → Modération Fans → Signalements privés.
Les blocages persistent après purge des conversations. Aucune purge des
sauvegardes n’est assurée par ces commandes.

## 5. Attestation humaine, ouverture et recette de deux comptes SSO

Seulement après validation des étapes 1–4, l’exploitant renseigne dans la
configuration hors Git : `FALUSS_FANS_MESSAGING_POLICY_ATTESTED=true`, puis
`FALUSS_PLATFORM_FANS_MESSAGING=true`. Les constantes booléennes doivent être
exactes, pas des chaînes `"true"`. Ne jamais utiliser `prepare` comme attestation.
Relancer `status` : `private_access:true`, `admission_open:true`, santé verte.

Deux personnes/comptes Faluss Identity distincts, deux navigateurs ou profils
isolés : A agit en Fan lié ; B possède un profil Créateur actif, avec présentation
éditoriale approuvée séparément. Le modérateur M est un troisième compte
administrateur désigné, non partie aux échanges.

1. Connexion A et B via le vrai formulaire Fans → Identity Me → callback Fans.
   Vérifier session locale, retour vers la page d’origine et absence de deuxième
   passwordless. Ne partager ni cookie, nonce, code ou secret dans les preuves.
2. A : Explorer → profil B → demande de texte ≤ 1 000 caractères. Aucun média.
   B voit la demande en attente ; A ne peut pas envoyer un second message ; B
   accepte. Les deux peuvent ensuite échanger un texte ≤ 2 000 caractères.
3. Vérifier invité, compte non lié, autre compte lié : pas de lecture/envoi dans
   ce fil. Nonce absent/invalide refusé ; réponse privée `no-store`.
4. Tester refus et blocage avec une nouvelle paire de recette si nécessaire :
   un refus est terminal jusqu’à expiration du fil ; ne pas l’effacer en SQL pour
   réutiliser la paire. Débloquer n’accepte ni ne rouvre une demande refusée.
5. Tester les limites : 2 demandes/h, 10/j, 5 en attente par Fan, 100 par Créateur ;
   30 messages/h et 200/j par expéditeur (demande comprise). Rejeu d’une intention
   identique compté une seule fois ; changement de contenu avec même clé refusé.
   Utiliser un environnement de recette autorisé pour quotas et expiration ;
   **ne pas antidater ni injecter des messages sur la production**.
6. A signale un message reçu de B. M voit uniquement la copie nécessaire au
   dossier ; administrateur non habilité refusé, participants sans copie/journal
   interne. M motive une décision provisoire ; A/B voient l’état et peuvent
   déposer leur recours. Réexamen, notification et traitement selon les décisions
   humaines définies. Une révision obsolète/concurrente est refusée (409).
7. Finaliser uniquement après notifications et recours réellement traités ;
   cocher l’attestation explicite et motiver. Aucun délai ne finalise à votre place.
   Tester séparément conservation de litige motivée, renouvellement et levée.
8. Contrôler ordinateur/mobile : demande, attente, échange, blocage, signalement,
   recours, panel modérateur, fermeture. Relever version/SHA, rôles, statuts HTTP,
   résultats du cron et captures expurgées. Ne publier aucun contenu réel privé.

## 6. Fermer les nouveaux envois sans abandonner les obligations

L’exploitant remet **uniquement** `FALUSS_PLATFORM_FANS_MESSAGING=false`.
Conserver l’attestation déjà valablement décidée, SSO/profils/UI et leurs schémas,
le plugin actif et le planificateur. Ne pas retirer l’attestation pour fermer
les envois : cela ferme aussi les accès privés des membres aux signalements/recours.

Vérifier `admission_open:false`, `private_access:true` et la santé de rétention.
Nouvelle demande et envoi refusés (503) ; historique non expiré lisible,
signalements, recours et blocage/déblocage encore accessibles, modération et
purges maintenues. L’acceptation d’une demande existante reste une décision d’état,
elle ne permet aucun nouvel envoi tant que l’admission est fermée.

Le cron et les opérations de modération survivent techniquement à la fermeture
d’admission, indépendamment des sessions. Ne pas désactiver/supprimer le plugin,
ses tables, SSO ou les callbacks de purge sans remplacement vérifié des obligations.
Une impossibilité de conserver le canal des recours exige une procédure de
continuité décidée et notifiée, pas un abandon silencieux des dossiers.

## Preuves et limites de l’audit

Le moteur ordinaire, modération, recours, rétention et fermeture sont livrés ;
l’outil de préparation ajouté est testé sur WordPress/MariaDB jetables avec vrais
cookies/nonces et API. Les liaisons SSO y sont des fixtures locales : aucun échange
réseau avec Me, aucun planificateur ou sauvegarde de Fans n’est attesté. La
validation des personnes, notifications/recours et recette cible reste humaine.

[Résultats détaillés et captures isolées](../evidence/fans-messaging-operations/README.md).
