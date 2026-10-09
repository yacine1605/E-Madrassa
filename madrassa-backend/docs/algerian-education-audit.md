# Audit de l’application selon le système éducatif algérien

Date : 4 octobre 2026. Périmètre : code présent dans le workspace, y compris les modifications locales des admissions, du passage annuel et des bulletins. Revue statique du code et consultation de sources publiques ; aucun test fonctionnel ni contrôle de la base déployée n’a été exécuté pour cet audit.

## Conclusion

L’application dispose d’une bonne base de gestion pour une école privée algérienne. Elle n’est pas encore démontrée conforme à toutes les règles pédagogiques et administratives applicables. La priorité est de sécuriser les programmes par année, les paramètres de notation, les bulletins définitifs et les parcours de passage/orientation. Les fonctionnalités proposées ci-dessous sont des besoins produit déduits de la revue ; elles ne constituent pas toutes des obligations réglementaires.

## Ce qui existe déjà

- Niveaux primaire 1AP–5AP et moyen 1AM–4AM ; préscolaire ; deux troncs communs de 1AS.
- Catalogue des matières avec libellés arabes et compléments propres à l’école privée.
- Inscriptions, classes, responsables légaux, affectations enseignants et contrôle des capacités.
- Séances, conflits de planning, présences et portail enseignant avec saisie des notes.
- Contrôle continu, devoirs et examens ; distinction entre zéro, absence, dispense et note manquante.
- Jusqu’à trois trimestres par niveau/année, barèmes /10 ou /20 et coefficients/poids configurables ; bulletins imprimables avec libellés arabes/français.
- Admissions avec validation du dossier, passage/redoublement/sortie/fin de cycle et historique de l’élève.
- Paiements en DZD, remboursements, rémunérations de gestion, droits d’accès et journal d’audit.

Ces éléments sont constatés dans les routes, services et schémas ; leur présence ne prouve pas leur bon paramétrage dans l’installation actuelle. Les README décrivent encore certaines fonctionnalités comme absentes alors que le code local les contient.

## Priorité 1 — Fiabilité pédagogique

| Besoin | Constat dans le code | Travail recommandé |
|---|---|---|
| Programme par année, niveau et filière | `school.curriculum` rattache les matières au niveau sans version annuelle. `AlgerianCurriculum::sync()` remplace les liens et les horaires du catalogue. | Versionner les programmes par année scolaire ; rattacher séances, évaluations et bulletins à la version applicable. Une mise à jour doit préserver les années clôturées. |
| Règles de notation vérifiées | Le calculateur accepte les poids configurés. Les nouvelles associations du catalogue utilisent le coefficient technique 1. La documentation indique qu’aucune grille nationale 2026–2027 n’est prévalidée. | Constituer des profils vérifiés par cycle, niveau, matière et filière, avec référence de circulaire et validation par la direction. Distinguer paramètres de test et paramètres utilisables pour les bulletins définitifs. Ne pas inventer de formule nationale unique. |
| Clôture et archivage des bulletins | Ajouté après cet audit : brouillon, validation de la classe complète, clôture irréversible, archive figée des résultats/règles/identités et du document HTML/CSS ; moyenne annuelle à parts égales issue des trois trimestres clôturés. Tests fonctionnels dédiés réussis. Voir `docs/bulletins.md`. | Prévoir ultérieurement une réédition corrective tracée avec nouvelle version et vérifier les paramètres réglementaires applicables à chaque cycle. |
| Passage et orientation cohérents | `StudentLifecycle::progress()` exige une année ultérieure et un niveau différent pour le passage, sans vérifier le niveau suivant ni les transitions entre cycles/filières. Les décisions sont manuelles et définitives. | Définir les transitions autorisées, les dérogations motivées et les vœux d’orientation. Enregistrer l’avis du conseil, les résultats utiles et une procédure de correction autorisée. Maintenir la décision humaine. |
| Couverture du lycée | `algerian_curriculum.php` contient les programmes des deux troncs communs de 1AS ; aucune grille de 2AS/3AS n’y est définie. | Si l’école accueille ces classes, ajouter leurs filières, matières, coefficients et volumes sur sources vérifiées. Pour une école limitée à la 1AS, il s’agit d’une extension future. |

## Priorité 2 — Parcours scolaires algériens

| Besoin | État actuel | Travail recommandé |
|---|---|---|
| Évaluation des acquis au primaire | Observations qualitatives possibles dans les bulletins, mais aucun suivi structuré des compétences repéré. | Ajouter compétences, niveaux de maîtrise, campagnes d’évaluation et remédiation selon les grilles applicables. Séparer ce suivi de la moyenne numérique. |
| BEM et baccalauréat | Aucun module dédié repéré dans les routes et services examinés. | Suivre candidats, références d’inscription, pièces, résultats et décisions d’orientation. Conserver les notes d’examens nationaux séparément des évaluations internes. |
| Dossier administratif | Identité, date de naissance, matricule interne et responsables présents. L’admission utilise une confirmation de dossier complet, sans téléversement de ses pièces. | Ajouter les champs nécessaires aux formulaires réellement utilisés : identité arabe/latine, lieu de naissance, adresse, établissement précédent et identifiant officiel distinct du matricule interne lorsqu’applicable. Prévoir pièces privées, validation et conservation. |
| Documents scolaires | Routes de documents consacrées aux bulletins. | Prévoir certificat de scolarité, document de transfert/radiation et états administratifs selon les modèles validés par l’école. |
| Calendrier scolaire | Périodes et dates des trimestres existent ; aucun calendrier central de vacances/fermetures repéré. | Configurer rentrée, vacances, jours fériés et périodes d’examens par année ; empêcher la génération de séances pendant les fermetures, sauf exception autorisée. |
| Échanges avec les plateformes officielles | Aucun connecteur national repéré. | Prévoir exports contrôlés, références et suivi des démarches sur les plateformes officielles, suivant l’applicabilité à l’école privée. Une inscription interne ne vaut pas inscription officielle. Ne pas présumer l’existence d’une API publique. |

## Priorité 3 — Usage quotidien

- **Portail parent et élève réel** : l’espace élève reste une page déclarée dans le groupe de démonstration ; aucun portail parent authentifié dédié n’a été repéré. Ajouter accès limité aux enfants liés, bulletins validés, planning, absences et situation des paiements.
- **Notifications** : une outbox existe, mais aucun consommateur de notification n’a été repéré dans les services examinés. Ajouter livraison, préférences de contact, suivi des erreurs et prévention des doublons pour les absences et annonces utiles.
- **Interface arabe/français** : les matières et bulletins ont des libellés arabes, mais la locale globale est `fr`. Ajouter traductions, choix de langue et prise en charge RTL dans les formulaires, tableaux et documents.
- **Vie scolaire**, selon les services offerts : absences prolongées, justificatifs, rendez-vous avec les parents, suivi du soutien, aménagements pédagogiques et autorisations de récupération. Transport et cantine restent des extensions conditionnelles.
- **Documentation** : actualiser les README pour distinguer les fonctionnalités connectées, celles de démonstration et les limites réglementaires de la rémunération.

## Points vérifiés auprès des sources publiques

1. Pour 2026–2027, le Ministère annonce l’anglais dès la 3AP et le français dès la 4AP, ainsi que le maintien du tronc commun au secondaire. Les cours de langues ajoutés plus tôt par cette école sont documentés comme compléments privés ; cet audit ne propose pas de les supprimer. Source : [Ministère, conférence du 30 juillet 2026](https://www.education.gov.dz/2026/07/30/وزير-التربية-الوطنية-ينشّط-ندوة-صحفية/) ; [compte rendu de Radio Algérienne](https://news.radioalgerie.dz/fr/node/91421).
2. Le Ministère décrit l’extension de l’évaluation des acquis aux fins de paliers du primaire. Cela justifie un suivi structuré distinct d’un simple bulletin de moyennes. Source : [Ministère, lancement du BEM 2025](https://www.education.gov.dz/2025/06/01/وزير-التربية-الوطنية-يعطي-إشارة-انطلاق-3/).
3. Le Ministère confirme les parcours numériques de préparation/1AP, la consultation du planning et des examens, ainsi que les notifications d’absence dans son espace des parents. Ces services constituent une référence fonctionnelle, sans prouver une possibilité d’intégration directe pour cette application. Source : [Ministère, 11 avril 2026](https://www.education.gov.dz/2026/04/11/الحفل-التكريمي-للطبعة-الأولى-من-الجائ/).
4. Le BEM et le baccalauréat restent des opérations nationales distinctes des examens internes. Source : [Ministère, 17 mars 2026](https://www.education.gov.dz/2026/03/17/وزير-التربية-الوطنية-يترأس-ندوة-مرئية-23/).

Les sources consultées ne valident pas une table complète de coefficients, les seuils de passage ni les volumes horaires de chaque filière pour 2026–2027. Il faut utiliser les textes applicables avant de préconfigurer ces règles. La recherche complémentaire a confirmé sur le site du Ministère l’annulation, par la décision n° 36 du 9 septembre, de la décision n° 20 de juillet sur les horaires et coefficients du secondaire. Une copie scannée de la grille du moyen a également été examinée, sans authentification indépendante de sa provenance ni validation de la formule des moyennes. Voir [la vérification détaillée des règles de notation](grading-rules-2026-2027.md).

## Fichiers déterminants

- `madrassa-backend/config/algerian_curriculum.php` et `docs/algerian-curriculum.md` : programmes et limites des références.
- `madrassa-backend/app/School/Services/AlgerianCurriculum.php` : synchronisation du catalogue.
- `madrassa-backend/app/School/Services/ReportCardCalculator.php` et `docs/bulletins.md` : calcul et limites des bulletins.
- `madrassa-backend/app/School/Services/StudentLifecycle.php` et `docs/admissions-progression.md` : admissions et passage annuel.
- `ecole-filament/routes/documents.php`, `app/Filament/Pages/StudentSpace.php`, `config/app.php` : documents, espace élève et langue.
- `madrassa-backend/database/schema/001_school.sql` et `config/school_backend.php` : dossiers, responsables, programme et outbox.

Ordre conseillé : programme annuel et règles vérifiées → bulletins clôturés et moyenne annuelle → passage/orientation → acquis et examens nationaux → dossiers/documents/calendrier → portail parent et notifications.
