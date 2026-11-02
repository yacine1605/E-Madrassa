# Spécification détaillée — Lot 6 Optimisation de l'emploi du temps

**Statut :** base de spécification proposée pour compléter le SRS 1.2 ; les décisions du §13 sont à confirmer.  
**Périmètre :** génération d'emplois du temps sous contraintes, propositions, comparaison, application et publication, replanification locale, affectations assistées (`FR-EDT-*`).  
**Références :** [SRS_Madrassa_1_2.md](SRS_Madrassa_1_2.md) (FR-EDT-01 à 05, ARC-09, BR-16, FR-JOB-01), planning manuel du lot 4 (FR-PLN-01 à 06, `platform/README.md`, incréments 76 à 80), [PREPARATION_LOTS_5_A_10.md](PREPARATION_LOTS_5_A_10.md).

Ce document précise les parcours, les règles et les critères de recette du lot. Les valeurs marquées « par défaut » sont des réglages de l'école ou des paramètres d'une génération. Les contrôles d'accès décrits ici s'appliquent aux écrans, à l'API, aux tâches de fond et au service d'optimisation.

## 1. Principes et rôles

Le lot 6 ne crée pas un second planning. Le planning manuel du lot 4 reste la seule source des séances : séries hebdomadaires, séances datées, conflits bloquants, versions et publication. L'optimiseur **propose** ; l'application **contrôle et écrit** (ARC-09). Une proposition n'entre dans le planning qu'en passant par les mêmes règles que la saisie manuelle, puis par la validation et la publication d'une version (FR-PLN-05). Deux logiques d'écriture concurrentes sur les séances sont exclues.

| Rôle | Droits |
|---|---|
| Responsable pédagogique (`academic_hr`), administrateur | Lancer une génération, une replanification ou une affectation assistée ; lire, modifier, comparer et appliquer une proposition ; régler les poids. Nouvelle permission `schedule.timetable.generate`, pour toute l'école ou pour les groupes d'un site. |
| Personne habilitée à publier (`schedule.timetable.publish`) | Valider et publier la version issue d'une proposition, comme toute version du lot 4. |
| Direction | Le planning publié, comme au lot 4 ; les propositions seulement si l'école lui attribue `schedule.timetable.generate`. |
| Enseignant, élève | Rien des propositions. Ils voient le planning publié, comme au lot 4. |

Le module `scheduling_optimization` (offre « complète ») exige `scheduling_manual`. Il utilise, s'ils sont actifs, `sites_rooms` (salles), `staff` (habilitations, disponibilités, bornes) et `lms` (affectations d'enseignement). Module coupé : ni menu, ni API, ni tâche ; le planning manuel reste entier.

## 2. Périmètre d'une génération

Une génération porte sur **une période** de dates d'une même année scolaire (souvent un trimestre ou l'année) et sur **un ensemble de groupes** : tous les groupes actifs d'un site, ou une sélection. Elle produit une **semaine type** : pour chaque cours, ses séances dans la semaine, avec jour, heures, enseignant et salle. Les séances datées naissent ensuite de cette semaine type, à l'application (§6), sur les jours ouverts du calendrier.

Les données sont **photographiées** au lancement (copie immuable et empreinte) : une donnée modifiée pendant la recherche ne change pas la génération en cours. L'application de la proposition recontrôle tout sur les données du moment.

**Les cours à placer.** Un cours est un couple groupe × matière du programme du groupe, en vigueur sur la période. Sa durée de séance est celle de la matière du programme (`session_duration_minutes`, sinon la durée par défaut de l'école, `planning.session_minutes`). Son nombre de séances par semaine vient de son volume (FR-PRG-02) :

1. `weekly_hours` connu : `weekly_hours × 60 / durée`. Un résultat non entier est un problème de données signalé avant la recherche.
2. Sinon `session_count` ou `total_hours` : le nombre total de séances réparti sur les semaines d'enseignement de la période, arrondi au supérieur, avec un avertissement si l'arrondi s'écarte du volume de plus de 10 %.
3. La personne qui lance la génération peut retenir un autre nombre par cours (« volume hebdomadaire retenu ») ; ce choix est enregistré dans les paramètres.

**Les séances conservées.** Les séries hebdomadaires que le planificateur a **verrouillées pour l'optimisation** et les séances déjà pointées ou passées sont conservées telles quelles ; elles comptent dans les volumes et occupent leurs créneaux, enseignants et salles. Une séance pointée n'est jamais déplacée (BR-10).

**L'enseignant d'un cours**, dans cet ordre :

1. celui d'une série verrouillée du cours ;
2. l'affectation d'enseignement en vigueur sur la période (lot 5, module `lms`) ;
3. celui de la série actuelle du cours, si l'option « garder les enseignants actuels » est active (par défaut) ;
4. sinon, au choix de l'optimiseur parmi les enseignants habilités, si l'option « proposer les enseignants » est active (désactivée par défaut, voir §8). Sans enseignant possible, le cours est signalé et la recherche ne démarre pas.

**La salle** est choisie par l'optimiseur parmi les salles du site du groupe, en service, de capacité au moins égale à celle du groupe, du type et avec les équipements que demande le cours. Le type et les équipements requis sont une donnée nouvelle de la matière du programme (§9) ; sans exigence, toute salle de classe convient. La salle principale du groupe est préférée (§4). Une école qui n'utilise pas les salles planifie sans salle, comme au lot 4.

## 3. Contraintes obligatoires

Ce sont les conflits bloquants du lot 4, plus les règles qu'une semaine type doit tenir. Une proposition qui en viole une n'existe pas : l'optimiseur ne la produit pas, et l'application refuse de l'enregistrer.

| N° | Contrainte | Source |
|---|---|---|
| H1 | Un enseignant n'a jamais deux séances au même moment. | FR-PLN-03, FR-EDT-01 |
| H2 | Une salle n'a jamais deux séances au même moment. | FR-PLN-03 |
| H3 | Un groupe n'a jamais deux séances au même moment. | FR-PLN-03 |
| H4 | Deux groupes qui partagent un élève sur la période (groupes de programmes différents, soutien, option) n'ont jamais de séances au même moment. L'optimiseur reçoit les paires de groupes liés, jamais les élèves. | FR-PLN-03 AC2, FR-GRP-02 |
| H5 | L'enseignant est habilité pour la matière au niveau du groupe sur toute la période. | FR-STF-02, BR-16 |
| H6 | L'enseignant est disponible : disponibilités hebdomadaires validées, et indisponibilités datées couvrant toute la période ; un membre parti ou non enseignant est exclu. | FR-STF-03 |
| H7 | La salle est du site du groupe, en service, non fermée sur toute la période, assez grande, du type et avec les équipements requis. | FR-SLL-02 |
| H8 | Une séance tient dans un bloc continu de créneaux de la grille, un jour ouvert ; elle ne chevauche jamais une pause (un trou de la grille). | FR-PLN-01 |
| H9 | Chaque cours reçoit exactement son nombre de séances par semaine, de sa durée. | FR-PRG-02, FR-EDT-01 |
| H10 | Les bornes de l'enseignant : heures par jour et par semaine au plus (`max_daily_hours`, `max_weekly_hours`). | FR-STF-03 |
| H11 | Un enseignant qui change de site dans la journée dispose du temps de trajet entre deux séances (`planning.travel_minutes`, 30 minutes par défaut). | FR-PLN-03 |
| H12 | Les séries verrouillées et les séances conservées restent à leur place. | FR-EDT-01, FR-PLN-02 |

**Dérogations (BR-16).** Le lot 4 ne fait qu'avertir pour H10 et H11 ; l'optimiseur les tient pour obligatoires. La personne qui lance la génération peut lever H10 ou H11 **pour un enseignant nommé**, avec un motif : la dérogation est enregistrée dans les paramètres, journalisée et rappelée sur la proposition. H1 à H9 et H12 ne se lèvent jamais : ce sont des conflits bloquants du lot 4, que la publication refuse. L'optimiseur n'assouplit jamais rien de lui-même ; ses diagnostics peuvent suggérer une modification des données, que seule une personne habilitée applique, par les écrans habituels.

**Les exceptions datées** de la période (fermetures, vacances, indisponibilités ou fermetures de salle partielles) ne façonnent pas la semaine type. Elles sont traitées à l'application (§6).

## 4. Préférences pondérées

Une préférence non satisfaite coûte son poids à chaque occurrence. La proposition minimise la somme. Les poids sont des entiers de 0 à 10 ; 0 ignore la préférence. Les poids de l'école (`planning.optimizer.weights`) valent par défaut :

| N° | Préférence | Mesure | Poids par défaut |
|---|---|---|---|
| P1 | Peu d'heures creuses pour les groupes | chaque créneau vide entre deux séances d'un groupe dans la journée | 8 |
| P2 | Peu d'heures creuses pour les enseignants | chaque créneau vide entre deux séances d'un enseignant dans la journée | 4 |
| P3 | Matières réparties dans la semaine | deux séances d'un même cours le même jour (sauf volume qui l'impose), ou un cours de deux séances sur deux jours consécutifs | 6 |
| P4 | Charge quotidienne régulière des groupes | écart, en créneaux, entre la journée la plus chargée et la plus légère d'un groupe | 3 |
| P5 | Charge régulière des enseignants | jours de présence d'un enseignant au-delà du nécessaire | 2 |
| P6 | Peu de changements de salle | chaque changement de salle d'un groupe entre deux séances consécutives ; séance hors de la salle principale du groupe | 2 |
| P7 | Peu de changements de site | chaque journée d'un enseignant sur plus d'un site | 5 |
| P8 | Stabilité | chaque séance déplacée par rapport au planning actuel (régénération et replanification) | 5 |

La personne qui lance une génération peut modifier les poids pour celle-ci ; ils sont enregistrés avec elle. La proposition affiche son score, sa décomposition par préférence et la liste des préférences non satisfaites, avec les groupes, enseignants et salles concernés (FR-EDT-02). Les résultats, les absences, la situation financière ou le sexe des élèves ne sont jamais des données de l'optimiseur.

## 5. Moteur, tâche de fond et contrat

**Architecture.** Un service `optimizer` en Python, avec OR-Tools CP-SAT en version figée, tourne dans son propre conteneur, sur le réseau interne, **sans accès à Internet**. Il n'a pas d'accès à la base. La tâche de fond de l'application (FR-JOB-01, type `schedule.generate`) construit l'entrée, l'envoie au service, suit l'avancement et écrit le résultat. Le service est **sans état** : entrée et résultat restent en mémoire et sont effacés après leur remise, au plus tard une heure après la fin. Les échanges sont authentifiés par un secret partagé de la configuration ; une entrée est limitée à 20 Mo.

**Données transmises.** Uniquement des identifiants opaques propres à la génération et des nombres : cours, durées, volumes, groupes liés, enseignants éligibles, disponibilités, salles éligibles, grille, séances conservées, poids, délai. **Aucun nom, aucun élève, aucune donnée personnelle.** La correspondance avec les enregistrements reste dans l'application, qui vérifie l'école de la tâche à l'exécution (FR-JOB-01).

**Contrat.** Un schéma JSON versionné (`contract_version`) décrit l'entrée et la sortie ; une version inconnue est refusée. La sortie donne l'issue, les séances placées, le score et sa décomposition, la borne connue, les diagnostics, les statistiques du moteur, la graine et la version du moteur.

**Issues** (distinctes de l'état de la tâche, `queued` à `cancelled`) :

| Issue | Sens | Proposition |
|---|---|---|
| `optimal` | solution prouvée optimale pour les poids donnés | oui |
| `feasible` | solution respectant toutes les contraintes, sans preuve d'optimalité | oui |
| `infeasible` | impossibilité **prouvée** | non ; diagnostic |
| `no_solution_in_time` | « Aucune solution trouvée dans le délai » ; rien n'est conclu sur la faisabilité | non ; relancer avec un délai plus long est proposé |
| `invalid_input` | données incohérentes détectées avant la recherche | non ; diagnostic |
| `error` | panne du service ou contrat violé | non ; la tâche échoue avec un message compréhensible |

**Délai.** 5 minutes par défaut (`planning.optimizer.time_limit_seconds`), de 1 à 30 minutes par génération. Le moteur utilise les cœurs de la machine de référence (4 par défaut) avec une graine enregistrée ; la reproductibilité exacte n'est pas garantie avec plusieurs cœurs, l'issue et le respect des contraintes le sont. Une seule génération à la fois par école ; un nombre limité pour toute la plateforme (2 par défaut), les autres attendent en file. L'annulation de la tâche arrête la recherche.

**Avancement.** La tâche affiche sa phase (contrôles préalables, recherche, meilleure solution trouvée et son score, écriture) et le temps écoulé.

**Contrôles préalables.** Avant toute recherche, des conditions nécessaires simples sont vérifiées et produisent un diagnostic immédiat : heures exigées d'un enseignant au-delà de ses disponibilités ou de son maximum ; heures d'un groupe, ou de groupes liés, au-delà des créneaux ouverts ; cours sans salle ou sans enseignant éligible ; durée qui ne tient dans aucun bloc de la grille ; séances conservées en conflit entre elles.

**Diagnostic d'impossibilité (FR-EDT-03).** Les contraintes sont regroupées par famille et par objet (un enseignant, une salle, un groupe, un cours). Quand l'impossibilité est prouvée, le moteur rend un sous-ensemble de groupes de contraintes suffisant pour la produire. L'application le traduit en phrases nommant les objets : « Laboratoire 1 : 34 heures demandées par 4 cours, 30 heures où leurs enseignants sont disponibles. » Chaque diagnostic propose des pistes (disponibilité, salle, volume, dérogation), jamais appliquées d'office.

**Écriture (ARC-09).** L'application recontrôle chaque séance proposée contre toutes les contraintes obligatoires avant d'enregistrer la proposition. Une seule violation, signe d'un défaut du moteur, fait échouer la tâche : rien n'est enregistré. Une solution partielle n'est jamais enregistrée ni présentée comme une proposition.

## 6. Proposition : revue, édition, comparaison, application

**La proposition** réunit les séances de la semaine type, le score et sa décomposition, les préférences non satisfaites, l'issue et les statistiques du moteur, les paramètres et l'empreinte des données. Elle est lue par semaine, par groupe, par enseignant et par salle, comme le planning du lot 4, les préférences non satisfaites signalées.

**Édition manuelle.** Déplacer une séance, échanger deux séances, changer l'enseignant ou la salle. Chaque modification déclenche un **recontrôle complet** des contraintes obligatoires ; une violation est refusée avec sa raison, dans les termes du lot 4 (enseignant occupé, salle trop petite…). Les préférences et le score sont recalculés. Chaque modification crée une **révision** de la proposition, avec son auteur ; l'historique des révisions est conservé.

**Comparaison.** Deux propositions, ou une proposition et le planning actuel sur la période : séances ajoutées, retirées ou déplacées, par groupe, enseignant et salle, et écart de score.

**Application.** Avec `schedule.timetable.generate` et les droits de planifier les groupes concernés (`schedule.session.create`, `schedule.session.update`), en une seule transaction, tout ou rien :

1. les séries non verrouillées des groupes concernés prennent fin la veille du premier jour appliqué (la date d'application ou le début de la période s'il est futur) ; les séances passées, pointées ou annulées ne changent pas ;
2. une série hebdomadaire est créée par séance de la semaine type, du premier jour appliqué à la fin de la période ; ses séances sont générées sur les jours ouverts (FR-PLN-01, FR-PLN-02) ;
3. une occurrence qui tombe sur une exception datée bloquante (indisponibilité de l'enseignant, fermeture de la salle) n'est pas créée : elle est listée parmi les **séances à reprendre**, que le planificateur traite par un remplacement (FR-PLN-06), une replanification locale (§7) ou une annulation motivée ;
4. une version du planning est préparée sur la période (FR-PLN-05).

La version suit ensuite le cycle du lot 4 : validation et publication par `schedule.timetable.publish`, **refusées tant qu'il reste un conflit bloquant** ; notifications **après la publication seulement**, aux seules personnes concernées (BR-19). Si les données ont changé depuis la photographie et créent un conflit, l'application est refusée avec la liste des conflits (« proposition périmée ») ; relancer une génération ou corriger la proposition reste possible.

Le point 3 modifie la règle du lot 4 selon laquelle une série est refusée en entier si l'un de ses jours est en conflit : pour une série issue d'une proposition, les dates en conflit daté sont sautées et listées. La saisie manuelle garde la règle actuelle.

## 7. Replanification locale et remplacements (FR-EDT-04, SHOULD)

Une absence planifiée d'enseignant (FR-STF-04), une indisponibilité datée, une fermeture de salle ou un changement de groupe liste les **séances touchées**. Pour chaque ensemble, le planificateur choisit :

1. **Remplacer** l'enseignant (FR-PLN-06), proposé en premier ;
2. **Replanifier localement** : l'optimiseur déplace seulement les séances touchées, à l'intérieur de leurs semaines, toutes les autres séances étant conservées. Une option permet aussi de déplacer les autres séances des groupes touchés pendant ces semaines. La stabilité (P8) pèse fort ;
3. **Annuler** avec un motif.

Le résultat est présenté sous forme de modifications datées, mises en évidence. Il s'applique séance par séance (au lot 4, la séance quitte alors sa série), chaque modification avec son motif, puis se publie comme toute version. **Les séances non concernées sont inchangées** : la comparaison avant et après ne montre que les séances touchées et, avec l'option, celles des mêmes groupes et semaines.

## 8. Affectations assistées (FR-EDT-05, SHOULD)

**Répartition des élèves dans les groupes.** Pour un programme et une période, les inscriptions validées sans groupe principal sont réparties entre les classes du programme. Contraintes : capacité (BR-07), élèves déjà affectés conservés (verrous), appartenance aux groupes d'option ou de soutien respectée (groupes liés). Préférence : effectifs équilibrés. Les résultats, absences, paiements, le sexe ou l'origine ne sont jamais des critères ; **aucun regroupement par niveau de réussite** n'est proposé en v1. Une règle pédagogique explicite pourra l'introduire plus tard, après décision.

**Affectation des enseignants.** Pour une période, chaque cours sans enseignant reçoit une proposition. Contraintes : habilitation, disponibilités, maximum hebdomadaire, couverture de tous les cours. Préférences : continuité (garder l'enseignant actuel du groupe et de la matière, ou celui de l'an passé pour le même niveau), équilibre des charges, minimum hebdomadaire de chacun atteint.

**Faisabilité.** Avant validation, la proposition d'affectation est soumise au moteur de planning (délai réduit, 2 minutes par défaut). Une affectation correcte en volume mais impossible à planifier est refusée avec son diagnostic (par exemple : deux enseignants indispensables à trois classes ne sont disponibles ensemble que sur des créneaux déjà pris).

**Validation humaine.** Une personne habilitée applique la proposition par les mécanismes existants : affectations d'élèves (FR-GRP-02, `group.membership.manage`) et affectations d'enseignement (lot 5, `lms.assignment.manage`) ; sans le module `lms`, l'enseignant est celui des séries générées. Les paramètres et versions successives des propositions sont conservés.

## 9. Données et réglages nouveaux

- **Matière d'un programme** : type de salle requis et équipements requis (codes des listes de l'école, FR-SLL-02), facultatifs.
- **Série hebdomadaire** : marque « verrouillée pour l'optimisation », posée et retirée par le planificateur, journalisée.
- **Génération** : école, auteur, type (génération, replanification, affectation), période, groupes, paramètres (volumes retenus, poids, délai, options, dérogations avec motifs), empreinte des données, tâche, issue, score, borne, statistiques, version du contrat et du moteur, graine.
- **Proposition et révisions** : séances de la semaine type (cours, jour, heures, enseignant, salle, conservée ou non), auteur et date de chaque révision.
- **Diagnostics et préférences non satisfaites** : code, objets concernés, mesure, poids.
- **Réglages** : `planning.optimizer.weights`, `planning.optimizer.time_limit_seconds` (300), `planning.optimizer.keep_current_teachers` (vrai), `planning.optimizer.propose_teachers` (faux).
- **Permission** : `schedule.timetable.generate`, attribuée par défaut au responsable pédagogique et à l'administrateur.

Générations, propositions et diagnostics suivent la durée de conservation du planning (OD-07) ; ils restent dans l'école (BR-01) et sont journalisés comme le reste du planning (BR-09).

## 10. Erreurs et réponses attendues

| Situation | Réponse attendue |
|---|---|
| Génération sans cours à placer | Refus `422 edt_nothing_to_plan`. |
| Une génération déjà en cours pour l'école | Refus `409 edt_generation_running`, avec un lien vers la tâche. |
| Données incohérentes, impossibilité prouvée | La tâche réussit ; issue `invalid_input` ou `infeasible` ; diagnostic nommant les objets ; aucune proposition. |
| Délai atteint sans solution | Issue `no_solution_in_time`, message « Aucune solution trouvée dans le délai » ; jamais « impossible » ; aucune proposition. |
| Service d'optimisation indisponible | La tâche échoue avec « Service d'optimisation indisponible » ; relance possible ; le planning manuel n'est pas touché. |
| Modification d'une proposition qui viole une contrainte obligatoire | Refus `409` avec le code du conflit du lot 4 (`session_teacher_busy`, `session_room_too_small`…). |
| Application d'une proposition périmée | Refus `409 edt_proposal_conflicts`, avec la liste des conflits ; rien n'est écrit. |
| Application d'une génération sans proposition | Refus `409 edt_proposal_not_applicable`. |
| Dérogation à une contrainte bloquante | Refus `422 edt_derogation_forbidden`. |
| Droits insuffisants, module coupé | `403 permission_denied`, `403 module_disabled`. |

Aucune réponse ne divulgue de données d'une autre école ; les diagnostics ne nomment que des objets que la personne peut lire.

## 11. Parcours et critères d'acceptation

1. **Générer sur l'instance de référence (§12)** : la tâche aboutit à `feasible` ou `optimal` en moins de 5 minutes sur la machine de référence. Appliquée, la version issue de la proposition se valide et se publie sans aucun conflit bloquant, vérifié par les contrôles du lot 4 et non par l'optimiseur seul. Le score et les préférences non satisfaites sont affichés.
2. **Impossibilité prouvée** : (a) un enseignant à 26 heures exigées pour 20 heures disponibles, rejeté dès les contrôles préalables ; (b) un laboratoire demandé par plus de cours que les créneaux où leurs enseignants sont disponibles, prouvé par le moteur. Dans les deux cas, un diagnostic nomme les objets et aucune proposition n'existe.
3. **Délai dépassé** : une instance faisable mais difficile, avec un délai de 10 secondes, aboutit à « Aucune solution trouvée dans le délai ». Elle n'est ni déclarée impossible, ni applicable ; relancée avec un délai suffisant, elle aboutit.
4. **Revue, édition, publication** : une séance déplacée sur un créneau où l'enseignant est occupé est refusée avec sa raison ; déplacée ailleurs, elle est acceptée et le score est recalculé. Deux propositions sont comparées. La proposition est appliquée, les séries verrouillées et les séances pointées restent inchangées, puis la version est validée et publiée. Personne n'est notifié avant la publication.
5. **Remplacement et replanification locale** : un enseignant absent deux semaines. Ses séances touchées sont remplacées ou déplacées ; la comparaison ne montre aucune autre séance modifiée.
6. **Affectation correcte en volume mais impossible à planifier** : chaque enseignant est sous son maximum hebdomadaire, mais leurs disponibilités communes ne permettent pas de placer tous les cours. Le contrôle de faisabilité refuse la validation avec son diagnostic.
7. **Dérogation** : dépasser le maximum hebdomadaire d'un enseignant n'est possible qu'avec une dérogation nommée et motivée, rappelée sur la proposition et journalisée ; une dérogation à une contrainte bloquante est refusée.
8. **Isolation et tâches** : l'entrée envoyée au service ne contient aucun nom ni identifiant d'élève ; une tâche ne lit que son école (TST-01) ; annulée, elle s'arrête ; relancée après une panne, elle ne crée pas de seconde proposition (FR-JOB-01 AC2).

## 12. Instance et machine de référence

**Instance de référence** (fictive, livrée avec son générateur dans les tests, à faire valider par une école) :

- un site, ouvert du dimanche au jeudi ; une grille de créneaux d'une heure, de 8 h à 12 h et de 13 h à 16 h, soit 35 créneaux par semaine, la pause de midi étant un trou de la grille ;
- quatre niveaux de quatre classes, soit 16 classes d'environ 31 élèves et 500 élèves ;
- 24 heures par classe et par semaine, en huit matières environ ; des séances d'une heure et quelques séances de deux heures (travaux pratiques) ; soit 384 heures de cours par semaine ;
- 20 enseignants, dont 4 vacataires aux disponibilités partielles, habilités sur une ou deux matières, avec des maximums hebdomadaires de 18 à 24 heures (au moins 420 heures disponibles) ;
- 18 salles de classe, 2 laboratoires et une salle informatique, avec types et équipements ;
- deux groupes d'option qui partagent des élèves avec plusieurs classes (groupes liés) et trois séries verrouillées.

Une **solution de référence**, construite à part, prouve que l'instance est réalisable ; elle sert aussi à vérifier les contrôles. Les cas limites du §11 sont des variantes de l'instance, livrées avec elle.

**Machine de référence** (proposée ; aucun profil de serveur n'est encore fixé, OD-04) : 4 processeurs virtuels x86-64 et 8 Go de mémoire, sous Linux avec Docker. Le service y tourne avec 4 cœurs. Les mesures publiées indiquent la machine, la version du moteur et la durée.

## 13. Décisions proposées, à confirmer

| N° | Sujet | Proposition |
|---|---|---|
| D1 | Architecture | Service Python OR-Tools CP-SAT dans son conteneur, interne et sans Internet, piloté par les tâches de fond ; aucune donnée personnelle transmise (§5). |
| D2 | Forme de l'emploi du temps | Une semaine type par période ; les semaines alternées (A/B) sont hors périmètre de la v1. |
| D3 | Bornes horaires et temps de trajet | Obligatoires dans l'optimiseur, avec une dérogation nommée et motivée possible (§3). |
| D4 | Exceptions datées partielles | Hors semaine type ; traitées à l'application comme séances à reprendre (§6). |
| D5 | Origine des enseignants | Série verrouillée, puis affectation d'enseignement, puis enseignant actuel ; choix par l'optimiseur sur option seulement (§2). |
| D6 | Poids par défaut | Ceux du §4, réglables par école et par génération. |
| D7 | Délai | 5 minutes par défaut, 30 au plus ; une génération à la fois par école, deux pour la plateforme. |
| D8 | Instance et machine de référence | Celles du §12, l'instance étant à valider par une école. |
| D9 | Répartition des élèves | Capacité, verrous et équilibre seulement ; aucun critère de résultat ni de profil en v1 (§8). |
| D10 | Règle du lot 4 | Une série issue d'une proposition saute ses dates en conflit daté et les liste, au lieu d'être refusée (§6). |

**Hors périmètre de la v1** : semaines alternées ; vœux horaires des enseignants au-delà de leurs disponibilités ; planification des examens et des surveillances ; salles partagées entre écoles ; optimisation des coûts de rémunération.
