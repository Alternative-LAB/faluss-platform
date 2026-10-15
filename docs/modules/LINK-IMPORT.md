# Importer ses liens dans faluss.me

## Pour qui et pourquoi

Les créateurs qui utilisent le Studio V3 peuvent reprendre leurs liens depuis
un export Linktree, un autre service ou leur propre liste. Le créateur fournit
le fichier ou le texte ; Faluss ne consulte aucune plateforme tierce.

## Parcours

1. Ouvrir **Studio → Liens → Importer mes liens**.
2. Dans **Ta source**, choisir **Un fichier** pour sélectionner un CSV ou un JSON,
   ou **Copier-coller** pour saisir du texte, du Markdown ou des liens HTML.
   **Options de détection** permet de préciser le format si nécessaire.
3. Cliquer sur **Prévisualiser mes liens**. L’étape **Tes liens** affiche une liste
   compacte : titre, domaine, sélection et état de chaque lien. Les compteurs
   indiquent les liens prêts, doublons, erreurs et places disponibles.
4. Ouvrir un lien pour modifier son titre, son URL ou son ordre, puis cliquer sur
   **Vérifier les modifications**. Cocher les lignes corrigées à importer et
   décocher celles à exclure. Les erreurs et doublons restent décochés et leur
   case devient disponible après correction et validation.
5. Cliquer sur **Importer … liens**. Le Studio recharge les données enregistrées
   et affiche le nombre de liens ajoutés.

**Changer de source** revient à la première étape sans vider la saisie. Le fichier
choisi et le texte collé gardent chacun leur brouillon pendant la session : passer
d’un onglet à l’autre ne les remplace pas. Une nouvelle source exige une nouvelle
prévisualisation. L’interface n’affiche qu’une action principale à chaque étape. La liste reprend
l’ordre détecté dans la source avec une numérotation à partir de 1.

L’import ajoute les liens hors collection, après les liens libres existants et
avant la première collection. Il conserve les contenus, images, réglages,
visibilités et membres des collections existantes. Il ne propose aucun remplacement.
Les nouveaux liens sont visibles par tout le monde, comme un lien ajouté manuellement.

## Formats et détection

Un CSV peut utiliser la virgule, le point-virgule ou la tabulation, avec ou sans
en-têtes. Les champs reconnus comprennent `title`, `label`, `name`, `titre`,
`url`, `href`, `link`, `lien`, `order`, `position`, `sort_order` et `ordre`.
Les cellules entre guillemets peuvent contenir des séparateurs ou des retours
à la ligne. Un titre absent prend le nom de domaine comme valeur.

Exemple CSV :

```csv
titre;url;ordre
Mon portfolio;https://example.com/portfolio;1
Mes vidéos;https://example.com/videos;2
```

Exemple JSON :

```json
{"links":[{"title":"Mon portfolio","url":"https://example.com/portfolio","position":1}]}
```

Le JSON accepte une liste de liens ou les enveloppes d’export reconnues par le
parseur. Les métadonnées d’images et d’avatars ne deviennent pas des liens.
Dans le texte, Faluss associe une URL à son titre sur la même ligne ou à la ligne
précédente. Les ancres HTML et la syntaxe `[Titre](https://example.com)` sont reconnues.

## Validation et doublons

Chaque titre contient au plus 80 caractères et chaque URL au plus 2 048 octets.
Les titres contenant encore un antislash ou le caractère `<` après nettoyage
sont signalés à corriger pour éviter leur transformation par le stockage historique.
Faluss normalise les URL sans protocole et les URL HTTP en HTTPS. Vérifier ce
changement dans l’aperçu : Faluss ne teste pas la disponibilité de la destination.
Les URL locales, privées, avec identifiants ou avec un protocole dangereux sont refusées.
L’analyse HTML nécessite l’extension PHP DOM ; les autres formats restent disponibles sans elle.

La comparaison ignore la casse du domaine, le port HTTPS par défaut, les variantes
d’encodage des caractères non réservés et les paramètres de suivi `utm_*`,
`fbclid` et `gclid`. Elle distingue les chemins selon leur casse, les fragments,
les sous-domaines dont `www` et les paramètres métier. Elle ne suit aucune redirection.
Les paramètres de suivi restent dans l’URL enregistrée ; seule la comparaison les ignore.
Faluss encode les crochets des chemins, paramètres et fragments avant la comparaison ;
les crochets du domaine IPv6 restent inchangés.

Un doublon ou une erreur reste visible et décoché. Après correction, vérifier
les modifications puis sélectionner la ligne. Le serveur refait ces contrôles
à la confirmation et ne retient que les nouveaux liens valides sélectionnés.

## Limites et incidents

- Un aperçu accepte au plus **512 Ko et 500 liens**. Les fichiers restent dans le
  navigateur ; seul leur contenu sert à l’analyse authentifiée. Faluss ne conserve
  ni fichier source ni brouillon d’import après fermeture de la page.
- La limite existante de la carte reste **32 blocs**, collections et médias inclus.
  Si la sélection dépasse la place disponible, décocher des liens. Le serveur
  refuse le lot entier en cas de dépassement.
- Si une autre session a changé la carte, l’import refuse la version obsolète et
  garde la saisie à l’écran. Copier les corrections utiles, actualiser puis analyser
  à nouveau avant de confirmer.
- En cas d’échec de stockage, le serveur annule le lot entier, y compris l’ordre et
  la projection des liens. Réessayer après résolution du problème.
- Si la réponse d’une confirmation se perd, actualiser avant de recommencer :
  l’enregistrement peut avoir abouti. La version et le dédoublonnage protègent le rejeu.
- Si l’enregistrement réussit mais que la relecture échoue, utiliser **Actualiser
  la rubrique**. Le Studio bloque les nouvelles écritures pendant cette attente.
- Les anciens liens encore en lecture seule nécessitent leur migration explicite
  existante avant d’autoriser les mutations de blocs.

Les contrôles possèdent des libellés, un focus visible et une annonce des résultats.
L’interface reste utilisable au clavier et sur mobile. JavaScript est nécessaire.

## Contrat technique

L’import reste une responsabilité du module Link. `LinkImportParser` analyse les
sources, `LinkImportValidator` normalise et classe les lignes, `LinkImportBatch`
ferme le contrat des lignes éditées. Ces classes n’accèdent ni à WordPress ni au
réseau. Studio possède le rendu et les adaptateurs AJAX ; les écritures passent
par `LinkStudioContract` et la transaction de l’agrégat Link.

L’interface privée reprend les conventions AJAX WordPress du Studio, avec des
réponses `{success,data}` et des codes HTTP d’erreur. Elle n’introduit pas d’API
REST publique. Chaque requête utilise la session et un nonce propre à son action ;
aucun identifiant de propriétaire fourni par le client n’est accepté.

| Action POST sur `admin-ajax.php` | Entrée | Résultat |
| --- | --- | --- |
| `faluss_studio_link_import_preview` | `nonce`, `source`, `format` (`auto/csv/json/text`) ; ou `rows` JSON | `rows` normalisées avec `label,url,order,status,message,selected`, `capacity` |
| `faluss_studio_v3_manage` | nonce existant, `mutation=import_links`, `version`, `fields={"rows":[{"label":"…","url":"…","order":0}]}` | `code=imported`, `imported`, `report` par ligne et état canonique |

Les statuts de ligne sont `ready`, `duplicate` et `invalid`. Les clés d’une ligne
éditée sont fermées ; le client ne peut imposer ni statut, UUID, visibilité,
image, propriétaire ou remplacement. L’ordre est un entier ; les égalités gardent
l’ordre source. La confirmation reçoit uniquement les lignes sélectionnées.
Le rapport décrit ce sous-ensemble ; le nombre `imported` compte les lignes `ready`.

Le serveur refuse une prévisualisation sans session/nonce ou identité disponible
(403), une méthode autre que POST (405), une source trop grande (413), une entrée
invalide (422). La mutation conserve le conflit `stale_version` (409), les refus
`import_empty` / `import_capacity` / `invalid_import` (422), et le verrouillage
des blocs historiques (409). Aucun échec ne renvoie une trace ou une requête SQL.

```mermaid
sequenceDiagram
    actor Createur as Créateur
    participant Studio
    participant Link
    participant Base as Base locale
    Createur->>Studio: Fichier ou texte, puis aperçu
    Studio->>Link: Analyse locale et validation
    Link-->>Studio: Lignes modifiables et erreurs
    Createur->>Studio: Confirme les lignes sélectionnées
    Studio->>Link: import_links + version
    Link->>Base: Transaction et verrous agrégat
    alt Version obsolète ou échec
        Link->>Base: Annulation complète
        Link-->>Studio: Erreur, saisie conservée
    else Version courante et capacité suffisante
        Link->>Base: Ajout des liens et projection Identity
        Link->>Base: Commit
        Link-->>Studio: Rapport et état canonique
    end
```

## Activation et retour arrière

Le panneau suit l’activation existante de Studio V3 sur le rôle `me`, avec Link
et son schéma de composition disponibles. Aucun nouveau flag, schéma, secret,
cron, fournisseur externe ou migration automatique n’est ajouté. Aucun changement
n’est nécessaire sur faluss.com ou Elementor ; la carte partagée rend les liens ajoutés.
Le cache des assets Studio utilise la version `3.3.0`.

Revenir à la version applicative précédente retire le panneau et conserve les
liens déjà créés dans le stockage habituel. Le créateur peut supprimer un lien
importé avec les contrôles existants. Ne pas restaurer une base pour retirer le panneau.
Aucune activation ni écriture de production ne fait partie de ce changement.

## Vérification

Les tests PHP couvrent les formats, les limites, les URL, les doublons et les
frontières d’authentification. Le contrat transactionnel réutilise le runtime Link
et sa base simulée pour vérifier conservation, capacité, conflit, rejeu et rollback.
Les tests navigateur utilisent le rendu PHP réel et un transport AJAX simulé.
Ils ne prouvent pas une recette WordPress/MariaDB/Elementor réelle.

```bash
vendor/bin/phpunit --filter LinkImport
php tests/Link/link-import-behavior-test.php
php tests/MeStudio/link-import-actions-test.php
FALUSS_PHP=php node tests/MeStudio/link-import-browser-test.js
```

Le test navigateur utilise Playwright installé hors du dépôt. Les preuves et
conditions d’exécution figurent dans [la recette](../evidence/link-import/README.md).
Avant déploiement, vérifier sur un WordPress isolé le nonce expiré, deux sessions,
un lot dépassant la capacité, la parité de la carte publique et le rollback applicatif.
