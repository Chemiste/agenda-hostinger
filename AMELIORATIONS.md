# Améliorations — lisibilité pour Michel et Christiane

Liste issue d'un parcours du site en se mettant dans la peau d'une personne
âgée, peu habituée à l'informatique et n'ayant plus une très bonne vue.
Objectif : ils doivent pouvoir **consulter et imprimer** sans aide, et ce
qu'ils voient ou impriment doit être clair. (La saisie des données reste
faite par Laurent.)

On traite **un point à la fois** : modification → test → commit → point
suivant.

| # | Point | État |
|---|-------|------|
| 1 | Tailles de texte trop petites à l'impression | ✅ à tester |
| 2 | Nom de la personne = plus petit texte de la carte imprimée | ✅ à tester |
| 4 | Date abrégée sur la carte ("mar. 18") | ✅ à tester |
| 3 | Textes coupés par « … » (médecin, adresse, route) | ⬜ à faire |
| 5 | Pas de date d'impression ni de numéro de page sur la feuille | ⬜ à faire |
| 6 | Médicaments et Pathologies cachés dans le menu du prénom | ⬜ à faire |
| 7 | « Normal » / « Compact » ne veulent rien dire | ✅ à tester |
| 8 | Beaucoup de commandes avant d'arriver aux rendez-vous | ⬜ à faire |
| 9 | « Déconnexion » trop facile à cliquer par erreur | ⬜ à faire |
| 10 | Le bouton « Imprimer » ouvre un menu, il n'imprime pas | ✅ à tester |
| 11 | Page Pathologies : rien n'annonce la fiche à emporter | ⬜ à faire |
| 12 | Libellés « Cause » / « Suivi » télégraphiques | ⬜ à faire |

---

## 1 + 2 + 4 — Lisibilité de la feuille imprimée ✅ à tester

**Ce qui a changé**

Impression normale :
- Corps de texte 10,5 pt → **12 pt**, titres de mois 10 → **12 pt**
- Nom de la personne 8,5 pt → **13 pt, en majuscules** (c'était le plus
  petit texte de la carte alors que c'est l'info n° 1)
- Heure 10,5 → **12,5 pt en gras**, médecin 10,5 → **12,5 pt en gras**
- Département 10 → **11,5 pt** ; adresse / téléphone / route /
  accompagnant / notes 9 → **11 pt** ; pathologie 8 → **10,5 pt**
- Questions à poser : titre 8 → **10 pt**, liste 9 → **11 pt**

Impression compacte :
- **2 colonnes au lieu de 3** (sinon les cartes deviennent trop étroites
  avec des caractères plus grands)
- Nom 8,5 → **11 pt en majuscules**, jour 15 → **18 pt**, mois 7,5 →
  **10 pt**, année 6,5 → **9 pt**, heure 7,5 → **11 pt**
- Médecin 9 → **11,5 pt en gras**, département 8,5 → **10,5 pt**, adresse
  / route / accompagnant / pathologie / notes 7,5 → **10 pt**
- L'adresse n'est plus tronquée à 2 lignes avec « … »

Écran + impression :
- Date écrite en entier : « mar. 18 » → **« mardi 18 août »** (le mois
  n'était que dans le titre de groupe ; à l'impression un saut de page
  pouvait séparer les deux)
- Heure écrite **« 14h05 »** au lieu de « 14:05 »

**Comment tester**
1. Accueil → Imprimer → **Normal** : aperçu avant impression. Vérifier que
   le nom (MICHEL / CHRISTIANE) saute aux yeux, que tout se lit à bout de
   bras, et regarder combien de pages ça fait maintenant.
2. Accueil → Imprimer → **Compact** : même vérification, 2 colonnes.
3. À l'écran : les cartes affichent « mardi 18 août · 14h05 ».

**Si c'est trop gros ou trop de pages** : dis-le, on ajuste (les tailles
sont toutes dans `assets/style.css`, bloc `@media print`).

## 7 + 10 — Un seul mode d'impression, avec séparateurs de mois ✅ à tester

**Ce qui a changé**
- Le mode « Normal » est supprimé. L'ancien « Compact » devient le seul
  format d'impression, et le menu déroulant disparaît : le bouton
  **« Imprimer » imprime directement**.
- Un **titre de mois** en pleine largeur est inséré avant le premier
  rendez-vous de chaque mois (« AOÛT 2026 », « SEPTEMBRE 2026»...), avec
  un filet en dessous. Il ne peut pas se retrouver seul en bas d'une page.
- Le CSS de l'ancienne impression détaillée a été retiré (code mort).

**Comment tester**
1. Accueil → **Imprimer** : la boîte d'impression doit s'ouvrir
   directement, sans menu intermédiaire.
2. Vérifier les titres de mois entre les groupes de cartes.
3. Vérifier sur téléphone Android aussi (le délai de 300 ms qui corrigeait
   l'ancien bug est conservé).

**Commit suggéré**
```
Improve printed agenda for elderly readers

Bump print font sizes and make the person's name the most prominent
element on each card. Spell out dates in full ("mardi 18 août · 14h05")
so a page break never hides which month an appointment belongs to.

Drop the Normal/Compact print menu: the card grid is now the only
printed format and the Imprimer button prints straight away. Add a
full-width month heading before each new month in the printed grid.
```
