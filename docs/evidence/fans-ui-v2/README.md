# Captures Fans UI V2 — recette locale du 28 septembre 2026

Les captures `real-wp-*` proviennent de WordPress 7.1.2, MariaDB 11.8.6 et
Chrome local, avec un profil actif et des comptes de test synthétiques. Seuls
les flags SSO, profils et UI de cette instance jetable ont été activés. Les
flags de production restent fermés. Les vues ont été inspectées visuellement
après la recette automatisée ; cette inspection ne remplace pas la validation
visuelle sur `fans.faluss.me` et les vrais parcours SSO, obligatoires avant
l’activation du flag UI en production. Le [compte rendu de revue](REVIEW-2026-09-28.md)
précise la configuration locale, les écarts et l’isolation des tests.

| Parcours réel | Ordinateur 1440 × 900 | Mobile 390 × 844 |
| --- | --- | --- |
| Invité : Explorer | [Capture](real-wp-explorer-guest-desktop.png) | [Capture](real-wp-explorer-guest-mobile.png) |
| Invité : HoF public, moteur indisponible | [Capture](real-wp-hof-guest-desktop.png) | [Capture](real-wp-hof-guest-mobile.png) |
| Invité : profil public actif | [Capture](real-wp-profile-guest-desktop.png) | [Capture](real-wp-profile-guest-mobile.png) |
| Créateur lié : Explorer, sans barre WordPress | [Capture](real-wp-explorer-creator-desktop.png) | — |
| Créateur lié : Créer, quatre choix fermés et sans barre WordPress | — | [Capture](real-wp-creer-creator-mobile.png) |
| Fan lié : HoF indisponible et navigation personnelle | [Capture](real-wp-hof-fan-desktop.png) | — |
| Administrateur non lié : HoF public et outils WordPress conservés | [Capture](real-wp-hof-admin-desktop.png) | [Capture](real-wp-hof-admin-mobile.png) |

La recette réelle a aussi vérifié le compte WordPress non lié, le Fan lié,
les permissions des espaces personnels, les assets et le statut HTTP **de la
page** ainsi que du REST pour les profils suspendu, retiré et inexistant.
La lecture HoF répond 200 pour l’invité, tandis que la session et les
classements lui répondent 403. Sur les pages Fans, la barre WordPress est
absente pour les membres ordinaires et présente pour l’administrateur ;
elle reste disponible sur les autres pages selon la préférence du membre.
Les UUID servent aux liens et ne sont pas visibles. Aucun nom public ou
portrait n’étant fourni par l’API, les fiches disent explicitement que la
découverte reste provisoire.

Les captures sans préfixe `real-wp-` sont issues du routeur synthétique qui
simule l’API pour les cas vides et en erreur, le focus clavier et la navigation
Créateur. Elles ne prouvent pas WordPress réel. Aucun contenu de la planche,
session, point, vente ou personne fictive n’est mis en ligne.
