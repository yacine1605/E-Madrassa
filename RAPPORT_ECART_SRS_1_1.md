# Rapport d'analyse d'écart SRS 1.1 ↔ code existant, et plan de développement

**Projet :** Madrassa — plateforme SaaS modulaire de gestion d'écoles et de centres de formation
**Document de référence :** `SRS_Madrassa_1_1.md` (version 1.1, 9 octobre 2026)
**Objet :** procédure demandée en §0.5 du SRS — rapport d'écart par identifiant, inventaire de ce qui est réutilisable, puis plan de correction ordonné par lot.
**Date de l'analyse :** 10 octobre 2026 · **Révision du code analysée :** `263918e` (« version 1.1 »)
**Statut :** à valider. **Aucune modification de code ne doit être engagée avant validation de ce rapport** (§0.5).

---

## 0. Méthode et limites de l'analyse

### 0.1 Ce qui a été lu

| Périmètre | Éléments inspectés |
|---|---|
| Schéma de données | `madrassa-backend/database/schema/001_school.sql` (853 lignes : tables, RLS, 11 fonctions de garde, déclencheurs d'immutabilité et d'audit, vue de soldes), les 12 migrations `madrassa-backend/database/migrations/*` |
| Services métier | `madrassa-backend/app/School/Services/*` (16 services), `app/School/Http/*`, `app/School/Modules/ModuleRegistry.php` |
| API et routage | `madrassa-backend/routes/school.php`, `ecole-filament/routes/*` (7 fichiers de routes + 4 fichiers de commandes Artisan) |
| Interface | 31 pages Filament `ecole-filament/app/Filament/Pages/*`, 6 contrôleurs HTTP, 3 intergiciels, ~40 vues Blade, `public/js/school-backend.js` |
| Configuration | `config/school_backend.php` (catalogue de ressources), `config/school_modules.php`, `config/modules/*.php` (13 manifestes), `config/algerian_curriculum.php`, `config/report_card_coefficients.php` |
| Tests | `madrassa-backend/tests/database.mjs` (34 invariants SQL), `tests/concurrency.mjs`, `ecole-filament/tests/FrontendTest.php` (7 tests), `tests/artifacts/*` |
| Exploitation | `Dockerfile`, `compose.yaml`, `docker/backup/backup.py`, `deploy/runtime-grants.sql`, `deploy/provision-tenant.sql`, `DOCKER.md`, `MODULES.md` |
| Documentation existante | `madrassa-backend/docs/*` (13 documents), `ecole-filament/docs/INTEGRATION.md`, `docs/VALIDATION.md` |

### 0.2 Limites

- **Analyse statique uniquement.** Aucune base n'a été provisionnée, aucun conteneur démarré, aucun test exécuté. Les statuts `CONFORME` reposent sur la lecture du code *et* la présence d'un test correspondant dans `database.mjs`/`concurrency.mjs`, jamais sur une exécution observée.
- Les statuts portent sur le **mode backend** (`SCHOOL_BACKEND=true`). Le mode démonstration (`DemoStore`) est traité séparément au §2.4 : il ne constitue pas une implémentation (voir §5, constat T15).
- Les performances (`NFR-PERF-*`), la disponibilité (`NFR-AVL-*`) et la conformité juridique (`NFR-DATA-02`) ne sont pas vérifiables par lecture de code : elles sont marquées `ABSENT` lorsque ni dispositif ni mesure n'existent, et la documentation du projet le reconnaît déjà explicitement (`docs/ARCHITECTURE.md` §Performances, `docs/VALIDATION.md`).

### 0.3 Conventions

Statuts : `CONFORME` · `PARTIEL` · `ABSENT` · `DIVERGENT` · `HORS_PÉRIMÈTRE` (§0.5 du SRS).
Risque : **bloquant** (empêche la clôture du lot) · **majeur** · **mineur**.
Effort : **S** ≤ 3 j · **M** ≤ 3 sem. · **L** > 3 sem. (par équipe de 1 à 2 personnes).
`ND-nn` = nouvelle décision à trancher, identifiée par cette analyse et absente de la §10 du SRS.

---

## 1. Résumé exécutif

**Cinq constats structurent tout le reste.**

**1. Le code existant n'est pas un embryon de SaaS mutualisé : c'est un déploiement dédié mono-établissement, déjà solide.** Le modèle de données est multi-tenant de bout en bout (`tenant_id` partout, RLS `FORCE` sur toutes les tables, clés étrangères composites `(tenant_id, id)`, rôle SQL d'exécution sans `BYPASSRLS` ni propriété des tables). Mais le runtime épingle volontairement un établissement unique : `TenantContext` refuse toute requête dont le tenant diffère de `DeploymentSchool::id()` ([TenantContext.php:17](madrassa-backend/app/School/Http/TenantContext.php:17)), et l'activation des modules se fait par variable d'environnement et non par tenant. **Autrement dit, le lot 9 du SRS (« Mode dédié ») est largement réalisé, alors que le lot 1 (« Fondations », console éditeur, plans, quotas) est quasi inexistant.** C'est l'écart le plus important du projet et il conditionne le plan.

**2. La couche d'invariants est l'actif majeur, et elle est réutilisable presque telle quelle.** Immutabilité financière appliquée *en base* (déclencheurs sur `payments`, `refunds`, `invoices`, `disbursements`, `expenses`, `contracts`, `payroll_runs`, `audit_events`), capacité de groupe atomique (verrou consultatif + déclencheur + test de course), idempotence par clé sur six opérations, prix figé à l'inscription vérifié par déclencheur, conflits d'emploi du temps (enseignant, salle, groupe, **et élève inter-programmes**) refusés en base, note manquante distincte d'un zéro par contrainte `CHECK`. 34 tests d'invariants SQL couvrent déjà, en tout ou partie, `TST-01`, `TST-07`, `TST-09`, `TST-10`, `TST-11` et `TST-15`. Reconstruire cela ailleurs serait la pire décision possible du projet.

**3. Trois absences bloquent la conformité dès maintenant, indépendamment des lots.**
- **Séparation des tâches (`BR-08`, `FR-IAM-05`, `TST-05`) : aucun dispositif.** Aucune table `sod_rule`, aucun contrôle `approved_by ≠ created_by`. Pire : la génération de rémunération s'auto-valide à la création (`'validated_at' => now()` dans [SchoolService.php:120](madrassa-backend/app/School/Services/SchoolService.php:120)) et la validation des heures n'enregistre pas l'identité du valideur. Les schémas de `work_logs` et `payroll_runs` doivent changer pour que `TST-05` soit même exprimable.
- **Internationalisation et RTL (`FR-I18-01`, `NFR-I18N-*`, `TST-17`) : aucun dispositif.** Locale `fr` unique, repli `fr` ([config/app.php:2](ecole-filament/config/app.php:2)), aucun catalogue arabe, chaînes françaises en dur dans les configurations, les messages d'erreur et les vues, aucun champ de nom arabe sur les élèves ni le personnel, normalisation de recherche latine seulement. Or le SRS place le MVP en **français *et* arabe** au lot 1. C'est un chantier transversal qui touche chaque écran déjà écrit : plus il est repoussé, plus il coûte cher.
- **Modèle de permissions fines : divergent par conception.** Les droits sont un tableau JSON de 16 permissions grossières (`finance.write`, `hr.read`…) stocké sur l'adhésion ([001_school.sql:17](madrassa-backend/database/schema/001_school.sql:17)), là où l'annexe A du SRS en définit ~60 au format `module.resource.action` avec marquage `sensitive`, rôles copiés par tenant, cumul, portées et dates de validité. Le principe P4 est en revanche **respecté** : aucun comportement n'est codé sur un nom de rôle, le code ne teste que des permissions — les ~40 appels à `SchoolService::authorize()` sont exactement les points de couture d'un modèle plus fin.

**4. Deux divergences de modèle de données imposent une décision architecturale avant le lot 2 et le lot 3.**
- **Pas de table d'affectation aux groupes.** L'inscription porte directement `cohort_id NOT NULL`. Conséquences : un seul groupe par inscription, aucun groupe de soutien ni d'option, aucune affectation datée, aucun transfert (la documentation l'admet : « Le modèle courant ne permet pas de transfert direct de groupe », `docs/ARCHITECTURE.md`), et une inscription ne peut pas exister avant d'avoir un groupe — alors que `FR-INS-01` crée l'inscription `pending` puis affecte le groupe à l'étape 4. `FR-GRP-02` est à construire.
- **La rémunération repose sur le contrat RH.** `contracts(basis ∈ monthly|hourly, rate_minor)` porte le montant, alors que `FR-STF-04` l'interdit explicitement (le montant appartient aux règles de rémunération, sous permission distincte) et que `FR-REM-01` exige quatre types (`fixed_monthly`, `hourly`, `per_session`, `mixed`), des tarifs par nœud du référentiel et une résolution par plus proche ancêtre. Les 13 critères d'acceptation de `FR-REM-02` et l'invariant `TST-19` sont hors d'atteinte du modèle actuel.

**5. La pile technique en place satisfait toutes les exigences `ARC-*` : il faut la conserver et amender le SRS (OD-01).** Le code est en **PHP 8.3 / Laravel 12 / Filament 5 / PostgreSQL 16 / Redis**, pas en Python/Django comme le propose la §4.2. Recommandation détaillée au §2.3 : **conserver**, car (a) aucun `ARC-*` ni `NFR-*` ne devient insatisfaisable, (b) l'actif le plus coûteux du projet (constat 2) est en SQL pur, donc indépendant du langage applicatif, (c) les lots 6 et 7 (OR-Tools, IA) doivent de toute façon tourner en processus de fond séparés selon `ARC-09`/`ARC-10` — le dépôt contient déjà des scripts Python de planification, ce qui démontre la cohabitation. Migrer vers Django reviendrait à réécrire le meilleur du code pour un gain architectural nul.

**Volumétrie de l'écart** (détail au §4) : sur 93 exigences fonctionnelles, règles métier et exigences non fonctionnelles évaluées hors lots 5–10 — **9 `CONFORME`, 41 `PARTIEL`, 31 `ABSENT`, 12 `DIVERGENT`**. Le socle est bon mais la surface conforme est faible : le projet est à environ **25–30 % du SRS** en valeur livrée, avec une qualité d'invariants nettement supérieure à ce pourcentage.

---

## 2. Ce qui existe : inventaire du code

### 2.1 Topologie du dépôt

```
E:/E-Madrassa
├── compose.yaml, Dockerfile, docker/           # exploitation : app, postgres, redis, sauvegarde, tunnel
├── MODULES.md, DOCKER.md, school.env.example   # un fichier d'environnement par école
├── ecole-filament/                             # application Laravel 12 + Filament 5 (interface)
│   ├── app/Filament/Pages/ (31 pages)          # navigation par module
│   ├── app/Http/Controllers/ (6)               # portail enseignant, bulletins, paie, scolarité
│   ├── app/Support/                            # BackendPage / DemoPage, DemoStore, HasModuleAccess
│   └── resources/views/, public/js, public/css  # vues Blade + couche Alpine pour les listes
└── madrassa-backend/                           # surcouche installée dans l'application (métier + SQL)
    ├── database/schema/001_school.sql          # LE schéma : RLS, gardes, audit, immutabilité
    ├── database/migrations/ (12)               # évolutions, toutes en SQL explicite
    ├── app/School/Services/ (16)               # SchoolService, StudentLifecycle, TeacherPay, …
    ├── app/School/Http/                        # TenantContext (RLS + transaction), SchoolController
    ├── config/modules/ (13 manifestes)         # activation par déploiement + dépendances
    ├── deploy/runtime-grants.sql               # privilèges minimaux du rôle d'exécution
    └── docs/ (13 documents)                    # architecture, schéma, API, validation, métier
```

Les deux répertoires forment **une seule application** : `madrassa-backend` est une surcouche de code et de configuration déposée dans l'application Laravel. `ARC-01` (monolithe modulaire, un dépôt) est donc respecté dans l'esprit, avec une réserve au §3.1.

### 2.2 Chaîne de traitement d'une requête métier (à conserver intégralement)

```
Requête HTTP (session, même origine, CSRF)
   └─ TenantContext (intergiciel)
        ├─ 401 si non authentifié
        ├─ 403 si le tenant de l'URL ≠ établissement du déploiement
        ├─ DB::transaction(..., 3 tentatives)          ← une transaction par requête (BR-06)
        │    ├─ set_config('app.user_id', …, local)
        │    ├─ vérification de l'adhésion active       ← la RLS exige tenant ET adhésion
        │    ├─ 404 si le module de la ressource est inactif
        │    ├─ calcul des permissions effectives       ← intersection droits × modules actifs
        │    ├─ set_config('app.tenant_id', …, local)
        │    ├─ SET LOCAL lock_timeout = 3s / statement_timeout = 10s
        │    └─ contrôleur → SchoolService → PostgreSQL
        │         └─ déclencheurs : gardes métier, immutabilité, audit
        └─ traduction des erreurs 23514/23505/40001 en messages métier français, jamais de SQL
```

C'est un modèle d'isolation **à deux barrières** (application + RLS) correctement réalisé, avec remise à zéro du contexte au commit ou au rollback — y compris derrière un pool de connexions en mode transaction. C'est précisément ce que demandent `ARC-02` et `NFR-TEN-01`.

### 2.3 Pile technique : constat et recommandation (OD-01)

| Couche | SRS §4.2 (référence) | Code existant | Verdict |
|---|---|---|---|
| Application | Python / Django | **PHP 8.3 / Laravel 12** | `DIVERGENT` → **conserver**, amender le SRS |
| Base de données | PostgreSQL | **PostgreSQL 16** (RLS, JSONB, déclencheurs) | `CONFORME` |
| Interface | Rendu serveur + HTMX | **Filament 5 / Livewire 4 + Alpine** | `DIVERGENT` → équivalent fonctionnel, conserver |
| Tâches de fond | File + planificateur | **absent** (`QUEUE_CONNECTION=sync`) | `ABSENT` → à construire (Redis déjà présent) |
| Fichiers | Compatible S3 | **disque local** | `ABSENT` → à construire (MinIO) |
| PDF | Moteur supportant l'arabe | **impression navigateur de vues Blade** | `DIVERGENT` → à remplacer par un service |
| Optimisation | OR-Tools CP-SAT | scripts Python hors application | `ABSENT` (lot 6) → cohabitation déjà démontrée |

**Recommandation : trancher OD-01 en faveur du maintien de Laravel/PostgreSQL, et amender la §4.2 du SRS.**

Justification, au regard de la règle « les `ARC-*` priment sur le choix de la pile » :

1. **Aucun `ARC-*` ne devient insatisfaisable.** `ARC-02` (RLS), `ARC-11` (montants exacts), `ARC-12` (UTC/Africa/Algiers) sont déjà satisfaits ; `ARC-06` (tâches de fond), `ARC-07` (S3) et `ARC-08` (Docker/env) sont des composants à ajouter, pas des propriétés du langage. Laravel fournit nativement files de tâches, stockage S3, planificateur, i18n et politiques d'autorisation.
2. **L'actif le plus coûteux est indépendant du langage.** Le schéma, les 11 fonctions de garde, les déclencheurs d'immutabilité et d'audit, la vue de soldes et les 34 tests d'invariants sont du SQL. Une migration vers Django les conserverait tels quels… et jetterait les 16 services, 31 pages et 6 contrôleurs qui les exploitent.
3. **Les lots 6 et 7 ne justifient pas un monolithe Python.** `ARC-09` exige que l'optimisation et l'IA tournent **en processus de tâches de fond** et que « l'application métier valide et écrit les résultats ». Un service Python/OR-Tools derrière une file de tâches est donc l'architecture *demandée*, quel que soit le langage de l'application. Le dépôt contient déjà des générateurs Python d'emplois du temps : la cohabitation est acquise.
4. **Le coût de migration est intégralement un coût d'opportunité.** Réécrire ~6 000 lignes de code métier et d'interface déjà testées n'avance aucun lot du SRS.

Le seul argument contraire du SRS — « un seul langage avec OR-Tools et l'IA » — est un confort d'équipe, et il est contredit par `ARC-09`/`ARC-10` qui imposent de toute façon une frontière de processus.

**Point à inscrire comme amendement, pas comme dette :** `ARC-11` exige `NUMERIC(14,2)`. Le code utilise des entiers `bigint` en centimes (`amount_minor`, `price_minor`, `rate_minor`). C'est **arithmétiquement au moins aussi sûr** (pas de flottant, pas d'arrondi implicite) et l'arrondi *half-up* au centime est déjà appliqué (`intdiv($rate * $minutes + 30, 60)`). Deux options : amender `ARC-11` pour accepter l'entier en centimes comme représentation équivalente (recommandé), ou migrer ~15 colonnes et toute l'arithmétique. **→ ND-01.**

### 2.4 Actifs classés par réutilisabilité

#### Catégorie A — Réutiliser tel quel (fondation du projet)

| Actif | Fichiers | Exigences SRS servies |
|---|---|---|
| Isolation multi-tenant en base : `tenant_id` partout, RLS `ENABLE`+`FORCE`, politiques exigeant tenant **et** adhésion active, clés étrangères composites `(tenant_id, id)`, vues `security_invoker` | `001_school.sql` | `ARC-02`, `BR-01`, `NFR-TEN-01`, `TST-01` |
| Privilèges minimaux du rôle d'exécution : pas de propriété, pas de `BYPASSRLS`, pas de `DELETE`, pas d'écriture d'adhésion, `UPDATE` limité colonne par colonne | `deploy/runtime-grants.sql` | `NFR-TEN-01`, `NFR-SEC-04`, `BR-02` |
| Contexte de requête transactionnel avec remise à zéro, délais de verrou et d'instruction, traduction d'erreurs sans fuite SQL ni données personnelles | `TenantContext.php` | `BR-06`, `NFR-SEC-06`, `NFR-UX-04` |
| Immutabilité financière appliquée en base (déclencheur `school.immutable()` sur 8 tables) | `001_school.sql:631-637`, `:816-819` | `BR-03`, `FR-FIN-03`, `TST-11` |
| Capacité atomique : verrou consultatif par établissement + déclencheur `guard_enrollment` + test de course | `001_school.sql:641-663`, `concurrency.mjs` | `BR-07`, `FR-GRP-02`, `TST-07` |
| Idempotence par clé avec comparaison de contenu (`replay()`) sur inscriptions, paiements, remboursements, versements, candidatures, paie | `SchoolService.php:41-55` | `BR-05`, `TST-08`, `TST-10`, `TST-12` |
| Prix figé : le déclencheur refuse une inscription dont le prix diffère du catalogue actif, et une facture dont le montant diffère de l'inscription | `001_school.sql:649-652`, `:810-815` | `BR-11`, `FR-PRG-01 AC1`, `FR-INS-01 AC3` |
| Détection de conflits de séances en base : enseignant, salle, groupe **et élève entre programmes différents** | `001_school.sql:691-709` | `FR-PLN-03`, `TST-15` |
| « Donnée absente ≠ zéro » par contrainte : `grades.status ∈ marked/absent/exempt/missing` avec `CHECK((status='marked')=(score IS NOT NULL))` | `001_school.sql:442-456` | `BR-14`, `FR-LMS-04` |
| Audit en ajout seul : déclencheur sur ~32 tables, aucune modification possible, RLS, index temporel, permission dédiée | `001_school.sql:740-806` | `BR-09`, `FR-AUD-01` (socle) |
| Résolveur de dépendances de modules avec détection de cycle, et permissions effectives = droits ∩ modules actifs | `ModuleRegistry.php` | `FR-PLT-02`, `ARC-04`, `BR-20` |
| 34 tests d'invariants SQL + 2 tests de concurrence | `tests/database.mjs`, `tests/concurrency.mjs` | `NFR-MNT-02`, 6 `TST-*` |
| Exploitation : image Docker, composition multi-écoles par fichier d'environnement, conteneur de sauvegarde (`pg_dump` + archive des fichiers + empreintes SHA-256 + rétention + refus de restauration sur cible non vide) | `Dockerfile`, `compose.yaml`, `docker/backup/backup.py` | `ARC-08`, `NFR-PORT-01`, `NFR-REC-01/02`, `FR-DED-01` |
| Documentation technique et métier honnête, qui nomme ses propres limites | `madrassa-backend/docs/*`, `ecole-filament/docs/*` | `NFR-MNT-03` |

#### Catégorie B — Étendre (bon socle, incomplet)

| Actif | Ce qui est acquis | Ce qu'il faut ajouter |
|---|---|---|
| `students` / `guardians` / `student_guardians` | matricule unique par tenant, parent partagé entre frères et sœurs sans duplication, audit, suppression bloquée si référencé | noms arabe + latin, lieu de naissance, sexe, adresse, **photo** (chaîne de traitement complète), statuts `former`/`blocked` + motif, champs personnalisés, génération du matricule, verrou optimiste, détection de doublons |
| `enrollments` + `invoice_balances` | états pédagogiques conformes, prix figé, idempotence, index unique partiel « une inscription non annulée par offre », gel de l'inscription annulée | création en `pending` (le défaut est `validated`), affectation de groupe séparée, transfert, quota, `access_granted`, dimension financière calculée et nommée |
| `payments` / `refunds` / `disbursements` | montants > 0, garde de surpaiement, garde de sur-remboursement, refus sur inscription annulée, immutabilité, idempotence, tests de course | statut `pending_validation`/`validated`/`reversed`, valideur, `reference` unique, contre-opération **distincte** du remboursement, circuit d'approbation, clôture de période, reçus |
| `sessions` + `guard_session` | 4 types de conflits bloquants, capacité et campus de la salle vérifiés, enseignant actif et habilité, intervalles semi-ouverts | récurrences et génération, statuts `delivered`/`replaced`, verrouillage, versions et publication, remplacements, créneaux et fermetures, suivi de volume |
| `attendance` (élèves) | unicité par (séance, inscription), refus de groupe erroné, motif obligatoire en correction, appel non saisi ≠ absence | filtrage par affectation **à la date** de la séance, seuils et notifications, portée enseignant |
| `teacher_qualifications` + `TeacherEligibility` | affectation refusée si l'enseignant n'est pas habilité (matière × niveau) — `FR-STF-02 AC1` satisfait | dates de validité, héritage d'un nœud parent vers ses enfants (`AC2`), suppression interdite si utilisée, **sortir les codes algériens codés en dur du service générique** |
| `work_logs` + `TeacherPay` | cycle déclaration → validation → rejet motivé, gel après validation, verrou personne/mois, paie du mois fermée refusant les heures tardives, portail enseignant de consultation | rattachement **à la séance**, statuts `present`/`late`/`absent`/`replaced`, identité du valideur (**SoD**), ajustements tracés, validation par lot |
| `admissions` + `StudentLifecycle` | conversion candidature → élève + responsable + inscription + facture **atomique et idempotente**, verrou optimiste par HMAC, décisions motivées, passage annuel contrôlé | tout l'amont CRM : contact, interactions, rappels, source, assigné, motif de perte, statuts paramétrables, anti-doublon téléphone |
| `assessments` / `grades` | barème, coefficient, publication gelant les notes, correction exigeant un motif, note manquante ≠ zéro, saisie enseignant | règles d'arrondi et de passage versionnées, validation avant publication |
| `audit_events` | ajout seul, acteur, entité, action, noms des champs modifiés, ~32 tables couvertes | **valeurs avant/après**, motif, adresse IP, identifiant de requête, opération liée, lecture filtrée par domaine |
| `campuses` / `rooms` | capacité de salle confrontée à celle du groupe à la création de séance (`FR-SLL-02 AC1`) | code, type de salle, équipements, statuts, **fermetures datées** (`AC2`) |
| Import CSV d'élèves | modèle CSV téléchargeable, analyse côté navigateur, contrôle d'en-têtes, plafond 2 Mo / 500 lignes, validation de **toutes** les lignes avant écriture, transaction unique | XLSX, dépôt serveur, tâche de fond, prévisualisation, `external_ref` idempotent, rapport de rejets, rapprochement financier, retour arrière, les 9 autres types de données |
| Contenu du référentiel algérien (`algerian_curriculum.php`, `primary_weekly_minutes.json`, `AlgerianCurriculum`, `school:setup-2026`) | cycles préscolaire/primaire/moyen/secondaire, niveaux, filières du secondaire, matières, volumes horaires hebdomadaires par niveau — exactement le contenu de `private_school_dz` | transformer en **données** de profil versionnées (`FR-PLT-03`), ajouter les libellés arabes, ajouter le profil `training_center` |

#### Catégorie C — À refondre (divergence de modèle)

| Sujet | Modèle actuel | Pourquoi il ne peut pas être étendu |
|---|---|---|
| Référentiel académique | `programs` → `levels` (arbre fixe à 2 niveaux) | `FR-REF-01` exige un arbre **générique** dont la hiérarchie des types est fixée par le profil (`cycle→grade→track` pour une école, `domain→specialty→pathway` pour un centre). Un centre de formation ne peut rien exprimer aujourd'hui. → tables `node_type` / `ref_node` + migration de données |
| Affectation aux groupes | `enrollments.cohort_id NOT NULL` | `FR-GRP-02` exige `group_membership(enrollment_id, group_id, valid_from, valid_to, is_main)` : multi-groupes, dates, transfert, index unique partiel sur le groupe principal. Modification de la clé du modèle d'inscription |
| Rémunération | `contracts(basis ∈ monthly\|hourly, rate_minor)` | `FR-REM-01` exige 4 types, des tarifs par nœud du référentiel, une résolution par plus proche ancêtre, une quantité incluse, et **l'interdiction** de porter le montant sur le contrat RH (`FR-STF-04`). → `pay_rule` + `pay_rule_rate` + `payroll_line` + `payroll_adjustment` |
| Facturation élève | une `invoice` unique couvrant le prix complet, `UNIQUE(tenant_id, enrollment_id)` | `FR-FIN-01` exige des échéances multiples (`installment`) et des ajustements (`adjustment` : remise, annulation de dette, pénalité) avec motif et seuil d'approbation |
| Correction financière | le remboursement sert de correction, et « rend cette somme de nouveau exigible » (`docs/ARCHITECTURE.md`) | `FR-FIN-03` exige une **contre-opération** (`payment_reversal`) qui annule l'effet sur le solde ; `FR-FIN-04` exige un circuit de remboursement **distinct** avec approbateur ≠ demandeur |
| Permissions | 16 permissions grossières en JSON sur l'adhésion | Annexe A : ~60 permissions `module.resource.action`, marquage `sensitive`, rôles copiés par tenant et modifiables, cumul, **portées**, dates de validité. → `permission_catalog`, `role`, `role_permission`, `role_assignment`, `sod_rule` |
| Activation des modules | `SCHOOL_PROFILE` / `SCHOOL_MODULES` par variable d'environnement | `FR-PLT-02` exige une activation **par tenant**, en base, sans redéploiement, avec quotas et dates de validité. Le résolveur de dépendances est réutilisable ; sa source de vérité change |
| Années et périodes | `periods(name, starts_on, ends_on)` à un seul niveau | `FR-REF-05` exige `year` **et** `period` (trimestres/semestres), une seule année `current`, non-chevauchement, clôture d'année |

#### Catégorie D — Hors périmètre du SRS (à décider explicitement)

| Sujet | Volume | Statut SRS |
|---|---|---|
| **Bulletins scolaires** : `report_card_terms`, `report_card_observations`, `report_card_archives`, `ReportCardCalculator`, `ReportCardArchive`, `ReportCardPresets`, `config/report_card_coefficients.php`, 8 vues Blade, cycle brouillon → validation → clôture avec archivage par empreinte, 4 PDF de référence | ~1 200 lignes + 13 routes | `HORS_PÉRIMÈTRE` — la §2.2 exclut explicitement les « bulletins officiels ministériels (formats, trimestres réglementaires, relevés officiels) ». `FR-LMS-04` ne couvre que des « moyennes simples ». **→ ND-02** |
| **Passage annuel** : `annual_progressions`, décisions `promoted`/`repeated`/`left`/`completed` avec contrôles de cohérence niveau/année | ~80 lignes + 2 routes | `HORS_PÉRIMÈTRE` — aucun `FR-*` ne le couvre. Fonctionnalité pertinente pour une école algérienne. **→ ND-03 : l'inscrire au SRS ou la retirer** |
| **Validateurs d'emploi du temps figés** : `WeeklyTimetable` (11 classes et 17 enseignants **codés en dur**), `WeeklyTeachingPlan`, `generate_*_timetables.py`, `import_private_timetable.php` | ~600 lignes | Outillage d'import pour **un** établissement. À sortir du chemin produit et conserver comme jeu d'essai (`tests/fixtures`) |
| **Mode démonstration** : `DemoStore` (session), `seed.json`, `modules.json`, `DemoPage`, 7 tests | ~400 lignes | Prototype d'interface. Réimplémente les règles métier en session, **avec des flottants pour les montants**. **→ ND-04 : bac à sable d'interface clairement étiqueté, ou suppression** |

---

## 3. Analyse d'écart détaillée

### 3.1 Exigences d'architecture (`ARC-*`)

| ID | Statut | Preuve | Ce qui manque | Risque | Effort |
|---|---|---|---|---|---|
| ARC-01 | `PARTIEL` | un dépôt, une application, 13 manifestes de modules, `ModuleRegistry` | les frontières inter-modules ne sont pas appliquées : `SchoolService::query()` accède à n'importe quelle table, `payroll()` écrit dans `expenses` | mineur | M |
| ARC-02 | `PARTIEL` / `DIVERGENT` | `tenant_id` sur 100 % des tables métier, RLS `FORCE`, clés composites, tenant fixé par la requête authentifiée | le runtime épingle un tenant unique ([TenantContext.php:17](madrassa-backend/app/School/Http/TenantContext.php:17)) ; le SaaS mutualisé est impossible en l'état | **bloquant** | M |
| ARC-03 | `PARTIEL` | `institutions` → `campuses` | niveau « Plateforme » absent (ni plan, ni entitlement, ni console) ; aucune garantie « au moins un site » | majeur | M |
| ARC-04 | `DIVERGENT` | activation par `SCHOOL_PROFILE`/`SCHOOL_MODULES`, dépendances résolues, API 404 si module inactif, menus masqués | activation par **déploiement** et non par tenant ; aucun quota ; `FR-PLT-02 AC2` attend 403 + code `module_disabled`, le code renvoie 404 | **bloquant** | M |
| ARC-05 | `PARTIEL` | table `outbox` alimentée **dans** la transaction, aucun envoi réseau en transaction | aucun consommateur ni ouvrier ; les services écrivent directement dans les tables d'autres modules | majeur | M |
| ARC-06 | `ABSENT` | `QUEUE_CONNECTION=sync` ([compose.yaml:30](compose.yaml:30)) | aucune file, aucune table de tâches, aucun état, aucune reprise, aucune page « Mes tâches » | **bloquant** | M |
| ARC-07 | `ABSENT` | `filesystems.php` : disque local uniquement ; `documents` porte `storage_key`/`sha256`/`mime` | aucun stockage S3, aucun préfixe par tenant, aucune URL signée | **bloquant** | M |
| ARC-08 | `CONFORME` | `Dockerfile`, `compose.yaml`, configuration par environnement, aucun service propriétaire | réserve : la composition est orientée développement (`APP_ENV=local`, `DB_SSLMODE=disable`) | mineur | S |
| ARC-09 | `ABSENT` | — | lot 6/7 | mineur | — |
| ARC-10 | `ABSENT` | — | lot 7 | mineur | — |
| ARC-11 | `DIVERGENT` | montants en `bigint` centimes, arrondi *half-up* au centime | type différent de `NUMERIC(14,2)` ; arrondi « par ligne » non formalisé | mineur | S ou L selon ND-01 |
| ARC-12 | `CONFORME` | `timestamptz` partout, `timezone='Africa/Algiers'` applicatif, `CHECK(timezone='Africa/Algiers')` en base | — | — | — |

### 3.2 Règles métier transverses (`BR-*`)

| ID | Statut | Preuve | Ce qui manque | Risque | Effort |
|---|---|---|---|---|---|
| BR-01 | `PARTIEL` | RLS + adhésion + clés composites ; 7 tests d'isolation | exports, tâches de fond, notifications et recherche globale n'existent pas encore ; accès support non encadré | majeur | M |
| BR-02 | `PARTIEL` | clés étrangères + carte de dépendances refusant la suppression ([SchoolController.php:168-184](madrassa-backend/app/School/Http/SchoolController.php:168)) | **aucun `archived_at` ni statut `archived` dans tout le schéma** ; l'archivage n'existe pas, seule la suppression est bloquée. Voir aussi constat T1 | majeur | M |
| BR-03 | `PARTIEL` | immutabilité en base sur 8 tables (`TST-11` couvert) | aucune opération liée : ni contre-opération, ni ajustement, ni avoir | **bloquant** (lot 3) | L |
| BR-04 | `PARTIEL` / `DIVERGENT` | entiers exacts en centimes, DZD verrouillé par `CHECK`, arrondi *half-up* | type ≠ `NUMERIC(14,2)` ; règle « total = somme des lignes arrondies » non applicable (pas de lignes) | mineur | S |
| BR-05 | `PARTIEL` | clé d'idempotence unique par tenant + `replay()` comparant le contenu, sur 6 opérations ; 2 tests de course | imports non idempotents ; aucune clé sur les futures opérations (échéances, contre-opérations) | majeur | S |
| BR-06 | `CONFORME` | une transaction par requête ; inscription + facture + audit + événement atomiques ; paie + dépense atomiques | — | — | — |
| BR-07 | `CONFORME` | verrou consultatif + déclencheur + `UNIQUE` partiel ; test « 2 requêtes concurrentes, une seule place » | à revalider après l'introduction de `group_membership` | — | — |
| BR-08 | `ABSENT` | — | aucune règle, aucune table, **et le modèle actuel l'interdit** : la paie s'auto-valide, les heures validées n'enregistrent pas le valideur | **bloquant** | M |
| BR-09 | `PARTIEL` | audit en ajout seul sur ~32 tables, acteur + action + champs modifiés | valeurs avant/après, motif, adresse IP, identifiant de requête, opération liée | majeur | M |
| BR-10 | `ABSENT` | `contracts(starts_on, ends_on)` uniquement | aucune date de validité sur groupes, tarifs, règles, rôles, affectations, habilitations, volumes horaires | **bloquant** | L |
| BR-11 | `CONFORME` | déclencheur refusant un prix d'inscription ≠ catalogue actif, et une facture ≠ inscription ; test « price snapshot frozen » | — | — | — |
| BR-12 | `PARTIEL` | états pédagogiques exacts ; vue `invoice_balances` ; test « un paiement ne réactive pas une inscription suspendue » | dimension financière non nommée (`unpaid`/`partially_paid`/`paid`), indicateur `overdue` absent, libellé combiné absent | majeur | M |
| BR-13 | `PARTIEL` | matière et niveau sont des clés étrangères ; matière validée contre le programme du niveau de la classe | l'élève n'a **aucune** référence de niveau ; `staff.role`, `student_guardians.relationship` et les moyens de paiement sont du texte libre ou des énumérations figées | majeur | M |
| BR-14 | `PARTIEL` | notes : `status='missing'` + `CHECK` ; appel : absence de ligne ; 2 tests | heures : aucun état « non pointé » au niveau de la séance (les heures sont déclarées par jour, pas par séance) | majeur | M |
| BR-15 | `PARTIEL` | la paie du mois fige les heures (`payroll_already_closed`) | aucune clôture de période financière | **bloquant** (lot 3) | M |
| BR-16 | `PARTIEL` | capacité, conflits, habilitation et campus/capacité de salle sont bloquants et non contournables | séparation des tâches absente ; aucune notion de « tarif manquant » | majeur | M |
| BR-17 | `PARTIEL` | l'audit n'enregistre que des noms de champs ; les erreurs API ne renvoient ni SQL ni données personnelles | aucune politique de conservation, aucun traitement spécifique des mineurs, non testé | majeur | M |
| BR-18 | `PARTIEL` | codes uniques par tenant sur programmes, niveaux, matières, personnel, élèves | les codes sont **modifiables** par l'API de mise à jour ; libellés mono-langue ; pas de code sur les sites ni les salles | majeur | S |
| BR-19 | `ABSENT` | — | pas de notifications (voir `FR-NOT-01`) | majeur | — |
| BR-20 | `PARTIEL` / `DIVERGENT` | menus masqués, API refusée, données conservées | granularité déploiement et non tenant ; code HTTP 404 au lieu de 403 `module_disabled` | majeur | S |

### 3.3 Exigences fonctionnelles

#### Module PLT — Console plateforme

| ID | Statut | Preuve | Ce qui manque | Risque | Effort |
|---|---|---|---|---|---|
| FR-PLT-01 | `ABSENT` | `institutions(name, kind, timezone, currency, settings)` existe ; provisionnement par SQL/Artisan manuel | `slug` immuable, `status` (`active`/`suspended`/`archived`), `default_locale`, console, création du site par défaut, invitation du premier administrateur, journalisation, idempotence | **bloquant** | L |
| FR-PLT-02 | `ABSENT` | `ModuleRegistry` résout les dépendances et refuse les cycles | `plan`, `tenant_entitlement`, quotas (élèves, stockage, IA), `valid_from`/`valid_until`, `grace_days`, lecture seule à l'expiration, blocage d'inscription au dépassement, 403 `module_disabled`, invalidation de cache | **bloquant** | L |
| FR-PLT-03 | `PARTIEL` / `DIVERGENT` | `algerian_curriculum.php` + `primary_weekly_minutes.json` + `AlgerianCurriculum::sync()` + `school:setup-2026` produisent les 4 cycles, les niveaux, les filières du secondaire, les matières et les volumes hebdomadaires | contenu **codé en dur** pour un déploiement, pas de catalogue de profils en données, pas de version, pas de `platform_code`, pas de libellés arabes, profil `training_center` inexistant, prix 2026/2027 figés dans le code | majeur | M |
| FR-PLT-04 | `ABSENT` | — | `support_access_grant`, consentement, durée ≤ 72 h, bannière, lecture seule, exclusion des données salariales, journalisation dans le journal du tenant | majeur | M |
| FR-PLT-05 | `ABSENT` | — | tableau de bord éditeur agrégé | mineur | S |

#### Module IAM — Identités, authentification, rôles

| ID | Statut | Preuve | Ce qui manque | Risque | Effort |
|---|---|---|---|---|---|
| FR-IAM-01 | `PARTIEL` | `public.users` + `memberships(active)` ; commandes `school:user` / `school:provision` ; l'adhésion est revérifiée **à chaque requête** (donc la désactivation coupe l'accès à la requête suivante, `AC1` tenable) ; fiche personnel sans compte possible (`user_id` nullable) | `status ∈ invited/active/suspended/revoked`, lien d'activation 72 h, mot de passe provisoire, `external_ref`, import de comptes, révocation définitive, protection du dernier administrateur (`AC2`), unicité d'e-mail par tenant | **bloquant** | M |
| FR-IAM-02 | `PARTIEL` | authentification de session même origine, CSRF sur toutes les mutations, `throttle:school-login`, `throttle:6,1` sur le changement de mot de passe, régénération de session | **MFA TOTP absent**, codes de récupération absents, hachage **bcrypt** et non Argon2id, politique de mot de passe ≥ 10 absente, refus des mots de passe courants absent, verrouillage progressif absent, expiration d'inactivité paramétrable absente, durée absolue 12 h absente, récupération par lien à usage unique absente | **bloquant** | M |
| FR-IAM-03 | `DIVERGENT` | **principe P4 respecté** : aucun nom de rôle dans la logique métier, uniquement des permissions ; ~40 points d'appel `authorize()` ; presets cumulables en configuration | 16 permissions grossières au lieu de ~60 `module.resource.action` ; aucune table de rôle ; rôles non copiés ni modifiables par tenant ; pas de marquage `sensitive` ; pas d'archivage de rôle ; permissions non assignables par écran | **bloquant** | L |
| FR-IAM-04 | `PARTIEL` | portée personnelle de l'enseignant appliquée côté serveur (`TeacherPortal`, listes limitées à ses classes, planning filtré par son identifiant) | aucun modèle de portée générique (site, niveau, groupe, matière) ; aucune portée élève ni parent ; pas de filtrage des exports (inexistants) | **bloquant** | L |
| FR-IAM-05 | `ABSENT` | — | `sod_rule`, contrôle `approved_by ≠ created_by`, option `finance.allow_self_validation` + marque `self_validated`, interdiction de modifier ses propres rôles, notification des permissions sensibles | **bloquant** | M |

#### Module REF — Référentiel et paramétrage

| ID | Statut | Preuve | Ce qui manque | Risque | Effort |
|---|---|---|---|---|---|
| FR-REF-01 | `ABSENT` / `DIVERGENT` | hiérarchie fixe `programs` → `levels` | `node_type(code, labels, level_order, is_required)`, hiérarchie pilotée par le profil, `domain→specialty→pathway` pour les centres, libellés renommables sans changer le code (`AC1`, `AC2`) | **bloquant** | L |
| FR-REF-02 | `PARTIEL` | CRUD avec `UNIQUE(tenant, code)`, suppression refusée si référencé, audit | `status ∈ active/archived` et archivage, `sort_order`, détection de doublons **normalisée** (casse, espaces, diacritiques arabes), `platform_code`, libellés multilingues, refus d'archiver un parent ayant des enfants actifs | **bloquant** | M |
| FR-REF-03 | `PARTIEL` | `subjects(code, name)` unique par tenant, suppression bloquée si référencée (`AC1`/`AC2` tenables), audit | `labels{fr,ar,en}`, `kind ∈ subject/module` et libellé dépendant du profil (`AC3`), `status archived`, permission dédiée `subject.subject.manage` (aujourd'hui `catalog.write`), unicité normalisée | majeur | M |
| FR-REF-04 | `ABSENT` | `institutions.settings` (JSONB) existe comme point d'accroche | `custom_field_def`, 4 entités, 5 types, limite de 20 champs, `is_sensitive`, valeurs JSONB, interdiction de changer le type si des valeurs existent | majeur | M |
| FR-REF-05 | `PARTIEL` / `DIVERGENT` | `periods(name, starts_on, ends_on)` avec `CHECK(ends_on>=starts_on)` et unicité par tenant | deux niveaux `year` + `period`, une seule année `current`, refus de chevauchement (`AC1`), clôture d'année figeant groupes, inscriptions et résultats (`AC2`) | **bloquant** | M |
| FR-REF-06 | `PARTIEL` | `institutions.settings` JSONB, fuseau et devise verrouillés par `CHECK` | aucun écran ; identité et coordonnées pour les reçus, logo, langues activées, **jours ouverts** (dimanche–jeudi), premier jour de semaine, créneaux horaires, politiques et seuils, journalisation des paramètres sensibles | **bloquant** | M |

#### Module TRV — Services transverses

| ID | Statut | Preuve | Ce qui manque | Risque | Effort |
|---|---|---|---|---|---|
| FR-AUD-01 | `PARTIEL` | `audit_events` en ajout seul (déclencheur `immutable` + aucun `DELETE` accordé), acteur, entité, identifiant, action, champs modifiés, RLS, index `(tenant, created_at DESC)`, permission `audit.read`, page Filament ; `AC2` satisfait | `before`/`after` (seuls les **noms** de champs sont conservés, donc `AC1` échoue), `reason`, `ip`, `request_id`, `linked_operation_id`, filtres par utilisateur/objet/période, séparation accès technique / journaux financiers, masquage des champs salariaux, `actor_id` nul pour les traitements hors requête | **bloquant** | M |
| FR-I18-01 | `ABSENT` | locale `fr`, repli `fr` ; `lang/fr/` seulement ; des en-têtes arabes **statiques** dans les vues de bulletins montrent l'intention | catalogues `ar`/`en`, suppression des chaînes en dur (configurations, messages de validation, messages métier, Blade), langue par utilisateur, styles logiques pour RTL, libellés par langue sur le référentiel, **noms de personnes arabe + latin**, normalisation arabe de recherche (alif/hamza, ya/alif maqsura, ta marbuta, diacritiques), formats de dates et de montants localisés, test de pseudo-localisation | **bloquant** | L |
| FR-IMP-01 | `PARTIEL` | pour les élèves seulement : modèle CSV téléchargeable, analyse navigateur avec contrôle d'en-têtes et de guillemets, plafonds 2 Mo / 500 lignes, **validation de toutes les lignes avant toute écriture**, transaction unique, erreurs numérotées par ligne | dépôt serveur et XLSX, tâche de fond, **prévisualisation** (`AC3`), `external_ref` et réimport idempotent (`AC1`), résolution des références par code du référentiel, contrôle de doublons, rapprochement financier, reprise à blanc, retour arrière, rapport de rejets téléchargeable, conservation du fichier d'origine, les 9 autres types de données | **bloquant** | L |
| FR-IMP-02 | `ABSENT` | — | exports Excel/CSV/PDF, permission d'export par domaine, exécution en arrière-plan au-delà de 2 000 lignes, journalisation, purge à 7 jours, **neutralisation des formules** (`AC2`) | **bloquant** | M |
| FR-FIC-01 | `PARTIEL` | `documents(storage_key unique/tenant, original_name, mime, size_bytes, sha256)`, RLS, audit, clé étrangère vers l'élève | stockage S3 et préfixe `tenant/{id}/`, téléversement en mode backend, **URL signées ≤ 15 min**, vérification de la signature de contenu (`AC1`), types et tailles configurables, nettoyage du nom, 5 catégories de droits (`AC2`), quota de stockage | **bloquant** | M |
| FR-NOT-01 | `ABSENT` | `announcements(title, body, published_at)` + page + publication par transition ; `outbox` enregistre les événements | table de notifications, cloche, e-mail SMTP, événements configurables, préférences par utilisateur, suivi et rejeu des échecs, minimisation des contenus | **bloquant** | M |
| FR-JOB-01 | `ABSENT` | `QUEUE_CONNECTION=sync` ; Redis déjà déployé | table `job`, états, progression, message d'erreur, relance, annulation, idempotence, page « Mes tâches », vérification du tenant à l'exécution (`AC1`, `AC2`) | **bloquant** | M |
| FR-PDF-01 | `DIVERGENT` | bulletins rendus en HTML Blade, destinés à l'impression navigateur ; archive avec `content_hash` et rendu figé ; 4 PDF de référence produits par Chromium | aucun service PDF, aucune police embarquée, **aucune garantie de ligatures ni de sens RTL** (`AC1`), pas d'en-tête de tenant générique, pas de numérotation figée, pas de génération dans la langue du destinataire | **bloquant** | M |

#### Module ELV — Élèves et responsables

| ID | Statut | Preuve | Ce qui manque | Risque | Effort |
|---|---|---|---|---|---|
| FR-ELV-01 | `PARTIEL` | `students(matricule unique/tenant, first_name, last_name, birth_date ≤ today, phone, email, active, user_id)` ; CRUD validé ; audit ; suppression bloquée si inscription (`AC2` tenable) ; portée enseignant appliquée (`AC3` partiellement) | noms **arabe et latin**, `birth_place`, `gender`, `address`, **photo intégralement absente** (formats, ≤ 2 Mo, recadrage, redimensionnement 1024/128, suppression EXIF, suppression définitive au remplacement, bascule `student.photo.enabled`, avatar par initiales — donc `AC0` échoue), `status ∈ active/former/blocked` + motif obligatoire, `origin_school`, champs personnalisés, **génération** du matricule `{année}{séquence}` (aujourd'hui saisi), verrou optimiste (`version`), avertissement de doublon probable | **bloquant** | L |
| FR-ELV-02 | `PARTIEL` | `guardians` + `student_guardians(relationship, can_collect)` ; `UNIQUE(tenant, student, guardian)` ; un responsable est partagé entre frères et sœurs sans duplication (`AC1` satisfait) | `relationship ∈ father/mother/guardian/other` (aujourd'hui texte libre), noms arabe/latin, profession, `is_primary_contact`, `is_payer`, `emergency_contact`, règle « un mineur sans contact principal ne peut pas être inscrit » (`AC2`) | majeur | M |
| FR-ELV-03 | `ABSENT` | `active boolean` seulement | transitions `active`→`former` et `active`↔`blocked` avec motif, journalisation, archivage après délai, avertissement sur les inscriptions actives (`AC1`) | majeur | M |
| FR-ELV-04 | `PARTIEL` | recherche multi-champs avec repli d'accents latins et pagination par curseur ; champs de recherche dérivés des règles de validation ; échappement `LIKE` correct | normalisation **arabe**, score de similarité (nom normalisé + date de naissance, ou téléphone), détection de doublons à la création et à l'import (`AC1`), filtrage par portée | **bloquant** | M |

#### Module CRM — Admission

| ID | Statut | Preuve | Ce qui manque | Risque | Effort |
|---|---|---|---|---|---|
| FR-CRM-01 | `PARTIEL` / `DIVERGENT` | `admissions` est un **dossier de candidature** : statuts `submitted/waiting/accepted/rejected/withdrawn/enrolled`, `documents_complete`, `review_reason` obligatoire, idempotence par clé **et** empreinte de requête, verrou optimiste par HMAC, statuts terminaux respectés, page Admissions + 5 routes | tout l'amont : `lead` (contact, enfants, niveau visé par référence, `source` paramétrable, `assigned_to`), `lead_interaction`, rappels et notification à l'assigné (`AC1`), statuts paramétrables, `lost_reason`, anti-doublon téléphone, garantie « un prospect n'entre dans aucun effectif » (`AC3`) | majeur | M |
| FR-CRM-02 | `PARTIEL` | `StudentLifecycle::admit()` : élève + responsable + lien + inscription + facture **dans une transaction**, idempotent (réutilise l'identifiant de candidature comme clé), passage à `enrolled` avec conservation du lien, refus si déjà converti avec une autre classe ou échéance, page d'historique par élève — `AC1` et l'esprit de `AC2` sont satisfaits | l'historique des **interactions** n'existe pas (dépend de `FR-CRM-01`) ; préremplissage limité aux champs du dossier actuel | mineur | S |

#### Module SLL — Sites et salles

| ID | Statut | Preuve | Ce qui manque | Risque | Effort |
|---|---|---|---|---|---|
| FR-SLL-01 | `PARTIEL` | `campuses(name unique/tenant, wilaya_code, address)`, audit, suppression bloquée si salles ou offres | `code`, `name{fr,ar,en}`, téléphone, `status`, garantie « au moins un site actif », refus d'archivage avec salles actives | majeur | S |
| FR-SLL-02 | `PARTIEL` | `rooms(campus, name unique/campus, capacity ≥ 1)` ; `guard_session` refuse une salle d'un autre campus ou de capacité < celle du groupe → **`AC1` satisfait et testé** | `code`, `room_type` paramétrable, `equipment[]`, `status ∈ active/unavailable/archived`, **`room_closure` datée** (`AC2` échoue) | majeur | M |

#### Module STF — Personnel

| ID | Statut | Preuve | Ce qui manque | Risque | Effort |
|---|---|---|---|---|---|
| FR-STF-01 | `PARTIEL` | `staff(code unique, name, user_id nullable, is_teacher, active, role text)` ; **fiche sans compte possible** et pouvant recevoir une rémunération → `AC2` satisfait ; un enseignant inactif reste dans l'historique des séances → `AC1` satisfait | `staff_type ∈ teacher_internal/teacher_external/administrative/other` (aujourd'hui `role` en texte libre, ce qui bloque les règles de `FR-REM-01`), `status ∈ active/on_leave/left` + date, noms arabe/latin, date d'embauche, `staff_number` distinct du code | **bloquant** | M |
| FR-STF-02 | `PARTIEL` | `teacher_qualifications(teacher, level, subject)` + `TeacherEligibility::validate()` refusant toute séance ou affectation non habilitée → **`AC1` satisfait** | `valid_from`/`valid_to`, **héritage d'un nœud parent vers ses enfants** (`AC2` échoue : les habilitations sont par niveau exact), interdiction de supprimer une habilitation utilisée. Voir constat T8 : des codes de matières algériens sont codés en dur dans ce service générique | **bloquant** | M |
| FR-STF-03 | `ABSENT` | — | `availability`, `unavailability`, `min_weekly_hours`/`max_daily_hours`/`max_weekly_hours`, saisie par l'enseignant, validation conditionnelle, priorité de l'indisponibilité, avertissements à la création de séance | **bloquant** (lot 4) | M |
| FR-STF-04 | `PARTIEL` / `DIVERGENT` | `contracts(staff, starts_on, ends_on, basis, rate_minor)` avec **non-chevauchement par déclencheur** et immutabilité, testés | **le contrat porte le montant**, ce que `FR-STF-04` interdit : `hr.read` donne accès au tarif, donc `AC1` (consulter les contrats sans voir de montant) est violé par conception. Pas de versionnement de type, pas de volume horaire contractuel, **aucun congé** (`leave`), donc `AC2` absent | **bloquant** | M |

#### Module GRP — Programmes, groupes, inscriptions

| ID | Statut | Preuve | Ce qui manque | Risque | Effort |
|---|---|---|---|---|---|
| FR-PRG-01 | `PARTIEL` / `DIVERGENT` | `offerings(level, period, campus, name, price_minor, active)` joue le rôle de programme ; le prix est figé sur l'inscription par déclencheur → **`AC1` satisfait et testé** ; une offre inactive n'est pas proposée → `AC2` tenable ; déplacement d'une offre utilisée refusé | `code`, `name{fr,ar,en}`, `duration`, `description`, **`price_version`** (modifier le tarif doit créer une version), `payment_terms`, `status ∈ draft/active/inactive`, gel par année clôturée, liste de matières **au niveau du programme** (aujourd'hui rattachée au niveau) | **bloquant** | M |
| FR-PRG-02 | `PARTIEL` / `DIVERGENT` | `curriculum(level, subject, hours, coefficient)` avec unicité et validation « la matière appartient au programme du niveau » ; volumes hebdomadaires par niveau disponibles en configuration (`primary_weekly_minutes.json`, `WeeklyTeachingPlan`) | rattachement **au programme** et non au niveau ; `weekly_hours`, `session_count`, `session_duration_minutes` ; règle « au moins une des trois valeurs » (`AC2`) ; `valid_from`/`valid_to` et versionnement (`AC1`) ; retrait par fin de validité si des séances existent (`AC3`) ; avertissement d'écart > 10 % ; permission `program.subject.manage` | **bloquant** | M |
| FR-GRP-01 | `PARTIEL` | `cohorts(offering, name unique/offering, capacity ≥ 1)` ; **réduction de capacité sous l'effectif actif refusée** → `AC2` satisfait ; `AC1` réalisable ; déclencheur d'immutabilité levé explicitement par migration dédiée | `code`, **`group_type ∈ class/support/option/session`**, `site_id` propre, `main_room_id`, `lead_teacher_id`, `valid_from`/`valid_to`, `status ∈ draft/active/closed`, conservation de composition à la fermeture | **bloquant** | M |
| FR-GRP-02 | `DIVERGENT` | contrôle de capacité **atomique et testé** (verrou + déclencheur + course) → `AC1` satisfait sur le modèle actuel ; conflit d'horaire élève vérifié à l'inscription | **la table `group_membership` n'existe pas** : `enrollments.cohort_id NOT NULL`. Donc : pas de multi-groupes, pas de dates, pas de `is_main` ni d'index unique partiel (`AC2`), pas de transfert ni d'historique (`AC3`), pas de liste d'attente | **bloquant** | L |
| FR-INS-01 | `PARTIEL` | `enroll()` : idempotence avec comparaison de contenu, prix figé vérifié en base, facture créée dans la même transaction, `UNIQUE` partiel « une inscription non annulée par offre » → **`AC1` et `AC3` satisfaits et testés** | création en `pending` (le défaut en base est `validated` et `enroll()` ne le surcharge pas — voir constat T16, donc l'étape 6 de validation est contournée), affectation de groupe séparée, refus d'un élève bloqué (`AC2`, pas de statut `blocked`), contrôle de quota, génération d'échéancier, instantané des conditions (`terms_snapshot`), `validated_by`/`validated_at`/`cancel_reason` | **bloquant** | M |
| FR-INS-02 | `PARTIEL` | états pédagogiques exacts ; `invoice_balances` calcule le solde net ; **test « un paiement ne réactive pas une inscription suspendue »** → moitié de `TST-09` couverte | dimension financière nommée (`unpaid`/`partially_paid`/`paid`), libellé combiné, indicateur `overdue` (dépend des échéances) | majeur | M |
| FR-INS-03 | `PARTIEL` | inscription annulée **gelée** par déclencheur (`enrollment_identity_frozen`) et réactivation refusée par l'API ; aucun remboursement automatique → `AC1` satisfait | motif de suspension stocké seulement dans la charge utile de l'`outbox` ; **transfert de programme inexistant** (`transferred_from`, report de paiements) → `AC2` absent | majeur | M |
| FR-INS-04 | `ABSENT` | — | indicateur `access_granted`, paramètre `enrollment.access.require_payment ∈ none/first_installment/full`, calcul à la demande | majeur | S |

#### Module FIN — Finance des élèves

| ID | Statut | Preuve | Ce qui manque | Risque | Effort |
|---|---|---|---|---|---|
| FR-FIN-01 | `ABSENT` / `DIVERGENT` | une `invoice` unique par inscription (`UNIQUE(tenant, enrollment_id)`), montant = prix complet, une seule `due_on` ; montant verrouillé sur l'inscription par déclencheur | `installment(enrollment_id, seq, due_date, amount)`, `adjustment(type ∈ discount/debt_waiver/penalty, reason, approved_by)`, contrôle bloquant « somme des échéances = dû − remises » (`AC1`), motif obligatoire (`AC2`), seuil d'approbation direction, modèle d'échéancier du programme, versionnement | **bloquant** | L |
| FR-FIN-02 | `PARTIEL` | `payments(invoice, amount > 0, method, reference, received_at, idempotency_key unique)` ; garde de surpaiement et refus sur inscription annulée **en base** ; `replay()` ; immutabilité ; **test « 2 envois concurrents, un seul paiement »** → `AC1` satisfait, `AC2` satisfait par la vue de soldes | `status ∈ pending_validation/validated/reversed`, `paid_on ≤ aujourd'hui` (seul `received_at` par défaut existe), **`reference` unique et non réutilisable** (`AC3` échoue : nullable et non unique), moyens de paiement **paramétrables par tenant** (énumération figée `cash/bank/ccp/card`), encaissement sur délégation, `self_validated`, refus en période clôturée, trop-perçu explicite | **bloquant** | L |
| FR-FIN-03 | `PARTIEL` / `DIVERGENT` | immutabilité **applicative et en base** sur paiements validés → **`AC1` satisfait et testé (`TST-11`)** | aucun circuit de validation (pas de statut, pas de valideur, pas de `finance.payment.validate`) ; **aucune contre-opération** : le remboursement sert de correction alors qu'il rend la dette exigible (`AC2` échoue) ; `BR-08` non appliquée (`AC3` échoue) ; option `finance.allow_self_validation` absente | **bloquant** | L |
| FR-FIN-04 | `PARTIEL` / `DIVERGENT` | `refunds(payment, amount > 0, reason ≥ 3 car., idempotency_key)` ; garde de sur-remboursement **en base** ; immutabilité ; annuler une inscription ne crée aucun mouvement → `AC1` satisfait | `refund_request` et approbation direction (`AC2` échoue), approbateur ≠ demandeur, circuit distinct du paiement, trop-perçu comme crédit avec décision explicite (aujourd'hui le surpaiement est simplement refusé) | **bloquant** | M |
| FR-FIN-05 | `ABSENT` | un reçu existe pour la **paie** (`payroll/receipt.blade.php`, route enseignant) : modèle réutilisable | reçu de paiement élève, numérotation `REC-{année}-{séquence 6}` unique par tenant (`AC1`), contenu figé, duplicata, langue configurable (`AC2` dépend de `FR-PDF-01`) | **bloquant** | M |
| FR-FIN-06 | `PARTIEL` | vue `invoice_balances` (payé net, solde), index `(tenant, due_on, id)`, `finance/summary` expose `outstanding_minor`, page Finance | listes par élève/programme/groupe/période, calcul du retard, notifications J-3/J/J+7, export contrôlé, refus d'accès enseignant explicite (`AC2`), rapprochement `AC1` non testé | majeur | M |
| FR-FIN-07 | `ABSENT` | la paie du mois fige les heures : **le mécanisme de clôture existe par analogie** | `finance_period`, refus de création ou validation datée dans la période, réouverture motivée et approuvée, rapport de rapprochement à la clôture | **bloquant** | M |

#### Module DEP — Dépenses

| ID | Statut | Preuve | Ce qui manque | Risque | Effort |
|---|---|---|---|---|---|
| FR-DEP-01 | `ABSENT` | `expenses(label, amount_minor, incurred_on, payroll_id)` ; `UNIQUE(tenant, payroll_id)` évite structurellement le double comptage des salaires | `expense_category` paramétrable, catégories initiales, catégorie **système `salaries` non saisissable** (`AC1`), libellés traduisibles, `other` exigeant libellé et motif, archivage, imputation à un programme | **bloquant** | M |
| FR-DEP-02 | `PARTIEL` | dépense immutable par déclencheur ; `disbursements` avec plafond vérifié en base, idempotence, moyen et référence ; distinction engagé / payé **structurelle** | `validation_status ∈ draft/submitted/validated/rejected`, `payment_status`, `beneficiary`, `reason`, `program_id`, pièces jointes et permission `expense.attachment.read` (`AC3`), valideur ≠ auteur (`AC1`), seuil direction, filtres et exports | **bloquant** | M |
| FR-DEP-03 | `ABSENT` | — | `expense_reversal` lié avec motif, application de la clôture | **bloquant** | M |

#### Module REM — Rémunérations

| ID | Statut | Preuve | Ce qui manque | Risque | Effort |
|---|---|---|---|---|---|
| FR-REM-01 | `DIVERGENT` | `contracts(basis ∈ monthly\|hourly, rate_minor)`, non-chevauchement et immutabilité en base | `pay_rule(kind ∈ fixed_monthly/hourly/per_session/mixed, monthly_amount, variable_basis, included_quantity, valid_from/valid_to)`, `pay_rule_rate(node_id, unit_amount)`, **résolution du tarif par plus proche ancêtre du référentiel**, restriction `administrative`/`other` → `fixed_monthly` seulement (`AC3`, impossible : `staff_type` n'existe pas), refus d'un tarif unitaire sur `fixed_monthly` (`AC7`), refus d'un `mixed` incomplet (`AC6`), unicité (règle, nœud) (`AC8`), changement au 1er du mois (`AC4`), permission `payroll.rule.manage` distincte, primes récurrentes. **Les 8 critères d'acceptation sont hors d'atteinte** | **bloquant** | L |
| FR-REM-02 | `PARTIEL` / `DIVERGENT` | une rémunération par (personne, mois) garantie par `UNIQUE` + verrou ; relance renvoyant l'existant → **moitié de `TST-12`** ; instantané du calcul en JSONB ; arrondi *half-up* au centime ; refus de calculer s'il reste des heures non traitées ; refus explicite du mois incomplet (`422`, aucune proratisation inventée) ; `AC1` et l'esprit de `AC2` partiellement tenus | **état `draft` absent : la rémunération est validée à sa création** (constat T2) ; tarifs par niveau, `per_session`, `mixed`, quantité incluse, ordre chronologique des unités incluses, primes et ajustements, proratisation paramétrable, ligne « tarif manquant » bloquante, dérivation depuis les séances pointées, crédit du remplaçant. **`AC3` à `AC13` non satisfaits** | **bloquant** | L |
| FR-REM-03 | `ABSENT` | `payroll_runs.calculation` (JSONB) conserve contrat, base, taux, minutes et règle d'arrondi | `payroll_line` (séance, niveau, quantité, tarif appliqué, part fixe, part variable), `payroll_adjustment` (montant ±, motif, auteur), **validation par `payroll.run.validate` ≠ auteur** (`AC1`), garantie que modifier la règle ne change pas un calcul validé (`AC2`) | **bloquant** | M |
| FR-REM-04 | `PARTIEL` | le versement crée sa dépense liée **dans la même transaction**, plafond vérifié en base, idempotence, `UNIQUE(tenant, payroll_id)` : les dépenses `salaires` du mois **ne peuvent pas** doubler les versements → esprit de `AC2` satisfait structurellement | états `partially_paid`/`paid` (`AC1` échoue, il n'y a pas d'état), montants à payer/versés/restants par personne et période, correction par contre-opération, catégorie `salaries` explicite (dépend de `FR-DEP-01`) | majeur | M |
| FR-REM-05 | `PARTIEL` | portail `/enseignant/remuneration` : heures validées, calcul, versements, reçu ; limité à la fiche de l'enseignant authentifié, sous module `payroll` | bascule tenant `payroll.self_view` | mineur | S |

#### Module PLN — Planification manuelle

| ID | Statut | Preuve | Ce qui manque | Risque | Effort |
|---|---|---|---|---|---|
| FR-PLN-01 | `ABSENT` | — | `closure(date_from, date_to, reason, scope)`, `time_slot`, durée de séance par défaut, jours ouverts dimanche–jeudi, priorité des exceptions datées (`AC1`) | **bloquant** | M |
| FR-PLN-02 | `PARTIEL` | `sessions(cohort, subject, teacher, room, starts_at, ends_at, status ∈ planned/published/cancelled)`, intervalles semi-ouverts documentés, matière validée contre le programme du niveau, enseignant habilité | `recurrence` et génération de séances (`AC1`), statuts `delivered` et `replaced`, `version_id`, `locked`, édition série / occurrence (`AC2`), **suivi de volume** planifié / assuré / restant | **bloquant** | L |
| FR-PLN-03 | `PARTIEL` | `guard_session` refuse en base : chevauchement enseignant, salle, groupe **et élève inter-programmes**, enseignant inactif, salle d'un autre campus ou de capacité insuffisante ; verrou consultatif ; 2 tests → **`AC2` satisfait, `AC1` partiellement (course non explicitement testée)** | contrainte d'exclusion `tstzrange` exigée par la §7.3 (le code utilise déclencheur + verrou — voir ND-05), vérification des indisponibilités et fermetures, avertissements (bornes horaires, dépassement de volume, temps de déplacement) | **bloquant** | M |
| FR-PLN-04 | `PARTIEL` | page Planning, vue planning enseignant filtrée par son identifiant, emplois du temps HTML/PDF statiques dans `docs/` | vues par groupe, salle, élève ; **export ICS** avec fuseau Africa/Algiers (`AC1`) ; export PDF par le service commun ; RTL ; exceptions datées reflétées | majeur | M |
| FR-PLN-05 | `PARTIEL` | statut `published` par l'API de transition avec motif obligatoire, consigné dans l'`outbox` | `timetable_version`, comparaison de versions, permission `schedule.timetable.publish`, refus de publier avec conflit bloquant (`AC1`), **notification uniquement après publication** (`AC2`) | majeur | M |
| FR-PLN-06 | `PARTIEL` | annulation par transition avec motif obligatoire | **remplacement inexistant** : pas de `replacement_teacher_id`, pas de statut `replaced`, pas de recontrôle d'habilitation et de disponibilité du remplaçant (`AC1`), pas de notification, pas d'historique de groupe (`AC2`). Bloque `FR-REM-02 AC13` | **bloquant** | M |

#### Module PTG — Pointage et présences

| ID | Statut | Preuve | Ce qui manque | Risque | Effort |
|---|---|---|---|---|---|
| FR-PTG-01 | `DIVERGENT` | `work_logs(contract_id, worked_on, minutes)` : heures **déclarées par jour**, unicité par (contrat, jour), hors contrat refusé, saisie manuelle uniquement | rattachement **à la séance** (`session_id`), `status ∈ present/late/absent/replaced`, `late_minutes`, `actual_start`/`actual_end`, `replacement_teacher_id`, `entered_by`/`entered_at`, état « non pointé » distinct d'« absent » (`AC1`), refus de pointer une séance future (`AC2`), limite J+N paramétrable | **bloquant** | M |
| FR-PTG-02 | `PARTIEL` / `DIVERGENT` | `validated_at` / `rejected_at` / `review_reason` avec `CHECK` d'exclusion mutuelle ; gel après validation **testé** ; la paie refuse de calculer tant qu'il reste des heures non traitées ; `UPDATE` limité aux colonnes autorisées | **identité du valideur absente, donc `BR-08` et `AC1` inexprimables** (constat T3) ; validation par lot ; notification de rejet ; validation par période | **bloquant** | M |
| FR-PTG-03 | `PARTIEL` | minutes validées sommées par contrat et mois ; gel ; la paie du mois ferme la période (`payroll_already_closed`, testé) | dérivation depuis les **séances validées**, crédit du remplaçant, séance annulée = 0 h, `teacher_hours_adjustment` (ancienne valeur, nouvelle valeur, motif, auteur) (`AC2`), rapprochement `AC1` non testé | **bloquant** | M |
| FR-PRE-01 | `PARTIEL` | `attendance(session, enrollment, status ∈ present/absent/late/excused, reason)` ; unicité par (séance, inscription) ; groupe erroné refusé en base ; correction exigeant un motif ; **appel non saisi ≠ absence** (absence de ligne) ; audit ; 3 tests | liste des élèves affectés **à la date de la séance** (`AC1`, impossible sans `group_membership` daté), seuils de notification, notification aux responsables, restriction « un enseignant n'appelle que ses séances » côté portail | **bloquant** | M |

#### Module TDB — Tableaux de bord

| ID | Statut | Preuve | Ce qui manque | Risque | Effort |
|---|---|---|---|---|---|
| FR-TDB-01 | `PARTIEL` | `finance/summary` : facturé, encaissé, remboursé, solde ouvert, dépenses engagées, versées ; pages Finance et Analytics ; **absence de double comptage garantie structurellement** (`AC2` satisfait par conception) | revenus facturés **par mois d'exigibilité** (dépend des échéances), encaissements nets déduisant les contre-opérations, charges salariales distinguant engagé et payé, **résultat net de gestion** avec convention affichée, **rentabilité par programme** avec méthode de répartition, retour aux opérations sources, période/filtres/date de mise à jour/base de calcul affichés, rapprochement `AC1` non testé | majeur | M |
| FR-TDB-02 | `PARTIEL` | `dashboard/overview` : effectifs élèves/classes/inscriptions/enseignants/personnel/en attente, classes et élèves par programme, 4 prochaines séances — **chaque bloc conditionné à une permission** | taux de remplissage effectif/capacité (`AC1`), conversion CRM, absences, taux de pointage validé, séances non pointées, filtres temporels, date de mise à jour, portée par rôle | majeur | M |
| FR-TDB-03 | `ABSENT` | — | chiffres cliquables vers les opérations sources, exports respectant les permissions, cache avec invalidation sur événement | mineur | M |

#### Lots 5 à 10 (cadrés)

| ID | Statut | Preuve / commentaire |
|---|---|---|
| FR-LMS-01 | `PARTIEL` | `lesson_versions(subject, title, version, body, published_at)` + publication par transition + page Leçons. Manque : chapitres, objectifs, prérequis, pièces jointes, ciblage par groupe, prévisualisation élève, conditionnement par `access_granted` |
| FR-LMS-02 | `ABSENT` | — |
| FR-LMS-03 | `ABSENT` | — |
| FR-LMS-04 | `PARTIEL` | **socle solide** : `assessments(max_score, coefficient, kind, held_on, published_at)`, `grades(status, score, reason)` avec note ≤ barème, publication gelant les notes, correction exigeant un motif, note manquante ≠ zéro, saisie enseignant, 5 tests. Manque : règles d'arrondi et de passage **versionnées**, validation avant publication, exports |
| FR-LMS-05 | `ABSENT` | — |
| FR-EDT-01…05 | `ABSENT` | page Optimisation = simulation explicitement déclarée. Les scripts Python de génération d'emplois du temps sont des outils figés pour un établissement : utilisables comme jeux d'essai, pas comme moteur |
| FR-IAI-01…05 | `ABSENT` | page Analytics = simulation déclarée, aucun score inventé (choix sain) |
| FR-STK-01…04 | `ABSENT` | — |
| FR-POR-01 | `ABSENT` | le portail **enseignant** existe et constitue un modèle réutilisable (intergiciel de portée, authentification séparée, vues limitées). Aucun portail parent ni élève |
| FR-DED-01 | `PARTIEL` → quasi conforme | `Dockerfile`, `compose.yaml`, un fichier d'environnement par école, `MODULES.md`, `DOCKER.md`, `Start-SchoolDemo.ps1`, conteneur de sauvegarde, table `deployment` épinglant l'établissement. **Le lot 9 est le lot le plus avancé du projet.** Manque : images épinglées par version/empreinte |
| FR-DED-02 | `ABSENT` | aucune clé de licence (modules, nombre d'élèves, date), aucun verrouillage en lecture seule à l'expiration |
| FR-DED-03 | `PARTIEL` | `DOCKER.md`, `docs/backups.md`, migration initiale refusant volontairement un retour arrière destructif, rapport de restauration dans `tests/artifacts/`. Manque : guide d'exploitation client, migrations réversibles, accès support encadré |

### 3.4 Exigences non fonctionnelles

| ID | Statut | Preuve / ce qui manque | Risque | Effort |
|---|---|---|---|---|
| NFR-PERF-01 | `ABSENT` | aucune mesure ; `docs/ARCHITECTURE.md` indique explicitement « ce ne sont pas des performances démontrées » | majeur | M |
| NFR-PERF-02 | `ABSENT` | aucun protocole de charge | majeur | M |
| NFR-PERF-03 | `ABSENT` | aucun test multi-tenant à l'échelle (et impossible avant ARC-02) | majeur | M |
| NFR-PERF-04 | `PARTIEL` | pagination **par curseur** plafonnée à 100 (excellent) ; défaut à 50 au lieu de 25 ; aucun traitement de fond pour les gros volumes | mineur | S |
| NFR-PERF-05 | `PARTIEL` | pas de rechargement complet (Livewire/Alpine), polices servies localement ; poids non mesuré | mineur | S |
| NFR-SEC-01 | `PARTIEL` | `SESSION_SECURE_COOKIE` paramétrable, `sslmode=require` par défaut côté base ; la composition fournie désactive TLS (développement) ; **aucun HSTS**, `SameSite` non explicité | majeur | S |
| NFR-SEC-02 | `DIVERGENT` | **bcrypt** (défaut Laravel) et non **Argon2id** ; secrets hors du dépôt (le `.env` présent ne contient aucun secret) | **bloquant** | S |
| NFR-SEC-03 | `PARTIEL` | CSRF actif sur toutes les mutations, requêtes paramétrées, le seul `whereRaw` filtre les noms de champs par `^[a-z_]+$`, échappement Blade. **Aucune CSP**, pas de téléversement, pas de neutralisation de formules | majeur | M |
| NFR-SEC-04 | `PARTIEL` | contrôle par ressource côté serveur sur **chaque** action (~40 appels `authorize()`) ; aucun test automatisé route × matrice de permissions (`TST-02`) | **bloquant** | M |
| NFR-SEC-05 | `PARTIEL` | `throttle:school-api`, `throttle:school-login`, `throttle:6,1` ; pas de verrouillage progressif par compte et par IP | majeur | S |
| NFR-SEC-06 | `PARTIEL` | erreurs API sans SQL ni données personnelles ; audit limité aux noms de champs ; non testé | majeur | S |
| NFR-SEC-07 | `ABSENT` | aucune journalisation de sécurité dédiée, aucune alerte | majeur | M |
| NFR-SEC-08 | `ABSENT` | **aucune intégration continue** (pas de `.github/workflows`), donc aucune analyse de dépendances | majeur | S |
| NFR-SEC-09 | `PARTIEL` | sauvegardes `pg_dump` + archive de fichiers + empreintes SHA-256 + rétention ; **les sauvegardes ne sont pas chiffrées** (le contrôle de clé porte sur `APP_KEY`, pas sur l'archive) | majeur | S |
| NFR-TEN-01 | `CONFORME` | RLS `ENABLE`+`FORCE` sur toutes les tables métier ; `school_runtime` `NOSUPERUSER NOBYPASSRLS`, non propriétaire, sans `DELETE` ni écriture d'adhésion ; vues `security_invoker` | — | — |
| NFR-TEN-02 | `PARTIEL` | 7 tests d'isolation au niveau SQL (lecture croisée, écriture croisée, clé étrangère croisée, adhésion inactive, tenant manquant, non-membre, remise à zéro au rollback) ; **non couvert : interface, API, exports, fichiers, tâches, notifications, recherche** | **bloquant** | M |
| NFR-TEN-03 | `PARTIEL` | préfixe Redis global `madrassa_`, non segmenté par tenant ; sans objet aujourd'hui (une base par déploiement), bloquant dès ARC-02 | majeur | S |
| NFR-AVL-01 | `ABSENT` | aucun engagement, aucune mesure | majeur | — |
| NFR-REC-01 | `PARTIEL` | sauvegarde quotidienne planifiée, base **et** fichiers, rétention configurable, rattrapage au démarrage ; **pas de chiffrement**, pas de copie séparée documentée, RPO/RTO non éprouvés | majeur | M |
| NFR-REC-02 | `PARTIEL` | procédure de restauration refusant une cible non vide, vérification des empreintes, `tests/artifacts/backup-restore-report.json` ; pas de test périodique automatisé | majeur | S |
| NFR-REC-03 | `ABSENT` | décision de la finance à obtenir (processus) | mineur | — |
| NFR-OPS-01 | `PARTIEL` / `DIVERGENT` | environnements séparables par fichier d'environnement ; migrations en SQL explicite ; **la migration initiale refuse volontairement un retour arrière**, ce qui contredit « migrations avec retour arrière » ; aucune supervision, aucun identifiant de corrélation | **bloquant** | M |
| NFR-DATA-01 | `PARTIEL` | aucune dépendance à un service cloud propriétaire, tout auto-hébergeable ; la résidence reste un choix d'hébergement non contractualisé (OD-04) | majeur | — |
| NFR-DATA-02 | `ABSENT` | aucune base légale, aucun registre de consentement, aucun contrat de sous-traitance (OD-08) | **bloquant** avant premier client | M |
| NFR-DATA-03 | `ABSENT` | aucune durée de conservation, aucune purge, aucune anonymisation (OD-07) | **bloquant** | M |
| NFR-DATA-04 | `ABSENT` | aucun export complet de tenant, aucune procédure de rectification | majeur | M |
| NFR-DATA-05 | `PARTIEL` | les sauvegardes suivent le stockage mais ne sont pas chiffrées | majeur | S |
| NFR-DATA-06 | `PARTIEL` | les jeux de démonstration sont fictifs ; aucune règle formalisée | mineur | S |
| NFR-UX-01 | `PARTIEL` | navigation Filament pilotée par les modules et les permissions, libellés métier français explicites | mineur | M |
| NFR-UX-02 | `PARTIEL` | captures mobiles et bureau dans `tests/artifacts/` ; pas de recette systématique | mineur | M |
| NFR-UX-03 | `ABSENT` | WCAG 2.1 AA non évaluée | majeur | M |
| NFR-UX-04 | `PARTIEL` | **bonne pratique déjà en place** : messages métier précis et actionnables pour les conflits ([TenantContext.php:47-51](madrassa-backend/app/School/Http/TenantContext.php:47)) ; généralisation à faire | mineur | M |
| NFR-UX-05 | `PARTIEL` | verrous optimistes par HMAC sur candidatures et heures déclarées ; pas de brouillon ni de reprise de saisie | mineur | M |
| NFR-COMP-01 | `ABSENT` | non mesuré | mineur | S |
| NFR-I18N-01 | `ABSENT` | voir `FR-I18-01` | **bloquant** | L |
| NFR-I18N-02 | `ABSENT` | voir `FR-PDF-01` | **bloquant** | M |
| NFR-I18N-03 | `ABSENT` | repli d'accents latins uniquement, aucune normalisation arabe | **bloquant** | M |
| NFR-PORT-01 | `PARTIEL` | Docker, configuration par environnement, aucun service propriétaire ; **stockage S3 et file de tâches non configurables (inexistants)**, e-mail non configuré | majeur | M |
| NFR-PORT-02 | `CONFORME` | aucune dépendance GPU | — | — |
| NFR-MNT-01 | `ABSENT` | **aucune intégration continue** : ni tests, ni analyse statique, ni vérification de migrations automatisées | **bloquant** | S |
| NFR-MNT-02 | `PARTIEL` | 34 invariants SQL + 2 tests de concurrence (excellents) + 7 tests d'interface portant sur le **mode démonstration** (donc non représentatifs du backend) ; aucun test d'intégration HTTP authentifié | **bloquant** | M |
| NFR-MNT-03 | `CONFORME` | 13 documents backend + 3 frontend : architecture, schéma, API, validation, exploitation, sauvegardes, règles métier. Qualité notable : les limites y sont nommées | — | — |
| NFR-MNT-04 | `PARTIEL` | migrations horodatées et explicites ; profils et paramètres de calcul **non versionnés** (codés en dur) | majeur | S |

### 3.5 Couverture des invariants critiques (§9.3)

| ID | Couverture | Preuve | Reste à faire |
|---|---|---|---|
| TST-01 | `PARTIEL` (SQL ✔) | 7 tests : lecture croisée, écriture croisée, clé étrangère croisée, adhésion inactive, tenant absent, non-membre, remise à zéro | étendre à interface, API, exports, fichiers, tâches, notifications, recherche |
| TST-02 | `ABSENT` | — | test paramétré route × permission × portée ; test de recherche statique prouvant l'absence de nom de rôle dans la logique métier (**structurellement vrai aujourd'hui**, donc facile) |
| TST-03 | `PARTIEL` | portée enseignant appliquée dans le portail | tests ; aucun périmètre élève (pas de portail) |
| TST-04 | `PARTIEL` | API 404 et menus masqués si module inactif | 403 + code `module_disabled`, granularité tenant, tâches non exécutées, tests |
| TST-05 | `ABSENT` | — | **tout** : règles SoD, identité du valideur en base, cas du cumul de rôles |
| TST-06 | `PARTIEL` | « audit enregistré sans instantané complet de données personnelles », immutabilité | une entrée par événement de `BR-09`, avec valeurs avant/après et motif |
| TST-07 | `CONFORME` | « capacity enforced » + « concurrent enrollment cannot consume same last seat » | revalider après `group_membership` ; porter à N=30 requêtes |
| TST-08 | `PARTIEL` | clé d'idempotence unique + `replay()` comparant le contenu | test explicite de double soumission d'inscription |
| TST-09 | `PARTIEL` | « payment never reactivates suspended enrollment », « net balance includes refunds » | dimension financière nommée, transition `unpaid`→`partially_paid`→`paid` |
| TST-10 | `CONFORME` (paiement) | « concurrent payments cannot overpay », clé unique, immutabilité | unicité de `reference` et de numéro de reçu |
| TST-11 | `CONFORME` | « payments immutable », « price snapshot frozen », « published grades frozen », « validated hours frozen » | étendre aux dépenses et lignes de paie validées lorsqu'elles existeront |
| TST-12 | `PARTIEL` | `UNIQUE(tenant, staff, month)` + verrou + relance renvoyant l'existant | versements : test de non-duplication |
| TST-13 | `PARTIEL` | garantie **structurelle** par `UNIQUE(tenant, payroll_id)` | test de rapprochement « dépenses `salaries` du mois = versements du mois » |
| TST-14 | `ABSENT` | — | tout (dépend de `FR-IMP-01`) |
| TST-15 | `PARTIEL` | « schedule overlap rejected », « cross-program student timetable collision rejected », « adjacent sessions allowed » | cas concurrent explicite ; contrainte d'exclusion |
| TST-16 | `ABSENT` | — | tout (dépend de `FR-TDB-01`) |
| TST-17 | `ABSENT` | PDF produits par Chromium sans garantie de rendu arabe | tout |
| TST-18 | `PARTIEL` | matière et niveau sont des clés étrangères, matière validée contre le programme du niveau | l'élève n'a **aucune** référence de niveau ; champs libres résiduels ; archivage ; doublons normalisés |
| TST-19 | `ABSENT` | — | tout (dépend de `FR-REM-01/02`) |

---

## 4. Synthèse quantitative

### 4.1 Par statut (93 exigences évaluées, lots 1 à 4 et transverses)

| Statut | Nombre | Part |
|---|---|---|
| `CONFORME` | 9 | 10 % |
| `PARTIEL` | 41 | 44 % |
| `ABSENT` | 31 | 33 % |
| `DIVERGENT` | 12 | 13 % |

Hors périmètre identifié dans le code : **4 ensembles** (bulletins, passage annuel, validateurs d'emploi du temps figés, mode démonstration) ≈ 2 300 lignes.

### 4.2 Par lot du SRS

| Lot | Exigences | Conformes | Partielles | Absentes/Divergentes | Maturité estimée |
|---|---|---|---|---|---|
| **1 — Fondations** | 19 | 0 | 7 | 12 | **≈ 20 %** — le socle d'isolation et d'audit est excellent, tout le reste (console, plans, IAM fin, référentiel générique, i18n, tâches, fichiers, notifications, import/export, PDF) est à construire |
| **2 — Cœur scolaire** | 17 | 1 | 13 | 3 | **≈ 45 %** — la zone la plus avancée, mais `group_membership` et le référentiel générique imposent une refonte |
| **3 — Finance** | 15 | 0 | 8 | 7 | **≈ 30 %** — invariants d'immutabilité et d'idempotence excellents, mais échéanciers, validations, contre-opérations, catégories et règles de rémunération sont absents ou divergents |
| **4 — Exploitation** | 11 | 0 | 9 | 2 | **≈ 35 %** — détection de conflits remarquable, mais calendrier, récurrences, remplacements, pointage par séance et tableaux de bord rapprochés manquent |
| **5 à 10** | 23 | 0 | 3 | 20 | **≈ 8 %** — sauf le lot 9 (mode dédié), le plus avancé du projet |

### 4.3 Les 12 divergences, par décision à prendre

| Divergence | Décision recommandée |
|---|---|
| Pile Laravel ≠ Django (`ARC-01`, §4.2) | **Amender le SRS** → ND/OD-01 |
| Montants entiers en centimes ≠ `NUMERIC(14,2)` (`ARC-11`) | **Amender le SRS** → ND-01 |
| Runtime mono-tenant (`ARC-02`) | **Aligner le code** → chantier W1.1 |
| Entitlements par environnement (`ARC-04`, `BR-20`) | **Aligner le code** → chantier W1.3 |
| Permissions grossières (`FR-IAM-03`) | **Aligner le code** → chantier W1.4 |
| Référentiel à hiérarchie fixe (`FR-REF-01`) | **Aligner le code** → chantier W1.5 |
| `periods` à un seul niveau (`FR-REF-05`) | **Aligner le code** → chantier W1.5 |
| Pas de `group_membership` (`FR-GRP-02`) | **Aligner le code** → chantier W2.5 |
| Contrat portant le montant (`FR-STF-04`, `FR-REM-01`) | **Aligner le code** → chantier W3.4 |
| Remboursement servant de correction (`FR-FIN-03/04`) | **Aligner le code** → chantier W3.2 |
| Heures déclarées par jour et non par séance (`FR-PTG-01`) | **Aligner le code** → chantier W4.3 |
| PDF par impression navigateur (`FR-PDF-01`) | **Aligner le code** → chantier W1.6 |

**Dix divergences sur douze doivent être corrigées dans le code ; deux seulement justifient un amendement du SRS.** C'est un bon ratio : il signifie que le code n'a pas dérivé par facilité mais par périmètre (un établissement unique, livré vite).

---

## 5. Constats techniques à traiter (hors écart fonctionnel)

Défauts et incohérences relevés pendant la lecture, indépendants du plan par lot. À traiter tôt : ils coûtent peu et évitent des surprises.

| # | Constat | Preuve | Gravité | Correction proposée |
|---|---|---|---|---|
| T1 | **L'API de suppression ne peut pas fonctionner en mode backend.** `SchoolController::delete()` exécute un `DELETE`, mais `runtime-grants.sql` n'accorde `DELETE` sur **aucune** table sauf `teacher_qualifications`. Toute suppression échoue en `42501`, traduite en 409 générique « Opération incompatible… ». | [SchoolController.php:187](madrassa-backend/app/School/Http/SchoolController.php:187) vs [runtime-grants.sql:31](madrassa-backend/deploy/runtime-grants.sql:31) | **majeur** (fonction annoncée, inopérante, message trompeur) | Remplacer la suppression par l'**archivage** attendu par `BR-02`, et n'accorder `DELETE` que sur les tables de configuration jamais référencées |
| T2 | **La rémunération s'auto-valide à sa création.** `payroll()` insère `validated_at => now()`. Il n'existe donc ni brouillon révisable, ni valideur distinct. | [SchoolService.php:120](madrassa-backend/app/School/Services/SchoolService.php:120) | **bloquant** (`BR-08`, `FR-REM-02 AC2`, `FR-REM-03 AC1`, `TST-05`) | Introduire `status ∈ draft/validated/...`, `validated_by`, et un contrôle SoD |
| T3 | **La validation des heures n'enregistre aucun valideur.** `work_logs` n'a que `validated_at`/`rejected_at`/`review_reason`. | [001_school.sql:484-495](madrassa-backend/database/schema/001_school.sql:484), migration `2026_10_05` | **bloquant** (`BR-08`, `FR-PTG-02 AC1`, `TST-05`) | Ajouter `entered_by`, `validated_by`, et un `CHECK`/déclencheur imposant `validated_by <> entered_by` |
| T4 | **Le tarif salarial est lisible avec `hr.read`.** Le montant étant porté par `contracts`, le responsable pédagogique et RH voit les montants, ce que `FR-STF-04 AC1` interdit. | [school_backend.php:46](madrassa-backend/config/school_backend.php:46) | **majeur** (confidentialité salariale) | Déplacer les montants vers `pay_rule` sous `payroll.rule.manage` / `payroll.amount.read` |
| T5 | **Le remboursement est détourné en correction.** La documentation du projet le signale : « Un remboursement rend cette somme de nouveau exigible dans ce modèle : il ne constitue pas un avoir. » | `docs/ARCHITECTURE.md` §Transactions | **bloquant** (`BR-03`, `FR-FIN-03 AC2`) | Séparer `payment_reversal` (contre-opération) et `refund_request`+`refund` (circuit approuvé) |
| T6 | **`payments.reference` est nullable et non unique.** `FR-FIN-02 AC3` exige une référence unique et non réutilisable ; `FR-FIN-05 AC1` exige des numéros de reçu non collisionnables. | [001_school.sql:315](madrassa-backend/database/schema/001_school.sql:315) | **majeur** | `UNIQUE(tenant_id, reference)` + séquence de reçu par tenant et par année |
| T7 | **Une inscription exige un groupe dès sa création** (`cohort_id NOT NULL`), alors que `FR-INS-01` crée l'inscription puis affecte le groupe, et que certains programmes n'ont pas de groupe. | [001_school.sql:266](madrassa-backend/database/schema/001_school.sql:266) | **bloquant** (`FR-INS-01`, `FR-GRP-02`) | Introduire `group_membership` et rendre l'affectation postérieure et datée |
| T8 | **Des codes métier algériens sont codés en dur dans un service génér­ique.** `TeacherEligibility` teste `upper(p.code)='PRIMAIRE'` et les codes `PRI-FR`, `PRI-EN`, `PRI-ES`, `PRI-SPORT`, `PRI-AR-LECT` pour appliquer une règle de polyvalence. | [TeacherEligibility.php:14-24](madrassa-backend/app/School/Services/TeacherEligibility.php:14) | **majeur** (`BR-13`, généricité école/centre) | Exprimer la polyvalence comme une habilitation sur un **nœud parent** (`FR-STF-02 AC2`), et sortir les codes vers les données de profil |
| T9 | **Audit sans valeurs.** Le déclencheur n'enregistre que les **noms** des champs modifiés ; `FR-AUD-01 AC1` exige ancienne valeur, nouvelle valeur et motif. `actor_id` est nul hors requête HTTP. | [001_school.sql:740-748](madrassa-backend/database/schema/001_school.sql:740) | **bloquant** (`BR-09`, `TST-06`) | Ajouter `before`/`after` JSONB avec liste de champs sensibles masqués, `reason`, `ip`, `request_id` ; prévoir un acteur « système » pour les tâches |
| T10 | **Aucun `archived_at` ni statut `archived` dans tout le schéma**, alors que `BR-02` et §7.1 l'exigent sur toute table métier. | `001_school.sql` | **majeur** | Ajouter `archived_at` (+ `version`, `created_by`, `updated_by` de la §7.1) aux tables métier |
| T11 | **Aucune intégration continue.** Les 34 tests d'invariants existent mais rien ne les exécute automatiquement. | absence de `.github/workflows` | **bloquant** (`NFR-MNT-01`) | Chaîne CI : tests SQL, tests PHP, analyse statique, vérification des migrations |
| T12 | **Pas de file de tâches.** `QUEUE_CONNECTION=sync` : aucun substrat pour imports, exports, PDF en lot, paie en masse, optimisation, IA. | [compose.yaml:30](compose.yaml:30) | **bloquant** (`ARC-06`, `FR-JOB-01`) | File Redis + table de tâches + ouvrier avec contexte tenant vérifié |
| T13 | **`enrollments.status` vaut `validated` par défaut** et `enroll()` ne le surcharge pas : une inscription naît validée, sans passer par la validation habilitée de `FR-INS-01` étape 6. | [001_school.sql:267](madrassa-backend/database/schema/001_school.sql:267), [SchoolService.php:72](madrassa-backend/app/School/Services/SchoolService.php:72) | **majeur** | Défaut `pending` + permission `enrollment.enrollment.validate` |
| T14 | **`code` est modifiable** par l'API de mise à jour sur programmes, niveaux, matières, personnel et élèves, alors que `BR-18` exige des codes immuables (et `FR-ELV-01` un matricule immuable). | `school_backend.php` catalogue + `SchoolController::update()` | **majeur** | Retirer les codes des règles de mise à jour ou poser un déclencheur de gel |
| T15 | **Le mode démonstration réimplémente les règles métier en session, avec des flottants pour les montants.** Risque de dérive entre `DemoStore` et les services réels — déjà réalisé. | [DemoStore.php:19-23](ecole-filament/app/Support/DemoStore.php:19) | **majeur** (dette de maintenance, source de confusion) | ND-04 : étiqueter clairement en bac à sable d'interface non normatif, ou supprimer |
| T16 | **Le module inactif renvoie 404**, alors que `FR-PLT-02 AC2` exige **403** avec le code `module_disabled`. | [TenantContext.php:29-30](madrassa-backend/app/School/Http/TenantContext.php:29) | mineur | Aligner le code et la charge utile d'erreur |
| T17 | **Pagination par défaut à 50**, `NFR-PERF-04` demande 25. | [SchoolController.php:63](madrassa-backend/app/School/Http/SchoolController.php:63) | mineur | Passer le défaut à 25 (plafond 100 déjà conforme) |
| T18 | **Sauvegardes non chiffrées.** Le contrôle de clé porte sur `APP_KEY`, pas sur l'archive produite. | [backup.py:123](docker/backup/backup.py:123) | majeur (`NFR-SEC-09`, `NFR-DATA-05`) | Chiffrer l'archive et documenter la gestion de clé |

---

## 6. Plan de développement

### 6.1 Principes retenus

1. **Ne jamais réécrire la couche d'invariants.** Toute évolution de schéma s'ajoute à `001_school.sql` par migration, en conservant déclencheurs, politiques RLS et clés composites. Chaque migration ajoute ses tests dans `database.mjs`.
2. **Traiter les invariants critiques en premier, lot par lot** (§0.5 du SRS). Dans un lot, aucun écran n'est développé avant que l'invariant correspondant soit vert.
3. **Un lot est livrable et testable seul** (§0.3) et ne casse aucun lot précédent : chaque chantier se termine par l'exécution complète de la suite d'invariants, pas seulement de ses nouveaux tests.
4. **L'internationalisation est un chantier du lot 1, pas une finition.** Chaque écran écrit après le lot 1 naît bilingue ; les écrans existants sont reprisés une seule fois, au lot 1. Repousser ce chantier multiplie son coût par le nombre d'écrans ajoutés entre-temps.
5. **Les divergences se tranchent avant le code**, dans le lot 0. Dix d'entre elles sont des refontes de schéma : les découvrir en cours de lot coûte un cycle de migration de données.
6. **Les estimations sont des fourchettes pour une équipe de 2 à 3 développeurs**, hors recette client et hors chantiers juridiques (OD-07, OD-08). Elles supposent le maintien de la pile actuelle.

### 6.2 Lot 0 — Décisions et outillage (préalable, 2 à 3 semaines)

Rien de fonctionnel n'est livré ; tout le reste en dépend.

| Réf. | Travail | Effort |
|---|---|---|
| 0.1 | **Trancher les décisions ouvertes bloquantes** : OD-01 (pile → maintenir Laravel, amender §4.2), ND-01 (montants entiers en centimes → amender `ARC-11`), ND-02 (sort du sous-système Bulletins), ND-03 (sort du passage annuel), ND-04 (sort du mode démonstration), ND-05 (contrainte d'exclusion `tstzrange` **en complément** du déclencheur), ND-06 (modèle de déploiement cible : SaaS mutualisé **et** dédié, le code partagé) | S |
| 0.2 | **Publier le SRS 1.2** avec les amendements §4.2, `ARC-11`, et l'arbitrage des éléments hors périmètre trouvés dans le code | S |
| 0.3 | **Intégration continue** (T11, `NFR-MNT-01`) : exécuter `database.mjs` + `concurrency.mjs` sur une base jetable, exécuter la suite PHP, analyse statique, vérification que chaque migration s'applique et se vérifie | S |
| 0.4 | **Socle de tests d'intégration HTTP authentifiés** : fabriques de tenant, d'utilisateur, d'adhésion et de permissions ; base du test route × permission (`TST-02`) | M |
| 0.5 | **Corrections à faible coût et fort rendement** : T16 (403 `module_disabled`), T17 (pagination 25), T14 (codes immuables), T1 (suppression → archivage ou privilèges cohérents) | S |

**Porte de sortie :** SRS 1.2 validé, CI verte sur les 34 invariants existants, premier test d'intégration authentifié passant.

### 6.3 Lot 1 — Fondations (3 à 3,5 mois)

Le lot le plus lourd : il transforme un déploiement dédié en plateforme.

| Réf. | Chantier | Contenu | Dépend de | Effort |
|---|---|---|---|---|
| **W1.1** | **Runtime multi-tenant** (`ARC-02`, `ARC-03`) | Retirer l'épinglage `DeploymentSchool` du chemin SaaS en le conservant comme **mode de déploiement dédié** (ND-06) ; résoudre le tenant depuis la session authentifiée (et/ou le sous-domaine), jamais depuis un paramètre client ; préfixer cache, sessions, files et stockage par tenant (`NFR-TEN-03`) ; garantir « un tenant a au moins un site » | 0.1 | M |
| **W1.2** | **Console éditeur** (`FR-PLT-01`, `FR-PLT-04`, `FR-PLT-05`) | `tenant(slug immuable, status, default_locale)`, création avec site par défaut + profil + invitation du premier administrateur, suspension révoquant les sessions, archivage en lecture seule, idempotence de création ; `support_access_grant` (consentement, ≤ 72 h, lecture seule, exclusion salariale, journalisation **dans le journal du tenant**, bannière) ; tableau de bord éditeur agrégé | W1.1, W1.4 | L |
| **W1.3** | **Entitlements par tenant** (`FR-PLT-02`, `ARC-04`, `BR-20`) | `plan`, `tenant_entitlement(module_key, quotas, valid_from, valid_until, grace_days)` ; **réutiliser `ModuleRegistry`** en changeant sa source de vérité de l'environnement vers la base ; blocage d'inscription au dépassement de quota sans bloquer consultation ni paiements ; lecture seule après grâce ; 403 `module_disabled` ; invalidation de cache au changement | W1.1 | M |
| **W1.4** | **IAM** (`FR-IAM-01` à `05`) | `permission_catalog` **peuplé depuis l'annexe A** avec marquage `sensitive` ; `role`, `role_permission`, `role_assignment(scope, valid_from, valid_to)` ; les 11 rôles prédéfinis **semés et copiés par tenant** ; cumul = union ; portées (tenant, site, niveau, groupe, matière) + portées personnelles implicites ; `sod_rule` + contrôle `approved_by ≠ created_by` ; cycle de vie des comptes (`invited/active/suspended/revoked`, lien 72 h, protection du dernier administrateur) ; **Argon2id** (T-SEC-02), MFA TOTP + codes de récupération, verrouillage progressif, politique de session. **Migrer les 16 permissions actuelles vers le catalogue fin en conservant les ~40 points d'appel `authorize()`** | 0.4 | L |
| **W1.5** | **Référentiel générique** (`FR-REF-01` à `06`) | `node_type` + `ref_node(parent_id, type, code, labels, sort_order, status, platform_code)` ; **migration de données** `programs`+`levels` → `ref_node` avec vues de compatibilité le temps de la transition ; `subject(kind, labels, status)` ; unicité **normalisée** (casse, espaces, diacritiques arabes) ; `academic_year` + `period` avec unicité de l'année `current`, non-chevauchement, clôture ; `custom_field_def` ; écran de paramètres d'établissement (identité, logo, langues, jours ouverts, créneaux, politiques, seuils) ; **transformer `algerian_curriculum.php` en profil `private_school_dz` versionné en données**, ajouter les libellés arabes (OD-13) et le profil `training_center` | W1.1 | L |
| **W1.6** | **Services transverses** (`FR-JOB-01`, `FR-FIC-01`, `FR-NOT-01`, `FR-IMP-01/02`, `FR-PDF-01`, `FR-AUD-01`) | File Redis + table `job` (états, progression, erreur compréhensible, relance, annulation, idempotence, **vérification du tenant à l'exécution**) + page « Mes tâches » + ouvrier de l'`outbox` (`FOR UPDATE SKIP LOCKED`, tentatives, retrait après succès) ; `file_object` sur S3/MinIO avec préfixe `tenant/{id}/`, URL signées ≤ 15 min, **vérification de signature de contenu**, types et tailles configurables, 5 catégories de droits, quota ; `notification` + SMTP + préférences + suivi et rejeu des échecs ; moteur d'import (XLSX/CSV, modèles, dépôt serveur, **prévisualisation sans écriture**, `external_ref` idempotent, résolution des références par code, doublons, rapprochement financier, reprise à blanc, retour arrière, rapport de rejets, conservation du fichier) ; moteur d'export (permissions et portées, par domaine, arrière-plan > 2 000 lignes, journalisation, purge 7 j, **neutralisation des formules**) ; **service PDF avec polices arabes embarquées** ; audit enrichi (avant/après, motif, IP, identifiant de requête, opération liée, lecture filtrée par domaine) | W1.1, W1.4 | L |
| **W1.7** | **i18n et RTL** (`FR-I18-01`, `NFR-I18N-*`) | Extraction de **toutes** les chaînes (configurations, validateurs, messages métier, Blade, JS) ; catalogues `fr` et `ar` ; langue par utilisateur avec repli tenant puis `fr` ; **styles logiques** (début/fin) pour un miroir RTL sans duplication ; libellés par langue sur référentiel, catégories, moyens de paiement, rôles ; **champs de nom arabe et latin** sur élèves, responsables et personnel ; normalisation arabe de recherche (alif/hamza, ya/alif maqsura, ta marbuta, diacritiques) + translittération ; formats de dates et de montants localisés ; **test de pseudo-localisation** détectant toute chaîne en dur | — (parallélisable) | L |

**Invariants à rendre verts :** `TST-01` (étendu à interface, API, exports, fichiers, tâches, notifications, recherche), `TST-02`, `TST-03`, `TST-04`, `TST-05`, `TST-06`, `TST-14`, `TST-17`, `TST-18`.
**Porte de sortie (§9.1) :** deux tenants isolés créés de bout en bout ; parcours R01, R02, R09, R10 validés ; revue de sécurité des permissions ; migrations réversibles (sauf la migration initiale, par décision documentée) ; documentation à jour.

### 6.4 Lot 2 — Cœur scolaire (2 à 2,5 mois)

| Réf. | Chantier | Contenu | Effort |
|---|---|---|---|
| **W2.1** | Élèves (`FR-ELV-01` à `04`) | Noms arabe et latin, lieu de naissance, sexe, adresse ; **chaîne de traitement de la photo** (JPEG/PNG/WebP, ≤ 2 Mo, recadrage carré, redimensionnement 1024/128, recompression, **suppression EXIF**, suppression définitive au remplacement, bascule `student.photo.enabled`, avatar par initiales, exclusion des exports) ; statuts `former`/`blocked` + motif ; `origin_school` ; champs personnalisés ; génération du matricule (OD-11) ; verrou optimiste ; avertissement de doublon avec motif obligatoire ; recherche et détection de doublons arabe + latin | L |
| **W2.2** | Responsables (`FR-ELV-02`) | `relationship` énuméré, noms arabe/latin, profession, `is_primary_contact`, `is_payer`, `emergency_contact`, règle du contact principal pour les mineurs | S |
| **W2.3** | CRM amont (`FR-CRM-01`) | `lead`, `lead_interaction`, source et statuts paramétrables, rappels + notification à l'assigné, `assigned_to`, `lost_reason`, anti-doublon téléphone, exclusion des effectifs. **Brancher sur `StudentLifecycle::admit()` existant** (`FR-CRM-02` quasi conforme) | M |
| **W2.4** | Sites et salles (`FR-SLL-01/02`) | Codes, libellés multilingues, statuts, `room_type` et `equipment` paramétrables, **`room_closure` datée** bloquant les séances | S |
| **W2.5** | **Groupes et affectations** (`FR-GRP-01/02`) — *chantier structurant* | `group_type`, `code`, `site_id`, `main_room_id`, `lead_teacher_id`, dates de validité, statuts ; **création de `group_membership(enrollment_id, group_id, valid_from, valid_to, is_main)`** avec index unique partiel `(enrollment_id) WHERE is_main AND valid_to IS NULL` ; migration de `enrollments.cohort_id` vers des affectations ; transfert atomique (clôture source + création cible) ; contrôle de capacité atomique **porté** sur le nouveau modèle ; file d'attente (OD-14) | L |
| **W2.6** | Personnel (`FR-STF-01` à `04`) | `staff_type` énuméré (débloque `FR-REM-01 AC3`), statuts `on_leave`/`left`, date d'embauche, noms arabe/latin ; habilitations **datées** avec **héritage du nœud parent** et sortie des codes codés en dur (T8) ; `availability`, `unavailability`, bornes horaires ; contrats **sans montant** (T4) ; `leave` créant des indisponibilités et listant les séances impactées | L |
| **W2.7** | Programmes et volumes (`FR-PRG-01/02`) | `code`, libellés multilingues, durée, **`price_version`**, `payment_terms`, statuts, gel par année clôturée ; `program_subject(total_hours, weekly_hours, session_count, session_duration_minutes, coefficient, valid_from, valid_to)` **rattaché au programme**, règle « au moins une des trois valeurs », versionnement, retrait par fin de validité, avertissement d'écart > 10 %, programme sans matière valide. **Migration depuis `curriculum` en réutilisant les volumes de `primary_weekly_minutes.json`** | M |
| **W2.8** | Inscriptions (`FR-INS-01` à `04`) | Création en `pending` (T13), validation/suspension/annulation habilitées avec motif, `terms_snapshot`, refus d'un élève bloqué, contrôle de quota, transfert avec `transferred_from`, `enrollment_status_history`, `access_granted` | M |

**Invariants :** `TST-07` (reporté sur `group_membership`, N=30), `TST-08`, `TST-18` complet.
**Porte de sortie :** parcours R03 (élève → programme → inscription → groupe) ; import des 500 élèves de référence ; inscriptions multiples autorisées ; capacité jamais dépassée.

### 6.5 Lot 3 — Finance (2,5 à 3 mois)

| Réf. | Chantier | Contenu | Effort |
|---|---|---|---|
| **W3.1** | Échéanciers et tarification (`FR-FIN-01`) | `installment(enrollment_id, seq, due_date, amount)`, `adjustment(type, amount, reason, approved_by)`, contrôle bloquant « somme des échéances = dû − remises » avec arrondi `BR-04`, motif obligatoire, seuil d'approbation direction (OD-09), modèle d'échéancier du programme, versionnement. **Remplace la facture unique actuelle** (migration de données) | L |
| **W3.2** | Paiements, validation, corrections (`FR-FIN-02/03/04`) | `status ∈ pending_validation/validated/reversed`, `paid_on`, **`reference` unique** (T6), moyens de paiement **paramétrables par tenant**, encaissement sur délégation, option `finance.allow_self_validation` + marque `self_validated` (OD-10), contrôle SoD ; **`payment_reversal` distincte du remboursement** (T5) ; `refund_request` + approbation direction avec approbateur ≠ demandeur ; trop-perçu comme crédit avec décision explicite. **Conserver intégralement les gardes et l'immutabilité existantes** | L |
| **W3.3** | Reçus, impayés, clôture (`FR-FIN-05/06/07`) | Reçu PDF via le service du lot 1, numérotation `REC-{année}-{séquence 6}` par tenant, contenu figé, duplicata, langue du destinataire ; listes d'impayés et de retards par élève/programme/groupe/période avec export contrôlé et notifications J-3/J/J+7 **manuelles ou semi-automatiques** ; `finance_period` avec clôture, réouverture motivée et approuvée, rapport de rapprochement | M |
| **W3.4** | **Règles de rémunération** (`FR-REM-01`) — *chantier structurant* | `pay_rule(kind ∈ fixed_monthly/hourly/per_session/mixed, monthly_amount, variable_basis, included_quantity, valid_from/valid_to)`, `pay_rule_rate(node_id, unit_amount)` ; **résolution du tarif : nœud du programme du groupe → plus proche ancêtre → tarif par défaut** ; restrictions par `staff_type` ; refus des combinaisons invalides (`AC6`, `AC7`, `AC8`) ; changement au 1er du mois ; permission `payroll.rule.manage` distincte ; primes récurrentes. **Migration depuis `contracts.basis/rate_minor` puis retrait du montant du contrat RH** (T4) | L |
| **W3.5** | Génération et détail (`FR-REM-02/03/04/05`) | `payroll_run(status ∈ draft/validated/partially_paid/paid)` recalculable en brouillon, figé à la validation ; `payroll_line` conservant **une copie** du niveau, de la quantité et du tarif appliqués ; `payroll_adjustment` tracé ; ligne **« tarif manquant »** bloquant la validation (`BR-16`) ; proratisation paramétrable de la part fixe ; unités incluses les plus anciennes du mois pour `mixed` ; validation par `payroll.run.validate` ≠ auteur (T2) ; versements liés à la dépense `salaries` avec états. **Conserver le verrou personne/mois, l'unicité et l'instantané existants** | L |
| **W3.6** | Dépenses (`FR-DEP-01/02/03`) | `expense_category` paramétrable avec `salaries` **système non saisissable**, imputation à un programme ; `validation_status` + `payment_status`, bénéficiaire, motif, pièces jointes sous `expense.attachment.read`, valideur ≠ auteur, seuil direction, filtres et exports ; `expense_reversal` lié | M |

**Invariants :** `TST-09`, `TST-10` (reçus inclus), `TST-11` (étendu), `TST-12`, `TST-13`, **`TST-19`** (reproduction au centime des 13 exemples de `FR-REM-02`).
**Porte de sortie :** parcours R04 à R07 ; rapprochement validé par la finance ; reçus arabes rendus correctement.

### 6.6 Lot 4 — Exploitation (1,5 à 2 mois)

| Réf. | Chantier | Contenu | Effort |
|---|---|---|---|
| **W4.1** | Calendrier et séances (`FR-PLN-01/02`) | `closure(scope tenant/site)`, `time_slot`, jours ouverts dimanche–jeudi, priorité des exceptions datées ; `recurrence` + génération de séances excluant fermetures et jours fermés ; statuts `delivered`/`replaced` ; `locked` ; édition série / occurrence ; **suivi de volume** planifié / assuré / restant par groupe et matière, confronté à `program_subject` | L |
| **W4.2** | Conflits, versions, remplacements (`FR-PLN-03/04/05/06`) | **Ajouter la contrainte d'exclusion `tstzrange`** pour enseignant et salle en **complément** du déclencheur existant (ND-05) ; contrôle des indisponibilités et fermetures ; avertissements (bornes horaires, dépassement de volume, temps de déplacement) ; vues groupe/enseignant/salle/élève + **export ICS** (Africa/Algiers) + PDF ; `timetable_version` avec comparaison, publication par `schedule.timetable.publish` refusant tout conflit bloquant, **notification uniquement après publication** ; annulation et **remplacement** avec recontrôle d'habilitation et de disponibilité, alimentant pointage et rémunération | L |
| **W4.3** | Pointage enseignant (`FR-PTG-01/02/03`) | `teacher_attendance(session_id, status, late_minutes, actual_start/end, replacement_teacher_id, entered_by, validated_by, validation_status)` : **rattachement à la séance**, « non pointé » distinct d'« absent », refus de pointer une séance future, limite J+N ; validation individuelle ou par lot avec **valideur ≠ auteur** (T3) et rejet motivé notifié ; heures assurées validées dérivées des séances, remplaçant crédité, `teacher_hours_adjustment` tracé ; **brancher sur le calcul de rémunération du lot 3**. Migration depuis `work_logs` | L |
| **W4.4** | Appel des élèves (`FR-PRE-01`) | Liste des élèves affectés **à la date** de la séance (nécessite `group_membership` daté, W2.5), seuils de notification, notifications aux responsables, portée « un enseignant n'appelle que ses séances ». **Conserver le modèle `attendance` existant** | S |
| **W4.5** | Tableaux de bord (`FR-TDB-01/02/03`) | Indicateurs financiers avec période, filtres, date de mise à jour, **base de calcul affichée** et retour aux opérations sources ; revenus facturés par mois d'exigibilité ; encaissements nets déduisant contre-opérations et remboursements ; charges salariales distinguant engagé et payé **sans double comptage** ; résultat net de gestion avec convention affichée et paramétrable ; rentabilité par programme avec méthode de répartition affichée et coûts manquants signalés ; indicateurs opérationnels (remplissage, inscriptions en attente, conversion CRM, absences, taux de pointage validé, séances non pointées) ; exports respectant les permissions ; cache invalidé sur événement | M |

**Invariants :** `TST-05` complet, `TST-15` (cas concurrent + contrainte d'exclusion), `TST-16`.
**Porte de sortie :** parcours R08 ; aucun conflit publié ; pointage saisi puis validé par une autre personne ; heures assurées validées intégrées aux rémunérations.

### 6.7 Lots 5 à 10 (à détailler avant démarrage, §6 du SRS)

| Lot | Point de départ dans le code | Orientation |
|---|---|---|
| **5 — Pédagogie** | `lesson_versions` + `assessments`/`grades` (socle solide, 5 tests) | Étendre les leçons (chapitres, objectifs, pièces jointes, ciblage par groupe, conditionnement par `access_granted`) ; **construire** devoirs, dépôts idempotents et quiz ; ajouter les règles d'arrondi et de passage **versionnées** et la validation avant publication. **Décider du sort du sous-système Bulletins (ND-02)** : le conserver comme module optionnel au-delà du SRS, ou le retirer |
| **6 — Optimisation** | aucun (la page est une simulation déclarée) | Service Python/OR-Tools **derrière la file de tâches du lot 1** (`ARC-09`) ; l'application valide et écrit ; les scripts Python existants servent de jeux d'essai de référence |
| **7 — Assistant IA** | aucun | Interface de fournisseur unique (`ARC-10`), aucun service externe actif par défaut, quotas branchés sur `tenant_entitlement` (lot 1), évaluation séparée arabe/français, **réponse d'élève traitée comme entrée non fiable** |
| **8 — Stock** | aucun | Registre de mouvements immuables, quantité **calculée**, correction par mouvement lié |
| **9 — Mode dédié** | **le plus avancé** : Docker, composition par école, sauvegardes, table `deployment` | Épingler les images par version et empreinte ; ajouter la **clé de licence** (`FR-DED-02`) équivalente aux entitlements avec verrouillage en lecture seule à l'expiration ; guides d'installation et d'exploitation |
| **10 — Portail parents** | portail **enseignant** comme modèle (intergiciel de portée, authentification séparée) | Comptes `parent` liés aux élèves, chaque parent ne voyant que ses enfants ; consentement et conformité loi 18-07 |

### 6.8 Chemin critique et jalons

```
Lot 0 (2–3 sem.)
  └─ W1.1 runtime multi-tenant ──┬─ W1.3 entitlements
                                 ├─ W1.5 référentiel générique ──┬─ W2.7 programmes/volumes
                                 └─ W1.6 transverses ────────────┤
  └─ W1.4 IAM ──┬─ W1.2 console éditeur                          │
                └─ (SoD) ──────────────────────────┐              │
  └─ W1.7 i18n/RTL (parallèle, transversal)        │              │
                                                   │              │
Lot 2 : W2.5 group_membership ─────────────────────┼──────────────┼─ W4.4 appel daté
                                W2.6 personnel ────┼──────────────┼─ W4.1/4.2 planning
                                                   │              │
Lot 3 : W3.1 échéanciers ─ W3.2 paiements ─ W3.3 reçus/clôture    │
        W3.4 pay_rule ─ W3.5 génération ◄──────────┘              │
                                   ▲                              │
Lot 4 : W4.3 pointage par séance ──┘                              │
        W4.5 tableaux de bord ◄───────────────────────────────────┘
```

**Quatre goulots d'étranglement.** `W1.1` (runtime multi-tenant) débloque tout le lot 1. `W1.4` (IAM) est indispensable à la séparation des tâches, donc à la finance et au pointage. `W2.5` (`group_membership`) débloque l'appel daté et les groupes non principaux. `W3.4`/`W3.5` + `W4.3` doivent être conçus **ensemble** : le lot 3 calcule sur des heures saisies manuellement, le lot 4 les remplace par les séances pointées — le modèle de `payroll_line` doit accepter les deux sources dès le lot 3, sinon le lot 4 impose une seconde refonte.

| Jalon | Contenu | Échéance indicative |
|---|---|---|
| **J0** | SRS 1.2 validé, CI verte, décisions ND tranchées | S+3 |
| **J1** | Lot 1 clos : deux tenants isolés de bout en bout, R01/R02/R09/R10 verts, MVP bilingue fr/ar | S+17 |
| **J2** | Lot 2 clos : R03 vert, 500 élèves importés, capacité et idempotence prouvées | S+27 |
| **J3** | Lot 3 clos : R04–R07 verts, `TST-19` au centime, rapprochement validé par la finance | S+39 |
| **J4** | Lot 4 clos : R08 vert, planning publié sans conflit, pointage validé par un tiers alimentant la paie | S+47 |

Soit environ **11 à 12 mois pour les lots 1 à 4** avec 2 à 3 développeurs, hors recette client, hors chantiers juridiques (OD-07, OD-08) et hors lots 5 à 10. L'hypothèse H5 du SRS (« le premier trimestre vise les lots 1 à 4 ») n'est pas tenable : **le seul lot 1 représente environ un trimestre complet.** C'est le point le plus important à remonter à la direction produit.

---

## 7. Risques

| # | Risque | Probabilité | Impact | Atténuation |
|---|---|---|---|---|
| R1 | **Sous-estimation du lot 1.** La console, l'IAM fin, le référentiel générique et l'i18n sont quatre chantiers lourds qui n'existent pas, alors que la perception « il y a déjà une application qui marche » est forte. | élevée | élevé | Faire valider le jalon J1 à S+17 **avant** d'engager le lot 2 ; livrer W1.1+W1.3 en premier pour rendre le multi-tenant démontrable tôt |
| R2 | **L'internationalisation repoussée.** Chaque écran livré sans i18n augmente le coût de reprise. | élevée | élevé | Traiter W1.7 **dans** le lot 1, en parallèle ; verrouiller par le test de pseudo-localisation en CI dès la première chaîne extraite |
| R3 | **Migration de données du référentiel (W1.5) et des affectations (W2.5)** sur un déploiement contenant déjà des données réelles d'un établissement. | moyenne | élevé | Vues de compatibilité pendant la transition ; migration répétable et vérifiée sur copie anonymisée ; sauvegarde vérifiée avant chaque bascule (procédure déjà documentée dans `docs/ARCHITECTURE.md`) |
| R4 | **Régression des invariants** pendant les refontes de schéma (`group_membership`, `pay_rule`, échéanciers). | moyenne | élevé | Exécuter **toute** la suite d'invariants à chaque migration, pas seulement les nouveaux tests ; aucune migration fusionnée sans son test |
| R5 | **Rupture du lot 3 par le lot 4.** Si `payroll_line` n'accepte pas deux sources d'unités (saisie manuelle puis séances pointées), le lot 4 impose une refonte du calcul validé. | moyenne | élevé | Concevoir `payroll_line` avec un champ de provenance dès W3.5 ; écrire un test du lot 3 avec des unités d'origine « séance » simulée |
| R6 | **Conformité juridique tardive** (OD-07, OD-08, loi 18-07). Aucune durée de conservation, aucun contrat de sous-traitance. | élevée | élevé | Lancer le chantier juridique **en parallèle du lot 1**, pas à la fin ; `NFR-DATA-02` conditionne le premier client payant |
| R7 | **Dérive du mode démonstration.** Les règles métier y sont dupliquées en session avec des flottants (T15). | **réalisée** | moyen | Trancher ND-04 au lot 0 : bac à sable d'interface non normatif clairement étiqueté, ou suppression |
| R8 | **Les bulletins scolaires orientent le produit hors SRS.** ~1 200 lignes et 13 routes implémentent une fonction explicitement exclue du périmètre. | moyenne | moyen | Trancher ND-02 au lot 0 ; si conservé, en faire un module optionnel documenté comme extension hors SRS, avec son propre coût de maintenance assumé |
| R9 | **Performances non démontrées** (`NFR-PERF-*`), sur une architecture reposant sur un verrou consultatif par établissement pour le planning et les inscriptions. | moyenne | moyen | Instrumenter tôt (latences p95, attentes de verrou, 409) ; test de charge sur le tenant de référence au jalon J2, avant que les volumes ne s'accumulent |
| R10 | **Absence d'intégration continue** aujourd'hui : 34 tests excellents que rien n'exécute. | **réalisée** | élevé | Chantier 0.3, en première semaine |

---

## 8. Décisions à trancher

### 8.1 Décisions ouvertes du SRS devenues bloquantes

| ID | Question | Recommandation de ce rapport | Avant |
|---|---|---|---|
| OD-01 | Pile technique | **Conserver Laravel 12 / PostgreSQL 16 / Filament 5 ; amender la §4.2.** Justification au §2.3 | Lot 0 |
| OD-11 | Numérotation matricules et reçus | Nécessaire dès W2.1 (matricule) et W3.3 (reçus) | Lot 0 |
| OD-13 | Libellés exacts du profil `private_school_dz`, dont les libellés arabes | Nécessaire dès W1.5 ; le contenu français existe déjà dans `algerian_curriculum.php` | Lot 1 |
| OD-12 | Fournisseur SMTP | Nécessaire dès W1.6 | Lot 1 |
| OD-14 | File d'attente de groupe | Nécessaire dès W2.5 | Lot 2 |
| OD-06 | Rémunération des séances annulées, absences, remplacements | Nécessaire dès W3.5 ; le code actuel applique déjà le défaut sain « une séance non assurée ne compte pas » | Lot 3 |
| OD-09 | Seuils d'approbation | Nécessaire dès W3.1 et W3.6 | Lot 3 |
| OD-10 | Défaut de `finance.allow_self_validation` | Nécessaire dès W3.2 | Lot 3 |
| OD-05 | Politique d'accès avec paiement partiel | Nécessaire dès W2.8 (`access_granted`) | Lot 2, pas lot 5 |
| OD-07 / OD-08 | Conservation et validation juridique | **À lancer en parallèle du lot 1**, pas à la fin | Premier client |

### 8.2 Nouvelles décisions issues de cette analyse

| ID | Question | Recommandation | Avant |
|---|---|---|---|
| **ND-01** | Représentation des montants : entiers en centimes (code) ou `NUMERIC(14,2)` (`ARC-11`) | **Amender `ARC-11`** pour accepter l'entier en centimes comme représentation équivalente, en documentant la règle d'arrondi *half-up* par ligne. Migrer 15 colonnes et toute l'arithmétique n'apporte aucune garantie supplémentaire | Lot 0 |
| **ND-02** | Sort du sous-système **Bulletins scolaires** (~1 200 lignes, explicitement hors périmètre §2.2) | **Conserver comme module optionnel hors SRS**, documenté comme tel, avec son coût de maintenance assumé — c'est un argument commercial réel sur le marché algérien. À défaut, retirer | Lot 0 |
| **ND-03** | Sort du **passage annuel** (`annual_progressions`) | **L'inscrire au SRS** (module GRP ou ELV) : fonction nécessaire à une école, déjà implémentée avec de bons contrôles | Lot 0 |
| **ND-04** | Sort du **mode démonstration** (`DemoStore`) | **Étiqueter en bac à sable d'interface non normatif** et le sortir du périmètre de test de conformité, ou le supprimer. Il duplique les règles métier avec des flottants (T15, R7) | Lot 0 |
| **ND-05** | Contrainte d'exclusion `tstzrange` (§7.3) vs déclencheur + verrou consultatif (code) | **Ajouter la contrainte d'exclusion pour enseignant et salle en complément**, en conservant le déclencheur pour les conflits de groupe et d'élève, qu'une contrainte d'exclusion ne peut pas exprimer | Lot 0 |
| **ND-06** | Modèle de déploiement cible : SaaS mutualisé, dédié, ou les deux sur le même code | **Les deux sur le même code** : le dédié existe déjà et fonctionne (lot 9 quasi acquis), le mutualisé est le lot 1. Conserver `DeploymentSchool` comme mode d'épinglage **optionnel** et non comme chemin unique | Lot 0 |
| **ND-07** | Sort des **validateurs d'emploi du temps figés** (`WeeklyTimetable`, 11 classes et 17 enseignants codés en dur) | **Sortir du chemin produit**, conserver comme jeu d'essai dans `tests/fixtures` | Lot 0 |
| **ND-08** | Révision de l'hypothèse **H5** (« le premier trimestre vise les lots 1 à 4 ») | **Réviser** : le seul lot 1 représente environ un trimestre. Calendrier proposé au §6.8 | Lot 0 |

---

## 9. Annexes

### 9.1 Correspondance des entités : modèle SRS §7.2 ↔ schéma existant

| Entité SRS | Table existante | Écart |
|---|---|---|
| `tenant` | `school.institutions` | ni `slug`, ni `status`, ni `default_locale` |
| `site` | `school.campuses` | ni `code`, ni `status`, ni libellés multilingues |
| `plan`, `tenant_entitlement` | — (variables d'environnement) | **à créer** |
| `support_access_grant` | — | **à créer** |
| `profile_template(version)` | `config/algerian_curriculum.php` + `AlgerianCurriculum` | en code, non versionné → **à passer en données** |
| `user` | `public.users` | conforme |
| `credential`, `mfa_device`, `session` | — | **à créer** (Argon2id, TOTP) |
| `role`, `role_permission`, `role_assignment`, `permission_catalog` | `memberships.permissions` (JSONB) | **à créer** (16 permissions grossières aujourd'hui) |
| `sod_rule` | — | **à créer** |
| `node_type`, `ref_node` | `school.programs` + `school.levels` | arbre fixe à 2 niveaux → **refonte** |
| `subject` | `school.subjects` | ni `kind`, ni libellés, ni `status` |
| `program_subject` | `school.curriculum` | rattaché au **niveau**, une seule volumétrie, non versionné → **refonte** |
| `custom_field_def` | — | **à créer** |
| `academic_year`, `period` | `school.periods` | un seul niveau → **refonte** |
| `tenant_setting` | `institutions.settings` (JSONB) | aucun écran |
| `audit_log` | `school.audit_events` | ni avant/après, ni motif, ni IP, ni identifiant de requête |
| `file_object` | `school.documents` | ni S3, ni catégories, ni quota, ni URL signée |
| `notification` | `school.announcements` (partiel) | **à créer** |
| `job`, `import_batch`, `import_row`, `export_log` | — | **à créer** |
| `student` | `school.students` | voir `FR-ELV-01` (photo, noms arabes, statuts) |
| `guardian`, `student_guardian` | `school.guardians`, `school.student_guardians` | partiel |
| `lead`, `lead_interaction` | `school.admissions` (aval seulement) | pipeline **à créer** |
| `room`, `room_closure` | `school.rooms` | fermetures **à créer** |
| `staff` | `school.staff` | `staff_type` **à créer** |
| `qualification` | `school.teacher_qualifications` | non datée, sans héritage |
| `availability`, `unavailability` | — | **à créer** |
| `contract` | `school.contracts` | porte le montant → **divergent** |
| `leave` | — | **à créer** |
| `program(price_version)` | `school.offerings` | versionnement **à créer** |
| `group` | `school.cohorts` | ni typage, ni dates, ni statuts |
| `group_membership` | — (`enrollments.cohort_id`) | **à créer — refonte structurante** |
| `enrollment` | `school.enrollments` | bon socle |
| `enrollment_status_history` | `outbox` (motifs seulement) | **à créer** |
| `installment`, `adjustment` | — (facture unique) | **à créer** |
| `payment` | `school.payments` | ni statut, ni validation, `reference` non unique |
| `payment_reversal` | — (`refunds` détourné) | **à créer** |
| `refund_request`, `refund` | `school.refunds` | circuit d'approbation **à créer** |
| `receipt` | — | **à créer** |
| `finance_period` | — | **à créer** |
| `expense_category` | — | **à créer** |
| `expense` | `school.expenses` | ni états, ni justificatifs, ni bénéficiaire |
| `expense_reversal` | — | **à créer** |
| `pay_rule`, `pay_rule_rate` | `school.contracts(basis, rate_minor)` | **refonte structurante** |
| `payroll_run` | `school.payroll_runs` | ni brouillon, ni lignes |
| `payroll_line`, `payroll_adjustment` | `payroll_runs.calculation` (JSONB) | **à créer** |
| `payout` | `school.disbursements` | conforme dans l'esprit |
| `closure`, `time_slot`, `recurrence` | — | **à créer** |
| `session` | `school.sessions` | ni `delivered`/`replaced`, ni `locked`, ni version |
| `timetable_version` | — | **à créer** |
| `teacher_attendance` | `school.work_logs` (par jour) | **refonte** (par séance) |
| `teacher_hours_adjustment` | — | **à créer** |
| `student_attendance` | `school.attendance` | bon socle |
| `lesson(version)` | `school.lesson_versions` | partiel |
| `grade` | `school.grades` + `school.assessments` | bon socle |
| **Hors SRS** | `report_card_terms`, `report_card_observations`, `report_card_archives`, `annual_progressions`, `teacher_assignments`, `deployment`, `outbox` | ND-02, ND-03 |

### 9.2 Correspondance des permissions : code existant ↔ annexe A

| Permission du code | Équivalents annexe A (indicatif) |
|---|---|
| `catalog.read` / `catalog.write` | `referential.node.read`/`.manage`, `program.program.read`/`.manage`, `subject.subject.manage`, `program.subject.manage`, `site.site.manage`, `room.room.manage`, `group.group.read`/`.manage` |
| `students.read` / `students.write` | `student.record.read`/`.create`/`.update`/`.archive`, `student.sensitive.read`, `guardian.record.*`, `crm.lead.*` |
| `enrollments.read` / `enrollments.write` | `enrollment.enrollment.read`/`.create`/`.update`, `enrollment.enrollment.validate`/`.suspend`/`.cancel`, `group.membership.manage` |
| `pedagogy.read` / `pedagogy.write` | `schedule.session.*`, `schedule.timetable.publish`, `attendance.student.entry.*`, `lms.*` |
| `finance.read` / `finance.write` | `finance.payment.read`/`.create`/`.create.delegated`/`.validate`/`.reverse`, `finance.discount.grant`, `finance.schedule.manage`, `finance.refund.request`/`.approve`, `finance.period.close`/`.reopen`, `expense.expense.*`, `expense.attachment.read` |
| `hr.read` / `hr.write` | `staff.profile.*`, `staff.qualification.manage`, `staff.availability.manage`/`.approve`, `staff.contract.read`/`.manage`, `staff.leave.*` |
| `payroll.read` / `payroll.write` | `payroll.rule.manage`, `payroll.amount.read`, `payroll.run.generate`/`.validate`, `payroll.payout.record`, `payroll.self_view`, `hours.validated.read`, `attendance.teacher.*` |
| `audit.read` | `audit.log.read` (sans la séparation par domaine) |
| — | `platform.tenant.manage`, `platform.support.access`, `tenant.settings.manage`, `custom_field.definition.manage`, `role.definition.manage`, `role.assignment.manage`, `user.account.*`, `import.job.*`, `export.job.create`, `dashboard.*`, `stock.*`, `ai.*` : **aucun équivalent** |

**Conséquence pour W1.4 :** 16 permissions grossières doivent être éclatées en ~60 permissions fines. Les ~40 points d'appel `SchoolService::authorize()` constituent la liste exhaustive des endroits à reprendre — c'est un travail mécanique, à faible risque, et le principe P4 (aucun nom de rôle dans la logique métier) est déjà respecté, ce qui en garantit la faisabilité.

### 9.3 Fichiers clés pour la suite des travaux

| Rôle | Fichier |
|---|---|
| Schéma et invariants | [madrassa-backend/database/schema/001_school.sql](madrassa-backend/database/schema/001_school.sql) |
| Contexte de requête, RLS, traduction d'erreurs | [madrassa-backend/app/School/Http/TenantContext.php](madrassa-backend/app/School/Http/TenantContext.php) |
| Opérations métier transactionnelles et idempotentes | [madrassa-backend/app/School/Services/SchoolService.php](madrassa-backend/app/School/Services/SchoolService.php) |
| API générique CRUD, recherche, pagination | [madrassa-backend/app/School/Http/SchoolController.php](madrassa-backend/app/School/Http/SchoolController.php) |
| Catalogue de ressources et règles de validation | [madrassa-backend/config/school_backend.php](madrassa-backend/config/school_backend.php) |
| Résolveur de modules et de permissions effectives | [madrassa-backend/app/School/Modules/ModuleRegistry.php](madrassa-backend/app/School/Modules/ModuleRegistry.php) |
| Privilèges minimaux du rôle d'exécution | [madrassa-backend/deploy/runtime-grants.sql](madrassa-backend/deploy/runtime-grants.sql) |
| Invariants SQL (34 tests) | [madrassa-backend/tests/database.mjs](madrassa-backend/tests/database.mjs) |
| Conversion candidature → inscription (atomique, idempotente) | [madrassa-backend/app/School/Services/StudentLifecycle.php](madrassa-backend/app/School/Services/StudentLifecycle.php) |
| Contenu du référentiel algérien à transformer en profil | [madrassa-backend/config/algerian_curriculum.php](madrassa-backend/config/algerian_curriculum.php) |
| Décisions d'architecture documentées par les auteurs | [madrassa-backend/docs/ARCHITECTURE.md](madrassa-backend/docs/ARCHITECTURE.md) |
| Limites reconnues de la livraison actuelle | [ecole-filament/docs/VALIDATION.md](ecole-filament/docs/VALIDATION.md) |

---

## 10. Conclusion et recommandation

Le code existant n'est pas un prototype : c'est un **produit mono-établissement opérationnel**, doté d'une couche d'invariants de qualité nettement supérieure à ce que l'on trouve habituellement à ce stade — isolation à deux barrières, immutabilité financière en base, capacité atomique testée en concurrence, idempotence par clé, conflits d'emploi du temps refusés par le moteur de base de données, et une documentation qui nomme honnêtement ses propres limites.

Mais il réalise le **lot 9** du SRS (mode dédié) et non le **lot 1** (fondations SaaS). L'écart ne se situe pas dans la qualité du code : il se situe dans le périmètre. Console éditeur, plans et quotas, permissions fines avec portées, séparation des tâches, référentiel générique école/centre, internationalisation arabe et RTL, tâches de fond, fichiers, notifications, import/export et PDF sont à construire — soit environ un trimestre complet de travail pour le seul lot 1.

**Recommandation en trois points.**

1. **Valider ce rapport et publier le SRS 1.2** avec deux amendements seulement (pile technique, représentation des montants) et l'arbitrage des quatre ensembles hors périmètre trouvés dans le code. Dix des douze divergences doivent être corrigées dans le code, non dans le SRS : le code n'a pas dérivé par facilité, il a été livré pour un périmètre plus étroit.
2. **Réviser l'hypothèse H5.** Les lots 1 à 4 représentent 11 à 12 mois à 2–3 développeurs, pas un trimestre. C'est le point à remonter en priorité à la direction produit, avant tout engagement commercial.
3. **Engager le lot 0 immédiatement** (2 à 3 semaines) : trancher les huit décisions `ND`, mettre les 34 invariants existants sous intégration continue — ils ne sont exécutés par personne aujourd'hui — et appliquer les corrections à faible coût du §5. Rien dans ce lot n'est fonctionnel, et tout le reste en dépend.

> Conformément à la §0.5 du SRS, **aucune modification de code ne doit être engagée avant validation de ce rapport.**
