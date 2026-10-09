# Bulletins scolaires

Le module **Pédagogie → Bulletins scolaires** utilise les classes, inscriptions validées, matières et notes du backend. Il imprime un élève ou un lot de 50 élèves en A4, avec libellés arabes/français. Le bouton d’impression permet aussi l’enregistrement en PDF.

## Paramètres de l’établissement

Chaque niveau et année scolaire reçoit jusqu’à trois trimestres, avec dates sans chevauchement, barème /10 ou /20, référence de la règle adoptée, coefficients des matières et poids des composantes `continu`, `devoir`, `examen`. Les paramètres sont enregistrés une fois par trimestre et partagés par les classes du niveau. Ils ne recopient pas les coefficients du programme, dont certaines valeurs initiales sont des valeurs par défaut.

Les nouveaux trimestres du **moyen 2026–2027** préremplissent les coefficients de 1AM à 4AM et le barème /20 à partir de la copie scannée de la correspondance MEN n° 470 du 19 septembre 2026. La référence et le lien de la copie sont affichés ; les valeurs restent modifiables. La provenance institutionnelle de cette copie reste à confirmer. Voir [les sources et la transcription](grading-rules-2026-2027.md).

Cette suggestion s’applique uniquement au programme scolaire `MOYEN`, aux niveaux `1`–`4` ou `1AM`–`4AM`, et à une année commençant en 2026 et finissant en 2027. Les autres cycles, années et matières non reconnues restent en saisie manuelle. Les coefficients du catalogue et les règles déjà enregistrées ne sont pas modifiés.

La grille comporte **arts plastiques ou musique** : lorsque les deux matières sont présentes, la musique est proposée hors moyenne et les arts plastiques dans la moyenne. Le formulaire permet d’inverser ce choix ; le serveur refuse de compter les deux simultanément pour ce profil. Il est aussi possible d’exclure les deux si aucune n’est évaluée. Tamazight, l’informatique et les autres matières peuvent être placées hors moyenne selon leur applicabilité. Aucun dénominateur fixe n’est imposé.

**Les poids des composantes ne sont pas prévalidés.** La correspondance n° 470 ne donne pas de formule continu/devoir/composition. Renseigner ces poids et compléter la référence de la règle adoptée reste nécessaire. La page officielle des [documents pédagogiques du primaire et du moyen](https://www.education.gov.dz/2017/11/15/الوثائق-الرسمية-البيداغوجية-لمرحلتي/) est une référence documentaire, pas une confirmation de tous les paramètres pour 2026–2027.

Pour chaque composante, les notes publiées sont normalisées au barème choisi et pondérées par le coefficient de l’évaluation. La moyenne de matière utilise les poids configurés. La moyenne générale pondère les moyennes de matière par les coefficients du trimestre. Les calculs utilisent les valeurs non arrondies ; l’affichage arrondit à deux décimales.

Une composante de poids zéro n’entre pas dans le calcul. Une matière qualitative affiche l’observation enregistrée et n’entre pas dans la moyenne. Une note manquante, une absence ou une évaluation publiée sans type empêche de calculer la moyenne de matière. Un zéro reste une note. Une matière entièrement dispensée est exclue de la moyenne générale ; une dispense partielle demande un traitement explicite.

## Validation, clôture et archives

Un lot suit le parcours **brouillon → validé → clôturé**. Les actions portent sur toute la classe, même lorsqu’un seul élève ou une page de 50 élèves est affiché. La validation refuse un lot vide, des notes numériques incomplètes ou une observation qualitative manquante. Elle enregistre les résultats, identités, matières, règles et données de calcul présentés dans l’aperçu. Un aperçu devenu obsolète doit être rechargé.

Après validation, les observations sont verrouillées. Le lot peut revenir au brouillon pour correction tant qu’il n’est pas clôturé. Si les données sources changent entre validation et clôture, la clôture est refusée et une nouvelle validation est nécessaire.

La clôture conserve définitivement la copie validée, son empreinte SHA-256, le document HTML rendu et sa feuille de style. Les changements ultérieurs de notes, d’identité, d’inscription, de programme ou de modèle ne modifient pas cette archive. La base interdit la modification et la suppression d’une archive clôturée. Les acteurs et dates sont enregistrés dans le journal d’audit. L’archive reste consultable et imprimable en PDF, avec téléchargement HTML ; un lot de plus de 50 élèves conserve toutes ses pages.

Une archive clôturée ne peut pas être rouverte. La réédition corrective avec nouvelle version n’est pas implémentée. Les signatures/cachets et observations du conseil de classe restent des zones à compléter à l’impression ; la validation dans l’application ne constitue pas une signature électronique.

## Bulletin et moyenne annuels

Le bulletin annuel devient disponible lorsque les trois trimestres de la classe sont clôturés avec le même barème. Chaque trimestre contribue à parts égales : **moyenne annuelle = (T1 + T2 + T3) / 3**. Le calcul utilise les moyennes non arrondies des archives trimestrielles ; seul l’affichage est arrondi à deux décimales. La moyenne par matière est affichée lorsqu’elle dispose de trois moyennes numériques.

Les élèves présents dans les archives trimestrielles sont conservés, même si leur inscription a changé depuis. L’absence d’une moyenne générale chiffrée dans un trimestre empêche la validation annuelle pour cet élève. Le bulletin annuel suit le même parcours de validation et clôture et conserve les références des trois archives sources. Aucun classement ni décision automatique de passage n’est ajouté.

## Accès et vérification

La lecture exige `pedagogy.read`, et l’impression nominative exige aussi `students.read`. La configuration et les observations exigent `pedagogy.write`. La validation, le retour au brouillon et la clôture exigent les trois permissions. Les routes utilisent le contexte de l’école fixe, une transaction PostgreSQL et RLS ; le rendu est privé et non mis en cache. Les actions concurrentes et répétées sont contrôlées par verrou transactionnel et empreinte de version. Le portail enseignant permet désormais le type **Contrôle continu** ; il ne donne pas accès aux bulletins globaux.

Migrations : `2026_10_04_000001_create_report_card_terms.php` et `2026_10_07_000001_create_report_card_archives.php`. Construire l’image puis recréer le service `app` applique les migrations via le point d’entrée existant.

Tests : `node tests/report-cards.cjs` crée une installation temporaire et vérifie les trois cycles, les moyennes, les observations, les permissions et les exports PDF. `node tests/report-card-archives.cjs` vérifie le parcours définitif, les versions obsolètes, les permissions, l’immuabilité en base, les archives de 52 élèves et la moyenne annuelle non arrondie, ainsi que les exports HTML/PDF et l’affichage mobile. `tests/report-card-calculator.php` vérifie les cas de notes manquantes, zéro, absence, dispenses et pondération. Les PDF d’exemple utilisent des données fictives et des règles de test.
