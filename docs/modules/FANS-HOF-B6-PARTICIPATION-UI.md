# B6 — formulaires de participation et recours privés

Raccordement en cours de recette, non publié. Les règles produit F1b R2 et les
contrats Hub existants sont conservés. Aucun contrat réseau, source d'achat,
pair réel ou mécanisme de consommation n'est ajouté.

## Parcours et données

- La session publique autorisée propose au Créateur lié une entrée
  `creator/hof?manage=join&session=…`. `ClosedParticipationEntry` vérifie la
  disponibilité publique actuelle, puis relit les règles locales approuvées et
  figées. L'inspection privée n'est pas élargie aux Créateurs sans rôle.
- La case de consentement explicite et l'empreinte des règles sont contrôlées
  côté serveur. Après demande, une redirection303 mène au suivi privé existant.
  Les décisions, recours, retraits et reprises réussis redirigent aussi vers une
  lecture GET, conservant le curseur de gestion ; revenir en arrière ne rejoue
  pas une écriture. L’état en attente vient du checkpoint durable, pas de l’URL.
  La coorganisation n'inscrit jamais automatiquement le Créateur comme participant.
- L'organisateur accepté peut admettre ou refuser les demandes. L'admission
  utilise le [checkpoint atomique](FANS-HOF-B6-PARTICIPATION-BRIDGE.md), puis sa
  reprise existante. Une décision locale acceptée et une confirmation Hub sont
  affichées séparément. Aucun point, rang ou attribution n'est fabriqué.
- Le retrait ferme immédiatement les futurs choix. Une réponse réseau incertaine
  reste en cours ; le membre peut reprendre la confirmation, même si le profil
  ne bénéficie plus de diffusion publique. Une demande non admise est retirée
  sans créer un faux enregistrement Hub.
- Les noms viennent exclusivement des présentations approuvées. Une présentation
  indisponible a un libellé neutre, sans identifiant technique ou identité inventée.

## Recours

Le refus permet au membre de soumettre un recours lié à sa révision de demande.
Le panel Fans existant ajoute la section « Recours de participation », réservée
aux habilitations d'administration existantes et à la garde physique fermée.
Les décisions, motifs et clôtures sont ceux de `SessionAppeals`, sans nouvelle
règle commerciale. Le recours favorable passe par `admitAppeal()` pour lier
dossier, journal, admission et checkpoint dans une transaction.

Un dossier décidé ne se réutilise pas pour une demande ultérieure. La reprise
depuis le panel vérifie encore la filiation de la révision de participation ;
une ancienne décision ne choisit pas implicitement un nouveau contexte.
Les horaires affichent le fuseau configuré dans WordPress ; le stockage reste UTC.

## Permissions et limites de transport

Les formulaires natifs restent utilisables sans JavaScript : nonce WordPress,
révisions exactes, cibles contrôlées par le serveur, demandes ambiguës refusées et
en-têtes privés sans cache partagé. Aucun acteur ou Faluss ID n'est déduit d'un
paramètre de destinataire fourni par le navigateur. Invité, Fan sans profil et
administrateur non lié ne deviennent pas participants en copiant un formulaire.

`faluss_fans_hof_closed_participation` ne peut fournir le transport qu'après
vérification du bail, socket, base primaire et environnement de recette physiques.
Le fournisseur doit utiliser la même connexion. La factory n'installe aucun
schéma, n'appelle pas le réseau sur GET et n'admet aucun site réel par un flag.
Les retours SSO gardent uniquement l'entrée et les curseurs canoniques validés ;
passwordless, HMAC, PKCE, session centrale et cookies restent inchangés.

## Validation et suite

Scénarios requis : entrée publique admissible, consentement, nonce/cible/CAS,
acteurs exclus, demandes dupliquées, décisions/refus/recours, réponse perdue après
COMMIT et lookup primaire, retrait, ancien dossier, pagination et absence de
mutation sur GET. La recette navigateur doit exercer les contrôles natifs au
clavier et au toucher et vérifier six largeurs, les noms accessibles, les
débordements et le retour navigateur. Les preuves et captures seront ajoutées
après exécution ; une compilation ou un écran seul ne vaut pas parcours fonctionnel.

Après suspension puis réadmission de session attestées, « Vérifier la confirmation »
utilise la [requalification privée](FANS-HOF-B6-PARTICIPATION-REQUALIFICATION.md)
pour un rôle admis dont le parent a changé. Elle conserve le consentement,
retire d'abord l'ancien contexte avec sa même action et n'admet que les
contributions futures après preuve primaire. Le GET ne transforme jamais l'ancien
binding en confirmation. Le parcours natif croisé est inclus dans la recette HTTP.

Les territoires et les choix d'intention doivent encore être vérifiés ensemble.
Un ancien binding de version de session ne constitue jamais une confirmation
pour la nouvelle version. Aucun test du véritable SSO Me, site cible, appareil
physique, achat, score public, flag, migration ou publication n'est revendiqué.
Conservation #150 et RustFS #161 restent distincts ; aucune durée ou purge ajoutée.
