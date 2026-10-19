# Messages automatiques Créateur → Fan

## Règles produit approuvées le 17 octobre 2026

- Deux configurations indépendantes : Bienvenue (texte, contenu privé facultatif
  quand sa diffusion est sûre) et Remerciement (texte uniquement, pièce jointe
  interdite aussi côté serveur). Même politique de texte, modération, blocages et
  limites que les messages Fans existants, sans transport parallèle.
- Bienvenue : transition serveur confirmée ne suit pas → suit, une seule fois
  par couple Fan/Créateur, y compris après désabonnement/réabonnement. Aucun envoi
  rétroactif aux personnes déjà suivies.
- Remerciement : attribution achetée attestée et confirmée augmentant réellement
  le score du Créateur, une seule fois toutes projections confondues. Pack seul,
  PC, bonus, simulation, rejeu, correction ou restauration après litige : aucun
  message. Les capacités économiques restent fermées aux instances jetables.
- Le suivi ou l'attribution admissible autorise le seul message automatique
  correspondant si le Créateur l'a activé, même sans conversation acceptée.
  Aucun formulaire, case à cocher ou accord Fan supplémentaire ; la correction
  produit remplace expressément les options de consentement proposées auparavant.
- Ces événements ne valent pas acceptation générale des échanges ou d'autres
  sollicitations ; une demande en mode restreint reste soumise à sa décision.
  Blocage, suspension, refus de contact, admission fermée et quotas restent
  opposables avant la livraison. Le mode de contact du Créateur régit les
  réponses et nouveaux échanges. Pas d'ouverture générale implicite.

## Périmètre du premier lot automatique

Les modes de contact sont intégrés par #235. Ce lot ajoute les configurations
indépendantes, l'aperçu serveur, la bienvenue textuelle réellement raccordée au
suivi et les formulaires gratuits « Suivre / Ne plus suivre ». Aucun opt-in Fan
n'est lu, enregistré ou demandé ; l'événement de suivi fournit l'autorisation
limitée au message correspondant. Aucune acceptation générale n'en découle.

Le raccordement aux faits PF confirmés et rapprochés est intégré par #239,
**uniquement sur les instances Hub/Fans physiquement jetables**. La configuration
peut y être activée lorsqu'une source typée fermée est réellement disponible.
Sur un site ordinaire, l'activation échoue toujours fermée côté serveur et
l'option reste indisponible. Une ligne de file fabriquée, un champ REST, un flag
ou un rôle ne constituent jamais une preuve PF. Aucun producteur d'achat réel
ni API économique de site ne sont ajoutés ; [contrôle source et reprise](FANS-AUTOMATIC-PF-THANKS-CLOSED.md).

### Persistance et livraison de la bienvenue

- Trois tables InnoDB additives, préparées explicitement par l'opération existante
  `wp faluss fans-messaging prepare` : `automatic_settings`, `automatic_events`,
  `automatic_contacts`. Aucune DDL au démarrage ou sur GET.
- Réglages propriétaires avec révision attendue ; édition périmée en409. Le GET
  ne crée rien ; aperçu sans écriture ; corps textuel conforme aux limites Fans.
- L'insertion effective du suivi et son événement sont dans la même transaction
  sous le mutex de messagerie. Un GET, un clic ou un rejeu de suivi ne déclenche
  rien. Retirer un suivi existant antérieur au lot enregistre une trace sans envoi,
  pour qu'un réabonnement ne transforme pas cet ancien suivi en bienvenue.
- Clé métier dérivée du couple Créateur/identité opaque, indépendante du numéro
  WordPress local. Un événement désactivé reste consommé pour la bienvenue : pas
  de rattrapage après activation. Aucun historique de suivi effacé avant ce lot
  n'est reconstitué ou présenté comme attesté.
- Révision et texte figés au déclenchement. Modifier le texte ne réécrit pas la
  file ; désactiver annule les éléments encore en attente, sans les ressusciter
  lors de la réactivation. Désabonnement avant remise annule aussi la bienvenue.
- Traitement borné à20 décisions par exécution, quota ordinaire Créateur partagé,
  reprise progressive60 à3600 secondes pour429. Message, notification et résultat
  durable s'écrivent dans une transaction unique. Après réponse COMMIT perdue,
  relire le même événement sur le primaire ; aucune nouvelle clé, aucun doublon.
- Vérifications à la remise : liaison actuelle, Créateur actif, refus, blocages
  dans les deux sens, suspension/modération, admission, quotas et configuration.
  Aucun texte n'est délivré à partir d'une session navigateur expirée fabriquée.

### Conversation automatique et droits ordinaires

La remise utilise les tables, notifications, signalements et purges ordinaires.
Sans fil préalable, l'état `automatic` rend seulement ce texte lisible. Ni Fan ni
Créateur ne peut y envoyer librement. Le Fan peut commencer explicitement un
premier échange sur le **même fil** : Ouvert l'ouvre, Sur demande crée une demande
qui nécessite toujours acceptation. Le message automatique ne consomme pas le
quota de nouvelles demandes du Fan ; sa première demande effective est datée
séparément sans falsifier la date de création du fil. Les refus et blocages
existants ne sont jamais acceptés ou levés par une automatisation.

Le texte livré suit la rétention ordinaire, douze mois après le dernier message.
Les instantanés en attente non remis expirent au plus tard douze mois après leur
événement. À l'issue, texte, identité destinataire et clé de remise sont effacés
de la file ; les références de fil/message sont effacées par la purge ordinaire.
Une empreinte métier minimale et le résultat restent nécessaires à l'unicité à
vie. Cette trace est une donnée pseudonyme, pas une preuve d'effacement physique
des sauvegardes ni une politique de conservation des faits PF de #150.

### Exploitation explicite, sans activation

La préparation ajoute l'événement WP-Cron `faluss_fans_message_automations`
(récurrence60 secondes) ; elle n'atteste pas la politique et n'ouvre aucun flag.
`wp faluss fans-messaging status` expose séparément le schéma et la programmation.
L'exploitant doit faire tourner les événements dus avec un déclenchement fiable,
vérifier la dernière exécution et les erreurs dans l'option privée
`faluss_fans_message_automation_status` (date UTC, nombre traité, code générique ;
aucun texte/identité). La purge horaire existante et ses verrous sont inchangés.
Ne pas compter sur une visite du site pour garantir la livraison.

Fermer l'admission interdit les nouvelles remises ; les messages déjà conservés,
signalements, recours et purges restent accessibles. Désactiver Bienvenue avant
un retrait de code, laisser terminer le job puis conserver les données : ne pas
supprimer les empreintes pour simuler une première bienvenue après rollback.

Le raccordement fermé du remerciement réutilise les faits Hub autoritatifs et la
file existante. Aucun réseau dans une transaction économique, aucune annulation
d'un soutien si la messagerie est indisponible et aucun nouveau contrat Hub
sans accord distinct.

La pièce jointe de bienvenue demeure **indisponible** tant que #161 ne prouve pas
la compatibilité du stockage privé, l'intégrité, la lecture autorisée et la
révocation de tous les objets/variantes. Pas de lien permanent ou original public,
pas d'élargissement des types de fichiers. Remerciement restera textuel.

## Scénarios requis avant livraison du lot complet

Les recettes doivent distinguer les deux configurations et leurs événements,
suivi refusé/rejoué/concurrent/réabonnement, anciens followers, plusieurs
attributions et projections, corrections partielles/totales, litiges/résolutions,
blocages des deux côtés, suspension, quotas, panne/COMMIT incertain, désactivation
avant livraison, texte figé malgré édition, permissions et contenus retirés.
Vérifier les formulaires natifs et API avec des comptes liés distincts, ordinateur
et mobile, sans contenu privé dans les rapports. Aucun site, flag ou secret réel.
