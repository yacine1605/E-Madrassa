# Référence des matières — Algérie, 2026/2027

Référence des matières initialement vérifiée sur le web le 28 septembre 2026. Les volumes hebdomadaires du primaire sont désormais ceux fournis par l'utilisateur, dans `config/primary_weekly_minutes.json` ; ils n'ont pas été certifiés indépendamment. Les coefficients existants restent inchangés ; les nouvelles associations utilisent la valeur technique 1.

Les listes détaillées totalisent 21 h en 1AP/2AP/3AP, 22 h 30 en 4AP et 23 h 15 en 5AP. Le total annoncé de 25 h 30 en 4AP est incompatible avec les matières fournies : les durées individuelles prévalent. La dernière liste, intitulée successivement « Grade 5 » et « Grade 4 », est interprétée comme la 5AP.

Le contexte privé ajoute initialement le français (3 h) en 1AP/2AP/3AP, l'anglais (2 h) en 1AP/2AP et l'espagnol (1 h) à tous les niveaux. Les volumes de base des matières restent exacts. La récréation est un repère de 0 minute à 10 h 30 : aucun créneau n'est réservé et les cours reprennent à 10 h 30. Le mardi se termine à 12 h 30. La semaine reste de 30 h.

À la demande de l'utilisateur, les ateliers/soutien du primaire **et du préscolaire** sont répartis entre 2 h d'anglais par classe et le reste en français : 1 h au préscolaire et en 1AP/2AP, 3 h en 3AP, 4 h 30 en 4AP et 3 h 45 en 5AP. Ces heures s'ajoutent aux cours de langue. Le libellé « Atelier / soutien » reste affiché, en bleu pour l'anglais et en jaune pour le français, avec le nom de la langue. Chaque atelier est accolé à un cours de la même langue et assuré par son enseignant. Ces compléments privés ne sont pas présentés comme des prescriptions nationales.

## Sources et décisions

- [Ministère, 30 juillet 2026](https://www.education.gov.dz/2026/07/30/وزير-التربية-الوطنية-ينشّط-ندوة-صحفية/) : anglais dès la 3e primaire, français dès la 4e pour 2026/2027.
- [El Massa, réforme des 1re et 2e primaires](https://www.el-massa.com/dz/الحدث/تغيير-كتب-الأولى-والثانية-ابتدائي-الموسم-المقبل) : retrait de l'éducation scientifique et civique comme matières distinctes. Arabe, mathématiques, éducation islamique, activités artistiques et sportives restent proposées.
- [Ech-Chaab, 16 septembre 2025](https://www.ech-chaab.com/new/2025/09/16/212066/) : en 3e primaire, retrait de l'éducation civique et de la géographie ; l'histoire reste distincte.
- [ONPS, catalogue par année et filière](https://www.onps.dz/) : noms arabes et organisation des cycles ; les anciens manuels ne suffisent pas à établir les réformes récentes.
- [Eddirasa, matières de 1re moyenne](https://eddirasa.com/ens-cm/1am/) : sciences naturelles et physique distinctes, informatique, éducation civique, arts et musique.
- [Eddirasa, matières de 1re secondaire](https://eddirasa.com/ens-sec/1as/) et [tableau comparant les deux troncs communs](https://www.arabsschool.net/2015/08/blog-post_74.html) : les deux filières conservent les matières générales, sciences naturelles, physique et informatique ; la technologie distincte concerne le tronc commun scientifique. Les coefficients de ce tableau ancien ne sont pas importés.
- [Ministère, décision du 9 septembre 2026 publiée le 10](https://www.education.gov.dz/2026/09/10/قرار-رقم-36-مؤرخ-في-27-ربيع-الأول-عام-1448-الموا/) : annulation de la décision du 28 juillet sur les horaires et coefficients du secondaire. Ne pas appliquer les suppressions annoncées en juillet.

L'amazighe reste dans le catalogue des matières et dans les cycles supérieurs ; elle ne reçoit aucun horaire ajouté au primaire puisqu'elle ne figure pas dans la grille fournie. Les enregistrements historiques sont conservés.

## Portée et fonctionnement

- Les années scolaires au sens de niveau (1AP, 2AP, etc.) sont séparées. La classe fournit son niveau via son offre de formation.
- Le modèle existant attache le programme au niveau, sans version par période. Cette configuration correspond à 2026/2027 ; elle n'est pas une archive des programmes des années antérieures.
- Primaire privé : 1re/2e = 8 matières ; 3e = 10 ; 4e/5e = 11. Les ateliers sont rattachés à l'anglais ou au français, avec leur propre indication d'activité. Moyen : matières générales, sciences naturelles, physique/technologie, informatique et activités artistiques distinctes.
- Secondaire demandé : une classe de 1re sciences et une de 1re lettres. Les classes A/B existantes sans inscriptions deviennent respectivement ces deux filières ; leurs identifiants et tarifs sont conservés, avec des niveaux et offres distincts. Les 2e/3e secondaires ne reçoivent pas de programme inventé.
- Le préscolaire comporte neuf matières/activités adaptées à l'école, avec les ateliers rattachés aux langues, sans prétention à un programme national officiel.
- `school:subjects-2026` simule et annule les écritures ; `--apply` sauvegarde le catalogue dans le stockage privé avant modification. Les liens incorrects sont remplacés, sans supprimer les matières ni les séances/notes historiques. La commande est idempotente ; la relancer rétablit la référence et peut donc remplacer les adaptations locales.
- Les formulaires séances, évaluations et affectations chargent uniquement les matières de la classe sélectionnée. La création côté serveur et le portail enseignant vérifient la même appartenance. Une classe sans programme n'offre aucune matière.
- `curriculum.hours` contient les heures hebdomadaires décimales, soutien compris pour les langues (0,75 = 45 minutes, 1,75 = 105 minutes), libellées « Heures par semaine ». Les calculs de planning utilisent des minutes entières sur une grille de 15 minutes, avec des séances de 30, 45 ou 60 minutes. Le champ `sessions.activity` conserve la distinction cours/soutien.
