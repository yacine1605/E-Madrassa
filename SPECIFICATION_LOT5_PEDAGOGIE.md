# Spécification détaillée — Lot 5 Pédagogie

**Statut :** base de spécification proposée pour compléter le SRS 1.2.  
**Périmètre :** leçons, ressources, activités, remises, quiz, notes et moyennes (`FR-LMS-*`).  
**Référence :** [SRS_Madrassa_1_2.md](SRS_Madrassa_1_2.md).

Ce document précise les parcours et les règles du lot. Les valeurs marquées « par défaut » peuvent être configurées dans les limites indiquées. Les contrôles d'accès décrits ici s'appliquent aux écrans, API, exports, notifications et ressources externes gérées par l'application.

## 1. Modèle et rôles

Une **activité** est une entité unique de type `quiz` ou `homework`. Elle possède une échéance, un barème, une audience et une politique de résultats. `duration_minutes` est obligatoire pour un quiz et nul pour un devoir maison. Un quiz est chronométré ; un devoir maison reçoit une ou plusieurs URL de remise.

| Rôle | Droits pédagogiques |
|---|---|
| Responsable pédagogique (`academic_hr`) | Gérer le programme fixe, les chapitres, les leçons, les règles de notation et les affectations ; administrer les activités et résultats de tous les groupes. |
| Enseignant (`teacher`) | Créer et publier des activités pour les groupes et matières qui lui sont affectés ; consulter les remises de ces groupes, corriger et publier les notes ; ajouter ses propres ressources complémentaires. Il ne modifie pas le programme ni les leçons de référence. |
| Élève (`student`) | Consulter le contenu et réaliser les activités des groupes auxquels il est effectivement inscrit et pour lesquels `access_granted=true` ; consulter ses seules remises et notes publiées. |

Chaque requête vérifie côté serveur le tenant, le rôle, le groupe, la matière, l'affectation en vigueur et l'accès de l'inscription. Une autorisation d'interface ne remplace jamais ce contrôle. La publication par un enseignant vers un groupe ou une matière qu'il n'enseigne pas est refusée côté serveur.

Le retrait de `access_granted` coupe immédiatement l'accès aux leçons et activités en cours et interdit toute nouvelle tentative ou remise. L'élève conserve, en lecture seule, l'accès à ses propres remises et résultats déjà publiés tant que son compte reste actif. Il ne voit jamais les notes d'un camarade. La suspension ou révocation du compte empêche toute connexion.

Lorsqu'une affectation d'enseignant prend fin, cet enseignant perd l'accès opérationnel aux remises et corrections du groupe. Le nouvel enseignant affecté et le responsable pédagogique récupèrent l'accès nécessaire à la continuité ; les corrections antérieures restent attribuées à leur auteur et toute nouvelle action est auditée.

## 2. Programme, leçons et ressources

Le programme et les leçons de référence sont gérés par le responsable pédagogique. Chaque cours est rattaché à un programme, puis rendu accessible aux groupes concernés par ce programme ; l'accès reste contrôlé par l'inscription et `access_granted`. L'ordre des chapitres et des leçons est explicite (`position`), modifiable par réordonnancement et stable pour les tentatives déjà réalisées. La publication peut porter sur une leçon, un chapitre entier ou tout le cours. Une publication de chapitre ou de cours est précédée d'un aperçu ; elle est refusée entièrement si un élément inclus est invalide. Une publication programmée devient visible à la date prévue, dans le fuseau `Africa/Algiers`.

Le cycle d'une leçon est `draft` → `published` → `archived`. Le paramètre d'école `lms.lesson.review_required` ajoute l'état `in_review` et une approbation par un responsable pédagogique différent de l'auteur ; il est désactivé par défaut. Toute modification publiée crée une nouvelle version. La version consultée par l'élève et celle liée à une activité ou tentative restent identifiables ; une leçon modifiée ne réécrit jamais une tentative passée.

Le contenu enrichi est stocké sous forme JSON structurée (schéma de type ProseMirror/Tiptap), validée côté serveur. Le HTML arbitraire, les scripts, les styles intégrés et les attributs d'événement sont rejetés. Les images de l'éditeur sont des URL HTTPS d'hôtes explicitement autorisés ; aucun fichier image n'est téléversé dans le LMS. Les ressources complémentaires des enseignants sont également des liens, rattachés à une leçon visible et publiés directement sans relecture. Un enseignant ne peut modifier ou supprimer que ses propres ressources ; le responsable pédagogique peut toutes les retirer. Les ajouts et retraits sont audités.

La liste blanche de domaines est configurable par tenant. Elle comprend par défaut les domaines usuels de Google Drive/Docs, GitHub et OneDrive, avec validation de frontière de domaine (un nom ressemblant à un domaine autorisé ne suffit pas). L'application ne télécharge ni ne prévisualise le contenu des URL côté serveur. Les liens externes sont ouverts avec `rel="noopener noreferrer"` et un avertissement de sortie de plateforme. Les élèves sont invités à vérifier les droits de partage avant remise.

## 3. Publication et états d'une activité

Parcours de l'enseignant : choisir le groupe et la matière ; créer l'activité en brouillon ; définir type, consignes, échéance, barème et règles ; prévisualiser en mode élève ; publier ou programmer. À la publication, le système valide l'affectation, l'audience, les dates, le barème et les liens, puis fige une version. L'audience initiale est la liste des élèves actifs avec `access_granted=true` à la publication. Un élève ajouté ensuite n'est inclus qu'après ajout explicite par un utilisateur autorisé ; une échéance individuelle peut alors être fixée.

États d'activité : `draft`, `scheduled`, `open`, `closed`, `archived`. L'état des résultats est indépendant : une note peut être à corriger, corrigée mais non publiée, ou publiée. Les états de chaque élève sont `not_submitted`, `absent_excused`, `to_grade`, `graded` et `published`. Le marqueur `late` est une information distincte, conservée avec la remise.

Une activité publiée ne peut être supprimée. Une modification crée une nouvelle version ; chaque tentative ou remise référence la version utilisée. Les nouvelles tentatives utilisent la version courante. Une correction de question ou de barème ne change pas rétroactivement les notes : un responsable pédagogique peut lancer une re-correction explicite, avec motif, historique de l'ancienne et de la nouvelle note, et nouvelle publication selon la politique choisie.

Le barème total est fixé par l'enseignant, strictement positif. Les points d'une question et la note brute sont des décimaux exacts ; la note est comprise entre zéro et le maximum de l'activité. Le coefficient de l'activité est positif, vaut `1` par défaut et est figé à la première tentative ou remise.

## 4. Quiz chronométré

Un quiz exige une heure d'ouverture, une échéance et une durée. Une tentative ne peut commencer qu'entre l'ouverture et l'échéance. Le serveur enregistre `started_at` et calcule `ends_at = min(started_at + duration, deadline_at)`. L'horloge du navigateur n'est qu'un affichage : chaque sauvegarde et envoi est horodaté et contrôlé par le serveur.

Les réponses sont enregistrées progressivement. Après une coupure, l'élève reprend la même tentative et le serveur restitue le temps restant ; une reconnexion ne remet pas le minuteur à zéro. À expiration, le serveur clôt la tentative et corrige les réponses enregistrées ; un traitement de fond garantit également la clôture si le navigateur est fermé. Une réponse arrivée après `ends_at` est refusée. Les envois utilisent une clé d'idempotence : une relance identique renvoie la tentative existante, tandis qu'une même clé avec un autre contenu est refusée.

Le nombre maximal de tentatives vaut `1` par défaut et est fixé à la publication. Chaque tentative commencée consomme une place ; une tentative en cours est reprise, jamais recréée. Le mode de sélection est fixé à la publication : `latest` par défaut, ou `best` (meilleur ratio points obtenus / points possibles ; égalité départagée par la plus récente) ou `average` (moyenne des ratios de toutes les tentatives terminées). Une tentative en cours ne participe pas au calcul.

Les QCM à choix simple ou multiple sont corrigés automatiquement, avec une règle tout ou rien par question et sans points négatifs. Une réponse non sélectionnée vaut zéro pour cette question. Les questions ouvertes restent corrigées par l'enseignant ; une note totale n'est complète qu'une fois toutes ces questions corrigées. Un zéro calculé pour un quiz effectivement remis est une note valide. Un zéro attribué à une non-remise exige en revanche une action explicite de l'enseignant.

## 5. Devoir maison, liens et retards

La remise contient une ou plusieurs URL HTTPS autorisées, sans téléversement de fichier. Le serveur conserve chaque version des URL, l'élève, la date serveur, l'activité et la clé d'idempotence. Une remise relancée avec la même clé ne crée pas de version supplémentaire. Tant que la remise est ouverte, l'élève peut remplacer ses liens ; les versions antérieures restent dans l'historique et seule la dernière version est à corriger.

Par défaut, toute nouvelle remise après l'échéance est refusée. L'enseignant peut choisir à la publication une acceptation tardive jusqu'à une date de clôture explicite ; toute remise ainsi acceptée porte le marqueur `late`. Une prolongation individuelle, motivée et auditée, remplace l'échéance uniquement pour l'élève concerné.

L'enseignant peut signaler une URL inaccessible et demander une nouvelle version. Il fixe alors une échéance de remplacement (48 heures par défaut, configurable). L'ancienne version est conservée et marquée inaccessible. Si la première remise était dans les délais, son statut ponctuel est conservé après remplacement ; sinon, le marqueur tardif reste présent. L'enseignant peut aussi noter la remise à partir de l'historique si aucune version valide n'arrive.

L'horodatage prouve qu'une URL a été remise à un instant donné, pas que son contenu externe est resté inchangé. L'interface indique cette limite et demande à l'élève de vérifier que l'enseignant peut ouvrir le lien.

## 6. Résultats, corrigés et notation

Pour chaque activité, l'enseignant choisit séparément la visibilité des résultats et celle du corrigé : `immediate`, `after_deadline` ou `on_teacher_publish`. Valeur par défaut : `on_teacher_publish` pour les résultats et `after_deadline` pour les corrigés. Une publication immédiate attend que la note de l'élève soit complète ; avec plusieurs tentatives, le corrigé reste caché jusqu'à la dernière tentative autorisée ou la fermeture du quiz. Si une nouvelle tentative modifie une note déjà publiée en mode immédiat, une nouvelle version du résultat est publiée et la précédente reste dans l'historique. `after_deadline` signifie que l'échéance est passée et que la correction de l'élève est complète. Le mode manuel exige une action explicite de publication. Les corrigés ne sont jamais montrés pendant une tentative.

Une moyenne et une note ne sont visibles par l'élève que si elles sont publiées. La publication automatique est permise pour une note entièrement corrigée automatiquement selon le mode choisi ; une correction manuelle attend la fin de la correction. Un enseignant peut publier un élève ou un lot après prévisualisation. Toute modification d'une note déjà publiée crée une nouvelle version ; la version précédemment publiée reste visible jusqu'à publication de la nouvelle. L'élève reçoit une notification sans note détaillée dans le message.

Pour chaque activité, le système stocke la note brute obtenue, le maximum de points, le coefficient, la version de l'activité et la version de la règle de notation. La règle est portée par le programme ou le niveau, jamais par l'école entière ; elle contient le maximum d'échelle (par exemple 10, 20 ou 100), le pas d'affichage (0,25, 0,5 ou 1), la méthode d'arrondi (`half_up` par défaut), le seuil de réussite (50 % par défaut) et sa période d'effet. Les versions sont immuables et sans périodes qui se chevauchent. Une activité conserve la règle applicable à sa publication ; un changement ultérieur ne réécrit pas les résultats passés.

La note affichée est calculée à partir du ratio brut `points_obtenus / points_max`, converti sur l'échelle puis arrondi au pas prévu. Le stockage n'est jamais remplacé par la note arrondie. La moyenne pondérée d'une période utilise les ratios bruts des seules notes publiées : `somme(ratio × coefficient) / somme(coefficients)`. La conversion et l'arrondi sur l'échelle de la période sont appliqués une seule fois au résultat final. Les activités `not_submitted`, `absent_excused`, `to_grade` et `graded` sont exclues de la moyenne. Le seuil de réussite ne transforme pas une note manquante en zéro.

À l'échéance, l'absence de remise devient `not_submitted` ; aucune note zéro n'est créée automatiquement. L'enseignant peut attribuer explicitement zéro pour non-remise, avec confirmation et audit. Une absence excusée requiert un motif, n'est ni une note ni un zéro et reste exclue des moyennes. Une note n'entre dans la moyenne qu'après publication.

## 7. Erreurs et réponses attendues

| Situation | Réponse attendue |
|---|---|
| Enseignant non affecté au groupe ou à la matière | Refus serveur `403 LMS_GROUP_FORBIDDEN`, sans publication ni fuite d'informations sur le groupe. |
| Élève sans `access_granted` ou hors audience | Refus `403 LMS_ACCESS_DENIED` pour contenu et activités ; aucun accès par URL directe. |
| Résultat non publié ou appartenant à un camarade | Réponse `404` ou refus équivalent ne révélant pas l'existence de la note. |
| Activité pas encore ouverte, échéance passée ou tentative expirée | Refus `409 LMS_ACTIVITY_NOT_OPEN`, `LMS_DEADLINE_PASSED` ou `LMS_ATTEMPT_EXPIRED`, avec état récupérable affiché. |
| Nombre maximal de tentatives atteint | Refus `409 LMS_ATTEMPTS_EXHAUSTED` ; les tentatives existantes restent consultables par leur auteur. |
| URL sans HTTPS, mal formée ou hors liste blanche | Refus `422 LMS_LINK_INVALID` ou `LMS_LINK_DOMAIN_BLOCKED`, sans requête serveur vers l'URL. |
| Double envoi après coupure réseau | La clé d'idempotence renvoie le résultat déjà créé ; aucun doublon. |
| Mise à jour d'une question après tentative | Création d'une version distincte ou refus ; aucune mutation silencieuse de la version utilisée. |

Les réponses d'erreur n'incluent ni URL privée d'un autre élève, ni réponse correcte avant sa date de visibilité, ni donnée provenant d'un autre tenant.

## 8. Parcours et critères d'acceptation

1. **Créer et publier un cours :** le responsable pédagogique ordonne les chapitres, crée les leçons structurées, prévisualise puis publie une leçon, un chapitre ou le cours. Un enseignant ajoute une ressource externe autorisée sans modifier la leçon. Une URL non HTTPS ou un HTML arbitraire est refusé.
2. **Publier une activité :** un enseignant affecté publie un quiz ou devoir pour son groupe ; une tentative de publication vers un groupe non enseigné est refusée côté serveur, même avec une requête API directe. Le responsable pédagogique peut administrer l'activité.
3. **Consulter :** un élève actif avec `access_granted=true` ne voit que les activités de son audience. Un élève sans cet accès ne voit ni cours ni activité. Le retrait d'accès bloque immédiatement les nouveaux travaux mais conserve les notes publiées de l'élève en lecture seule.
4. **Passer un quiz :** l'élève démarre avant l'échéance, perd la connexion, reprend la même tentative avec le temps restant, puis envoie. Le serveur clôt à `min(début + durée, échéance)` et une relance d'envoi ne crée aucun doublon.
5. **Remettre un devoir :** une remise à l'heure reçoit un accusé horodaté ; les liens restent dans l'historique. Un lien inaccessible déclenche une demande de remplacement datée. Un lien interdit est refusé. Une remise tardive suit la politique configurée et porte le marqueur attendu.
6. **Corriger et publier :** le QCM auto-corrigé obtient un état corrigé ; les questions ouvertes restent à corriger. L'élève ne voit aucune note avant sa publication. Une note publiée apparaît seulement dans le compte de son destinataire, jamais dans celui d'un camarade.
7. **Calculer la moyenne :** seules les notes publiées comptent. Une activité non remise reste manquante jusqu'à l'attribution explicite de zéro ; une absence excusée et une note en brouillon sont exclues. Une modification de règle pour une période ultérieure ne change pas les résultats historiques.

Les journaux conservent auteur, date, objet, ancienne et nouvelle valeur, motif pour les dérogations, version concernée et adresse IP conformément à `BR-09`. Les ressources et notes respectent l'isolation tenant de `BR-01` et les durées de conservation applicables.
