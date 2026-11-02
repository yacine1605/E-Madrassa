# Préparation des lots 5 à 10 : décisions, prérequis et validation

Date de rédaction : 19 octobre 2026.

Ce document reformule l'analyse fournie et la rapproche des documents du dépôt. Il explique ce qui peut commencer, ce qui attend une décision et ce qui devra être démontré avant de déclarer un lot terminé. Il ne vaut ni décision produit, ni spécification détaillée, ni validation de livraison.

## Situation actuelle

Les lots 5 à 10 sont présentés dans le [SRS 1.2](SRS_Madrassa_1_2.md), sous « Modules ultérieurs (cadrés — à détailler avant le lot) ». Leurs fonctionnalités et leurs conditions de passage sont esquissées, mais leur spécification détaillée doit être rédigée avant leur réalisation.

Le [rapport d'écart](RAPPORT_ECART_SRS_1_2.md) confirme ce travail restant. Sa formulation « n'ont pas encore d'identifiants FR détaillés » est à nuancer : le SRS comporte déjà des identifiants `FR-LMS-*`, `FR-EDT-*`, `FR-IAI-*`, `FR-STK-*`, `FR-DED-*` et `FR-POR-01`. Ce sont les règles détaillées, permissions et scénarios de recette qui restent à compléter.

Chaque lot demande donc trois éléments :

1. **Des décisions** : choisir les règles métier et les options qui restent ouvertes.
2. **Des apports, si nécessaires** : comptes externes, jeux de référence, matériel ou validations que le porteur du projet et les personnes concernées doivent fournir.
3. **Une spécification avant développement** : traduire ces choix en comportements précis et vérifiables.

Le lot 3 reste également ouvert : le rapprochement d'un mois attend la validation de la finance dans [REVUE_LOT3.md](REVUE_LOT3.md). Ce document indique que les reçus arabes ont déjà été validés le 17 octobre 2026 ; cette validation ne remplace pas celle du rapprochement.

## Vue d'ensemble

| Lot | Décisions ou apports principaux | Condition de passage propre au lot |
|---|---|---|
| 5 — Pédagogie | Autonomie des élèves, notation, fichiers et liens, relecture | Parcours enseignant → élève validé de bout en bout |
| 6 — Optimisation | Poids des préférences, architecture du solveur, machine et école de référence | Recette sur une instance réaliste et les cas limites du SRS |
| 7 — Assistant IA | Modèle, matériel, coûts, offre commerciale, quotas, corpus annoté | Évaluation arabe/français réussie et validation humaine des notes |
| 8 — Stock | Aucune décision ouverte identifiée dans l'analyse | Quantités courantes égales à la somme des mouvements |
| 9 — Installation dédiée | Format de licence, hébergement, machine vierge | Installation complète sur un environnement vierge et guides remis |
| 10 — Portail parents | Création des comptes, MFA, validation juridique | Isolation parent/enfant testée et lecture des emplois du temps |

Ces conditions s'ajoutent aux exigences communes de clôture décrites plus bas.

## Lot 5 — Pédagogie

Périmètre : leçons, devoirs, quiz, notes et moyennes simples (`FR-LMS-*`).

### Décisions retenues pour la spécification

| Sujet | Décision retenue |
|---|---|---|
| Rôles et accès | Responsable pédagogique, enseignant et élève connecté. Accès aux activités conditionné côté serveur par l'affectation, l'inscription et `access_granted`. |
| Activités | Une entité avec deux types : quiz chronométré et devoir maison ; les deux ont une échéance. |
| Notation | Barème choisi par activité ; échelle versionnée par programme ou niveau ; stockage en points bruts/max ; seuil de réussite configurable, 50 % par défaut. |
| Ressources | Remises et ressources LMS par liens HTTPS sur liste blanche ; aucun téléversement LMS. Images de l'éditeur par URL externe autorisée, contenu structuré et validé. |
| Relecture | Programme et leçons de référence gérés par le responsable pédagogique. Relecture configurable par école, désactivée par défaut. Les enseignants publient uniquement des ressources complémentaires. |
| Retards et tentatives | Remise tardive refusée par défaut ; acceptation tardive jusqu'à une date explicite ou prolongation individuelle. Quiz : une tentative par défaut, mode `latest`; choix par activité. |

Les comptes élèves liés à leur dossier constituent un socle réutilisable. Leur existence ne suffit pas à livrer les parcours pédagogiques.

Le [document de spécification détaillée du lot 5](SPECIFICATION_LOT5_PEDAGOGIE.md) fixe les parcours, états, règles de notation, permissions, erreurs et critères d'acceptation. Les valeurs par défaut y sont proposées pour permettre la réalisation et peuvent être ajustées par configuration d'activité ou d'école lorsque cela est prévu.

### Validation attendue

Un enseignant affecté publie une activité ; un élève autorisé la réalise ; le résultat est corrigé puis publié selon la politique choisie. La recette couvre aussi le refus d'accès sans `access_granted`, la publication interdite vers un groupe non enseigné, l'isolation des notes entre élèves, les échéances, les tentatives, les liens invalides et les relances idempotentes.

## Lot 6 — Optimisation des emplois du temps

Périmètre : génération sous contraintes, propositions, comparaison, publication, replanification et affectations assistées (`FR-EDT-*`).

### Décisions et apports

- Fixer les poids par défaut des préférences : heures creuses, répartition des matières et de la charge, changements de salle et de site. Le SRS exige des poids configurables.
- Choisir la machine de référence pour mesurer l'objectif proposé de solution réalisable en moins de cinq minutes. Cet objectif ne garantit pas une solution optimale.
- Formaliser l'intégration d'OR-Tools CP-SAT avec l'application PHP. L'analyse propose un service dans un conteneur distinct, piloté par les tâches de fond ; cette architecture doit être décrite avant réalisation.
- Fournir ou valider une instance réaliste d'environ 500 élèves et 20 enseignants, avec une solution de référence : groupes, matières, volumes, disponibilités, salles, équipements et contraintes. Utiliser des données fictives ou anonymisées.

### Spécification

Le [document de spécification détaillée du lot 6](SPECIFICATION_LOT6_EMPLOI_DU_TEMPS.md) (2 novembre 2026) définit le contrat entre l'application et le solveur, les issues des générations, les délais et les diagnostics, et distingue strictement les contraintes obligatoires des préférences pondérées. Une proposition ne devient publiable qu'après contrôle des contraintes et validation par une personne habilitée, par la publication du lot 4. Il propose les poids par défaut, l'architecture, l'instance et la machine de référence ; ces choix sont listés à son §13, à confirmer avant la réalisation.

### Validation attendue

La recette doit couvrir l'instance réalisable de référence, puis les quatre cas du SRS :

1. Instance dont l'impossibilité est prouvée, avec diagnostic exploitable.
2. Délai dépassé : indiquer qu'aucune solution n'a été trouvée dans le délai, sans conclure à l'impossibilité.
3. Remplacement avec préservation des séances non concernées.
4. Affectation correcte en volume mais impossible à planifier.

Aucun planning publié ne doit violer une contrainte obligatoire. Une solution partielle ne peut pas être publiée comme complète.

## Lot 7 — Assistant IA

Périmètre : génération de brouillons de devoirs et quiz, correction assistée, quotas et évaluation (`FR-IAI-*`).

### Décisions et apports

- **OD-03 — Modèle et infrastructure** : choisir le modèle, le mode d'exécution, le matériel et le budget. Le SRS privilégie l'étude d'un modèle local. Toute option externe doit respecter les exigences de résidence des données et obtenir le consentement explicite de l'école ; aucun service externe n'est activé par défaut.
- **OD-02 — Offre commerciale** : définir comment l'option IA est vendue, son quota mensuel par école et le comportement à l'épuisement du quota.
- Fournir un échantillon de réponses en arabe et en français, annotées par un enseignant, avec les notes et commentaires attendus. Les exemples issus de situations réelles doivent être anonymisés pour les tests.

### Spécification à rédiger

Définir les usages autorisés, l'isolation des contenus par école, le suivi de consommation, les limites de coût et le fonctionnement en cas d'indisponibilité. Fixer avant la recette les critères d'acceptation de l'évaluation, séparément pour l'arabe et le français.

La génération produit un brouillon relu avant publication. La correction produit une proposition : l'enseignant valide ou modifie la note et le commentaire. Conserver la proposition, la décision et son auteur. Traiter les réponses des élèves comme des entrées non fiables.

### Validation attendue

L'évaluation satisfait les critères convenus dans les deux langues. Aucune proposition de note issue de l'IA ne devient définitive sans validation de l'enseignant. Une panne de l'IA ne bloque pas les autres fonctions.

## Lot 8 — Stock

Périmètre : articles, catégories, emplacements, mouvements, seuils et inventaires (`FR-STK-*`).

Aucune décision ouverte ni aucun compte externe n'est identifié pour ce lot dans l'analyse fournie. Son cadrage est suffisamment avancé pour **commencer immédiatement la spécification**, puis le développement.

### Spécification à rédiger

Décrire les entrées, sorties, ajustements, transferts et inventaires, avec les permissions correspondantes. Reprendre les règles du SRS :

- quantité courante calculée depuis le registre des mouvements ;
- mouvements immuables, corrigés par un mouvement lié ;
- motif obligatoire pour les sorties et ajustements ;
- aucun stock négatif sauf paramètre explicite ;
- écarts d'inventaire transformés en ajustements.

Les fournisseurs, commandes, valorisations, péremptions et liens avec les dépenses sont des extensions prévues, pas des engagements du premier périmètre.

### Validation attendue

Pour chaque article et emplacement, la quantité courante est toujours égale à la somme des mouvements signés. Vérifier cette égalité après transfert, correction et inventaire, ainsi que lors de sorties concurrentes susceptibles de dépasser le stock disponible.

## Lot 9 — Installation dédiée

Périmètre : packaging Docker, licence et exploitation par le client (`FR-DED-*`).

Les images Docker, le traitement des tâches et le stockage compatible S3 fournissent un socle. Leur présence ne constitue pas, à elle seule, la livraison du mode dédié.

### Décisions et apports

- Définir le format et le mécanisme de vérification de la clé de licence : modules, nombre d'élèves autorisé et date d'expiration.
- Préciser les modalités d'expiration et de renouvellement. **Le comportement de principe est déjà fixé par le SRS : lecture seule à l'expiration, sans perte de données.**
- **OD-04 — Hébergement** : choisir le prestataire du cloud de l'éditeur et ses engagements. Cette décision concerne également la mise en production de l'offre hébergée.
- Fournir une machine ou une machine virtuelle vierge, conforme aux prérequis qui seront documentés, pour la recette d'installation.

### Spécification et guides à rédiger

Définir le contenu du package, la configuration, les prérequis matériels et réseau, la licence et les mises à jour. Rédiger un guide d'installation et un guide d'exploitation couvrant au minimum les migrations réversibles, les sauvegardes, la restauration et les incidents courants. L'accès distant du support exige l'autorisation du client.

### Validation attendue

Installer un tenant dédié sur un environnement vierge à partir du package et des guides. Vérifier le démarrage, la licence, son expiration, les sauvegardes et la restauration, puis remettre les guides au client.

## Lot 10 — Portail parents et élèves

Périmètre : accès personnel aux informations autorisées, notamment planning, présence, notes publiées et situation de paiement (`FR-POR-01`).

Les comptes responsables liés aux élèves et les règles de portée testées pour `TST-03` constituent une base à réutiliser. Les parcours du portail restent à spécifier et à réaliser.

### Décisions et apports

- Choisir entre invitation du parent par l'école et inscription du parent suivie d'une approbation par l'école.
- Décider si le MFA est obligatoire pour les parents et préciser le parcours de récupération de compte.
- **OD-08 — Validation juridique** : faire valider les dispositions applicables à la loi 18-07, notamment les consentements et le contrat de sous-traitance. Cette validation reste une action à obtenir ; ce document ne l'atteste pas.

### Spécification à rédiger

Décrire l'activation, les liens parent/enfant, leur modification et leur révocation, les permissions, les notifications et les consultations autorisées. Prévoir le refus d'accès à un autre enfant ou à une autre école, y compris par accès direct à une URL ou à un export.

### Validation attendue

Tester qu'un parent ne consulte que ses propres enfants. Livrer également la lecture de leur propre emploi du temps par les élèves et leurs parents : cette partie termine le reliquat `FR-PLN-04` du lot 4, identifié dans [REVUE_LOT4.md](REVUE_LOT4.md).

## Prérequis communs et préparation de la production

### Rétablir la CI GitHub

La revue du lot 4 et le rapport d'écart signalent une CI GitHub bloquée par la facturation, avec des vérifications exécutées localement. Régulariser la facturation, relancer les workflows et conserver une exécution verte des tests requis avant la clôture des lots. Les résultats locaux préparent la validation, mais ne remplissent pas l'exigence de CI du SRS.

### Régler la rémunération — OD-06

La finance et chaque école doivent préciser le traitement des séances annulées, des absences justifiées et des remplacements. L'analyse signale que, dans l'attente, ces situations ne génèrent pas d'unités payées ; la revue du lot 4 précise que seules les séances au pointage validé génèrent les unités de rémunération.

Faire approuver la règle définitive et la documenter avant la production. Cette décision reste aussi liée à la clôture du lot 3 : son échéance indiquée dans le SRS est le lot 3.

### Régler l'hébergement et le juridique

- **OD-04** : hébergement et engagements d'exploitation à décider avant la mise en production.
- **OD-08** : validation juridique à obtenir avant le premier client payant.

Le SRS prévoit également **OD-07**, les durées de conservation par catégorie, à valider avec les écoles et un juriste avant le premier client. Ce point complète la préparation de production évoquée dans l'analyse initiale.

### Fournir les accès WhatsApp

L'envoi réel des alertes nécessite le compte Meta WhatsApp Business de l'école, ses modèles de messages approuvés et un jeton d'accès valide. Fournir ces éléments par la configuration sécurisée prévue, sans inscrire le jeton dans ce document ou dans le dépôt.

### Respecter les conditions communes de clôture

Le SRS définit un lot terminé lorsque :

1. Toutes ses exigences MUST sont conformes.
2. Ses tests d'invariants sont verts en intégration continue.
3. La revue de sécurité de ses permissions est faite.
4. Ses migrations sont réversibles.
5. Sa documentation est à jour.

À ces conditions s'ajoutent le critère de passage propre au lot et les validations humaines explicitement exigées.

## Ordre de travail proposé

1. **Commencer le lot 8** : rédiger sa spécification, puis réaliser le registre de mouvements et sa recette.
2. **Répondre aux quatre questions du lot 5** pour permettre la rédaction de sa spécification.
3. **Lancer tôt le choix IA, OD-03** : préparer les exemples annotés et comparer qualité, matériel et coûts avant de figer le choix.
4. **En parallèle**, rétablir la CI et obtenir la validation financière du lot 3, avec le traitement d'OD-06.
5. Préparer les apports des autres lots : instance et machine de référence pour le lot 6, licence et environnement vierge pour le lot 9, règles d'activation des comptes pour le lot 10.
6. Régler l'hébergement, les obligations juridiques et la conservation dans les délais indiqués avant la production et le premier client payant.

## Fiche à compléter avant chaque développement

- **Périmètre retenu** : exigences incluses et exclusions.
- **Décisions approuvées** : règle choisie, responsable et date.
- **Apports disponibles** : données, comptes, matériel et validations nécessaires.
- **Spécification détaillée** : parcours, états, permissions, règles métier, erreurs et cas limites.
- **Recette** : scénarios, données de référence, résultats attendus et critères mesurables.
- **Clôture** : preuve CI, revue des permissions, réversibilité des migrations, documentation et validations humaines requises.

## Documents de référence

- [SRS Madrassa 1.2](SRS_Madrassa_1_2.md) : modules ultérieurs, plan de livraison, critères de clôture et décisions ouvertes.
- [Rapport d'écart SRS 1.2](RAPPORT_ECART_SRS_1_2.md) : état de couverture et travail restant.
- [Revue du lot 3](REVUE_LOT3.md) : rapprochement financier et procès-verbal des validations.
- [Revue du lot 4](REVUE_LOT4.md) : contrôles d'exploitation et reliquat du portail.
