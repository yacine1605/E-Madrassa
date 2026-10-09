# Coefficients et calcul des moyennes — vérification du 4 octobre 2026

## Résultat

Une copie scannée de la correspondance MEN n° 470 du 19 septembre 2026 a été téléchargée et ses deux pages examinées visuellement. Elle contient une grille de coefficients du moyen pour 2026–2027, adressée aussi aux établissements privés. Elle a été obtenue depuis une republication, sans copie identique retrouvée sur le site du Ministère : sa provenance institutionnelle n’est donc pas authentifiée indépendamment. Les valeurs ci-dessous sont une transcription à confronter à l’exemplaire reçu par l’établissement avant activation.

Source du document : [copie scannée, deux pages](https://drive.google.com/file/d/1cCJ5kWkFapVa7-GF2dps8OWOd2b5N0xT/view). Découverte via [la republication](https://etawdjih.blogspot.com/2026/09/middle-school-hours-coefficients-2026-2027.html). La transcription provient de l’image du document, pas du tableau HTML de cette republication. Une copie locale de travail est conservée dans `.test-tools/men-middle-coefficients-2026.pdf`.

## Coefficients transcrits pour le moyen

| Matière | 1AM | 2AM | 3AM | 4AM |
|---|---:|---:|---:|---:|
| Arabe | 2 | 3 | 3 | 5 |
| Tamazight | 2 | 2 | 2 | 2 |
| Français | 1 | 2 | 2 | 3 |
| Anglais | 1 | 2 | 2 | 3 |
| Mathématiques | 2 | 3 | 3 | 4 |
| Sciences naturelles | 1 | 2 | 2 | 2 |
| Sciences physiques et technologie | 1 | 2 | 2 | 2 |
| Histoire-géographie | 2 | 2 | 2 | 3 |
| Éducation islamique | 1 | 1 | 1 | 2 |
| Éducation civique | 1 | 1 | 1 | 1 |
| Arts plastiques **ou** musique | 1 | 1 | 1 | 1 |
| EPS | 1 | 1 | 1 | 1 |
| Informatique | 1 | 1 | 1 | 1 |

L’annexe regroupe le coefficient de l’histoire et de la géographie ; en 4AM, elle indique `3 (2+1)`. L’application possède déjà une matière combinée `MOY-HG`. Ne pas créer un deuxième coefficient pour la géographie sans vérifier le mode de saisie attendu.

La ligne artistique est une alternative. Le programme actuel contient `MOY-ART` et `MOY-MUSIC` simultanément : il faut distinguer les activités proposées par l’école de la ligne comptée dans la moyenne. Même principe pour Tamazight et l’informatique : leur présence dans le tableau ne suffit pas à déterminer leur applicabilité à chaque élève/classe. L’annexe écrit les totaux sous la forme `14+(1)+(2)`, `20+(1)+(2)`, `20+(1)+(2)` et `27+(1)+(2)` ; ne pas fixer un dénominateur global sans traiter ces conditions.

## Calcul actuellement effectué par l’application

Le calculateur normalise chaque note au barème du bulletin, puis calcule la moyenne de chaque composante avec les coefficients des évaluations.

`Matière = (Continu × poidsContinu + Devoirs × poidsDevoirs + Composition × poidsComposition) / somme des poids actifs`

`Moyenne générale = somme(Moyenne matière × coefficient matière) / somme des coefficients des matières chiffrées non dispensées`

Une note manquante ou une absence non traitée bloque le résultat ; un zéro reste une note. Les matières qualitatives et entièrement dispensées sont exclues de la moyenne générale. Les poids sont ceux enregistrés pour le trimestre, pas une règle nationale codée en dur.

**Exemple de fonctionnement, sans validation nationale** : avec des poids `1,1,2`, un continu de 14, un devoir de 16 et une composition de 18 donnent `(14+16+2×18)/4 = 16,50`. Cet exemple explique la distinction entre les poids internes à une matière et son coefficient dans la moyenne générale ; il ne certifie pas la formule applicable en 2026–2027.

La correspondance n° 470 traite des horaires et coefficients, sans formule de calcul des composantes. Elle ne permet donc pas de prévalider les poids du continu/devoir/composition. Les exceptions possibles par matière, les notes pratiques/orales et les règles d’absence doivent être vérifiées dans le texte d’évaluation applicable.

## Primaire et secondaire

- **Primaire** : aucune grille complète de notation 2026–2027 ni règle de prise en compte des compléments privés n’a été validée dans cette recherche. Les horaires hebdomadaires fournis par l’école ne sont pas des coefficients de notation.
- **Secondaire** : le site officiel publie la décision n° 36 du 9 septembre 2026 annulant la décision n° 20 du 28 juillet sur les horaires et coefficients. Il faut retrouver le texte applicable après cette annulation ; elle ne suffit pas, à elle seule, à prouver que toutes les anciennes valeurs sont rétablies. [Publication du Ministère](https://www.education.gov.dz/2026/09/10/قرار-رقم-36-مؤرخ-في-27-ربيع-الأول-عام-1448-الموا/).
- Une [publication officielle sur l’évaluation 2021–2022](https://www.education.gov.dz/old/ترتيبات-التقويم-البيداغوجي-للسنة-الد/) a été retrouvée, mais les images attachées n’ont pas pu être consultées. Sa date ne permet pas de certifier son applicabilité pour 2026–2027.

## Conséquence pour l’application

La lacune relevée dans l’audit concerne la validation des profils de notation, pas l’absence d’un moteur de calcul. À la demande de l’utilisateur, la grille du moyen a ensuite été intégrée comme suggestion pour les nouveaux trimestres 2026–2027 : coefficients préremplis, barème /20 et référence du document. Les règles enregistrées et les coefficients du catalogue restent inchangés ; aucun poids de calcul n’est imposé. Voir [le fonctionnement du formulaire](bulletins.md).

Pour rendre une grille utilisable : vérifier le document reçu par l’école, sélectionner les matières réellement comptées, définir les composantes et exceptions selon le texte d’évaluation, puis enregistrer les règles avec leur référence pour chaque niveau/année/trimestre. Les règles du trimestre sont déjà conservées en JSON avec une référence ; les nouvelles valeurs ne doivent pas modifier les trimestres précédemment configurés.
