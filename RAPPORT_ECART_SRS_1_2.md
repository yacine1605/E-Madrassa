# Madrassa — état d'application du SRS 1.2

Analyse du 11 octobre 2026, mise à jour avec les incréments 14 et 15. Référence : `SRS_Madrassa_1_2.md`, décisions §10.1 et rapport 1.1 déjà intégré. La suite reprend le plan existant ; aucune nouvelle décision d'architecture n'est introduite. Le document SRS source n'est pas modifié.

## Base retenue et preuves

`platform/` est la nouvelle application normative (ND-09) : Laravel 12, Filament 5, PostgreSQL, schéma incrémental, rôles SQL restreints, API et services. `ecole-filament/` et `madrassa-backend/` restent l'application antérieure ; leur couverture ne vaut pas conformité de la nouvelle base. Les bulletins et la démonstration sont hors périmètre (ND-02, ND-04).

Validation de l'incrément 15 : 146 contrôles SQL embarqués et 134 tests PHP/PostgreSQL 16 (3 144 assertions), sans fichier `.env` local. Les 152 contrôles SQL natifs avec concurrence ont été exécutés à l'incrément 14 ; aucun schéma SQL n'a changé depuis. Les tests utilisent des bases jetables, jamais les données de l'application antérieure. « PARTIEL » peut désigner une API testée dont le parcours utilisateur manque ; aucune ligne n'affirme la conformité complète du produit avant recette.

Abréviations de fichiers : **SQL** = `platform/database/schema/`, **API** = `platform/app/Http/Controllers/`, **services** = `platform/app/Support/`, **tests** = `platform/tests/Feature/` et `platform/tests/sql/`, **UI** = `platform/app/Filament/`, **CI** = `.github/workflows/ci.yml`. S/M/L : effort relatif d'un complément, pas un engagement calendaire.

## Exigences fonctionnelles

| ID | Statut | Preuves et complément nécessaire | Risque | Effort |
|---|---|---|---|---|
| FR-PLT-01 | PARTIEL | SQL, UI/Console, ConsoleTest : création, invitation, états ; recette de bout en bout à terminer | majeur | M |
| FR-PLT-02 | PARTIEL | SQL, ConsoleTest : dépendances, quotas de stockage, lecture seule ; quota élèves avec lot 2 | majeur | M |
| FR-PLT-03 | PARTIEL | SQL, ReferentialTest : profils versionnés ; validation des libellés métier OD-13 | mineur | S |
| FR-PLT-04 | PARTIEL | SupportTest, SchoolPanelTest : consentement API, lecture seule et bannière ; écran tenant de gestion des consentements à créer | majeur | M |
| FR-PLT-05 | PARTIEL | UI/Console/Supervision, ConsoleTest : comptages ; supervision et alertes d'exploitation à compléter | majeur | M |
| FR-IAM-01 | PARTIEL | AccountController, UI/School/Accounts, AccountTest, SchoolPanelTest : cycle de vie ; formulaires publics d'activation/récupération à créer | majeur | M |
| FR-IAM-02 | PARTIEL | SessionController, CredentialController, SchoolLogin, Credentials : connexion, MFA, verrouillage et mot de passe provisoire ; recette navigateur à terminer | majeur | M |
| FR-IAM-03 | PARTIEL | RoleController, SQL, UI/School/Roles et Assignments, SchoolPanelTest ; recette utilisateur et présentation groupée des permissions à compléter | majeur | M |
| FR-IAM-04 | PARTIEL | Permissions, RoleAssignmentController ; portées groupe/personnelles à compléter avec lot 2 | bloquant | L |
| FR-IAM-05 | PARTIEL | SQL, AuthorizationTest : auto-attribution et auto-élévation refusées ; validations métier avec lots 3/4 | bloquant | M |
| FR-REF-01 | PARTIEL | ReferentialController, UI/School/ReferenceTypes, ReferentialTest, SchoolReferencePanelTest ; recette utilisateur à terminer | majeur | S |
| FR-REF-02 | PARTIEL | ReferentialController, UI/School/ReferenceNodes, SchoolReferencePanelTest ; références des lots suivants et recette utilisateur | majeur | M |
| FR-REF-03 | PARTIEL | SubjectController, UI/School/Subjects, SchoolReferencePanelTest ; utilisation par programmes au lot 2 et recette utilisateur | majeur | M |
| FR-REF-04 | PARTIEL | CustomFieldController, SQL ; interface et validation des futurs dossiers | majeur | M |
| FR-REF-05 | PARTIEL | CalendarController, UI/School/Years et Periods, SchoolReferencePanelTest ; clôture des dossiers futurs au lot 2 | majeur | M |
| FR-REF-06 | PARTIEL | SettingController, SQL ; interface, seuils finance et créneaux planning | majeur | M |
| FR-AUD-01 | PARTIEL | AuditController, SQL, ServicesTest ; écran journal absent, objets métier futurs | majeur | M |
| FR-I18-01 | PARTIEL | Locales, lang/fr, lang/ar, LocalizationTest, PseudoLocalizationTest ; comptes/droits/référentiel/calendrier livrés, autres écrans et recette visuelle arabe à compléter | majeur | M |
| FR-IMP-01 | PARTIEL | Imports, ImportController, ImportTest ; écran et import des entités du lot 2 | majeur | M |
| FR-IMP-02 | PARTIEL | Exporter, ExportController, ExportTest ; écran et jeux de données métier futurs | majeur | M |
| FR-FIC-01 | PARTIEL | Files, FileController, FileTest ; stockage S3 de déploiement et interfaces métier | majeur | M |
| FR-NOT-01 | PARTIEL | Outbox, NotificationController, ServicesTest ; cloche tenant et événements futurs | majeur | M |
| FR-JOB-01 | PARTIEL | Tasks, WithinSchool, JobController, ServicesTest ; écran des tâches et supervision | majeur | M |
| FR-PDF-01 | PARTIEL | Pdf, vues/pdf, PdfTest ; modèles métier et recette visuelle arabe | majeur | M |
| FR-ELV-01 | ABSENT | platform : fiche élève à créer au lot 2 | majeur | L |
| FR-ELV-02 | ABSENT | platform : responsables et liens à créer au lot 2 | majeur | M |
| FR-ELV-03 | ABSENT | platform : statuts et archivage des élèves | majeur | M |
| FR-ELV-04 | ABSENT | platform : recherche et doublons des élèves | majeur | M |
| FR-CRM-01 | ABSENT | platform : prospects, pipeline et historique | majeur | M |
| FR-CRM-02 | ABSENT | platform : conversion transactionnelle | majeur | M |
| FR-SLL-01 | PARTIEL | SiteController, SQL, AuthorizationTest ; écran et compléments lot 2 | majeur | M |
| FR-SLL-02 | ABSENT | platform : salles et contraintes | majeur | M |
| FR-STF-01 | ABSENT | platform : personnel sans compte obligatoire | majeur | L |
| FR-STF-02 | ABSENT | platform : habilitations référencées | bloquant | M |
| FR-STF-03 | ABSENT | platform : disponibilités et bornes | majeur | M |
| FR-STF-04 | ABSENT | platform : contrats et congés | majeur | M |
| FR-PRG-01 | ABSENT | platform : programmes et tarifs | majeur | L |
| FR-PRG-02 | ABSENT | platform : matières, volumes et séances des programmes | majeur | L |
| FR-GRP-01 | ABSENT | platform : groupes/classes | majeur | L |
| FR-GRP-02 | ABSENT | platform : affectations, capacité concurrente, transferts | bloquant | L |
| FR-INS-01 | ABSENT | platform : inscription idempotente et engagement figé | bloquant | L |
| FR-INS-02 | ABSENT | platform : deux dimensions d'état | bloquant | M |
| FR-INS-03 | ABSENT | platform : annulation, suspension et transfert | majeur | L |
| FR-INS-04 | ABSENT | platform : politique d'accès liée au paiement | majeur | M |
| FR-INS-05 | ABSENT | platform : passage annuel | majeur | L |
| FR-FIN-01 | ABSENT | platform : échéanciers, remises et validations | bloquant | L |
| FR-FIN-02 | ABSENT | platform : paiements et idempotence | bloquant | L |
| FR-FIN-03 | ABSENT | platform : validation et contre-opérations | bloquant | L |
| FR-FIN-04 | ABSENT | platform : remboursements et trop-perçu | bloquant | L |
| FR-FIN-05 | ABSENT | platform : reçus numérotés et PDF | majeur | M |
| FR-FIN-06 | ABSENT | platform : impayés et retards | majeur | M |
| FR-FIN-07 | ABSENT | platform : clôture financière | bloquant | L |
| FR-DEP-01 | ABSENT | platform : catégories de dépenses | majeur | M |
| FR-DEP-02 | ABSENT | platform : dépenses et approbations | bloquant | L |
| FR-DEP-03 | ABSENT | platform : corrections liées | bloquant | M |
| FR-REM-01 | ABSENT | platform : quatre règles et tarifs versionnés | bloquant | L |
| FR-REM-02 | ABSENT | platform : génération et exemples AC1–AC13 | bloquant | L |
| FR-REM-03 | ABSENT | platform : détail, primes et ajustements | majeur | M |
| FR-REM-04 | ABSENT | platform : versements et dépense unique | bloquant | L |
| FR-REM-05 | ABSENT | platform : consultation personnelle | majeur | M |
| FR-PLN-01 | ABSENT | platform : calendrier et créneaux | majeur | L |
| FR-PLN-02 | ABSENT | platform : récurrence et séances | majeur | L |
| FR-PLN-03 | ABSENT | platform : conflits et exclusion concurrente | bloquant | L |
| FR-PLN-04 | ABSENT | platform : vues PDF/ICS | majeur | L |
| FR-PLN-05 | ABSENT | platform : versions et publication | majeur | L |
| FR-PLN-06 | ABSENT | platform : annulations et remplacements | majeur | L |
| FR-PTG-01 | ABSENT | platform : pointage enseignants | majeur | M |
| FR-PTG-02 | ABSENT | platform : validation par un autre auteur | bloquant | M |
| FR-PTG-03 | ABSENT | platform : heures, correction et verrouillage | bloquant | M |
| FR-PRE-01 | ABSENT | platform : appel élèves | majeur | M |
| FR-TDB-01 | ABSENT | platform : indicateurs financiers rapprochables | bloquant | L |
| FR-TDB-02 | ABSENT | platform : indicateurs opérationnels | majeur | M |
| FR-TDB-03 | ABSENT | platform : traçabilité et exports des indicateurs | majeur | M |

Les lots 5–10 n'ont pas encore d'identifiants FR détaillés : LMS, optimisation, IA, stock, dédié complet et portails restent à spécifier avant réalisation. Le paramètre de tenant dédié existe ; il ne livre pas le lot 9.

## Règles transverses

| ID | Statut | Preuves et complément nécessaire | Risque | Effort |
|---|---|---|---|---|
| BR-01 | PARTIEL | SQL RLS, SchoolContext, SchoolIsolationTest ; étendre aux écrans et lots futurs | bloquant | M |
| BR-02 | PARTIEL | SQL et tests : archivage ; tables métier futures | majeur | M |
| BR-03 | ABSENT | Écritures financières et inscriptions annulées non créées | bloquant | L |
| BR-04 | PARTIEL | Money : format en centimes ; arrondi métier avec finance | bloquant | M |
| BR-05 | PARTIEL | Création tenant, jobs et imports idempotents ; opérations métier futures | bloquant | L |
| BR-06 | PARTIEL | SchoolContext transactionnel ; transactions des lots suivants | bloquant | L |
| BR-07 | ABSENT | Groupes et capacité concurrente au lot 2 | bloquant | L |
| BR-08 | PARTIEL | SQL check_segregation, tests ; tables d'approbation futures | bloquant | M |
| BR-09 | PARTIEL | SQL audit append-only et API ; événements métier futurs | majeur | M |
| BR-10 | PARTIEL | SQL rôles/assignations ; tarifs, groupes et rémunérations futurs | majeur | M |
| BR-11 | ABSENT | Engagement d'inscription au lot 2 | bloquant | M |
| BR-12 | ABSENT | Inscription au lot 2, financier au lot 3 | bloquant | M |
| BR-13 | PARTIEL | SQL référentiel et portées ; dossiers métier futurs | majeur | M |
| BR-14 | ABSENT | Notes, appel et pointage futurs | majeur | M |
| BR-15 | ABSENT | Clôture financière au lot 3 | bloquant | L |
| BR-16 | PARTIEL | Refus SQL explicites, DatabaseErrors ; invariants métier futurs | bloquant | L |
| BR-17 | PARTIEL | Tokens hachés, messages sans SQL ; rétention et anonymisation absentes | majeur | L |
| BR-18 | PARTIEL | Codes SQL immuables et labels FR/AR ; futurs catalogues | majeur | M |
| BR-19 | PARTIEL | Notifications sans détails sensibles ; publication planning future | majeur | M |
| BR-20 | PARTIEL | RequireModule, Permissions, jobs ; menus tenant et calculs futurs | bloquant | M |

## Exigences non fonctionnelles

| ID | Statut | Preuves et complément nécessaire | Risque | Effort |
|---|---|---|---|---|
| NFR-PERF-01 | ABSENT | Test de charge et mesures p95 à produire | majeur | L |
| NFR-PERF-02 | ABSENT | Scénario mixte et environnement de mesure à documenter | majeur | M |
| NFR-PERF-03 | ABSENT | 50 tenants/25 000 élèves à mesurer après lot 2 | majeur | L |
| NFR-PERF-04 | PARTIEL | Jobs et exports >2 000 ; certaines API listent encore tout, pagination à compléter | majeur | M |
| NFR-PERF-05 | ABSENT | Poids compressé des pages à mesurer | mineur | M |
| NFR-SEC-01 | PARTIEL | Config session ; TLS/HSTS dépend du futur déploiement platform | majeur | M |
| NFR-SEC-02 | PARTIEL | Argon2id et variables d'environnement ; rotation en exploitation à documenter | majeur | M |
| NFR-SEC-03 | PARTIEL | CSRF, Blade, SQL paramétré, fichiers et exports testés ; CSP à compléter | majeur | M |
| NFR-SEC-04 | PARTIEL | PermissionCoverageTest, SchoolPanelTest, SchoolReferencePanelTest : permissions retirées, IDs étrangers et onglets périmés refusés ; étendre aux écrans et lots futurs | bloquant | M |
| NFR-SEC-05 | PARTIEL | LoginThrottle, SchoolLogin, tests ; activation et récupération UI à terminer | majeur | M |
| NFR-SEC-06 | PARTIEL | Refusal, DatabaseErrors, liens chiffrés ; revue exploitation/logs à terminer | majeur | M |
| NFR-SEC-07 | PARTIEL | Audit droits et verrouillage ; alertes anormales non livrées | majeur | M |
| NFR-SEC-08 | ABSENT | Pas de job d'audit dépendances dans CI | majeur | S |
| NFR-SEC-09 | PARTIEL | docker/backup de l'ancienne application ; adapter au déploiement platform | majeur | M |
| NFR-TEN-01 | PARTIEL | SQL, deploy/roles.sql, tests ; revalider pour toute nouvelle table | bloquant | M |
| NFR-TEN-02 | PARTIEL | SchoolIsolationTest, fichiers/exports/jobs, SchoolPanelTest, SchoolReferencePanelTest ; étendre aux autres écrans | bloquant | M |
| NFR-TEN-03 | PARTIEL | CurrentSchool, WithinSchool, Files ; déploiement et prochains modules | bloquant | M |
| NFR-AVL-01 | ABSENT | SLO et mesure d'exploitation à contractualiser | majeur | M |
| NFR-REC-01 | ABSENT | Déploiement platform et sauvegarde séparée à créer | bloquant | L |
| NFR-REC-02 | ABSENT | Restauration base+fichiers platform à tester | bloquant | M |
| NFR-REC-03 | ABSENT | Validation RPO par la finance requise hors code | majeur | S |
| NFR-OPS-01 | PARTIEL | CI migrations et request_id ; environnements et procédures à compléter | majeur | L |
| NFR-DATA-01 | PARTIEL | Aucun cloud imposé ; résidence des services à vérifier au déploiement | bloquant | M |
| NFR-DATA-02 | ABSENT | Validation juridique OD-08 hors code | bloquant | L |
| NFR-DATA-03 | ABSENT | Politique OD-07, purge et anonymisation à créer | majeur | L |
| NFR-DATA-04 | PARTIEL | Exports par domaine ; export tenant complet et procédure de rectification absents | majeur | L |
| NFR-DATA-05 | PARTIEL | Fichiers privés et export signé ; sauvegardes platform à intégrer | majeur | M |
| NFR-DATA-06 | PARTIEL | Tests avec données fictives ; règles à maintenir avec lots suivants | majeur | S |
| NFR-UX-01 | PARTIEL | Console et panneau école Filament ; autres écrans et recette utilisateur à terminer | majeur | L |
| NFR-UX-02 | PARTIEL | Composants Filament ; tests visuels mobile/tablette à faire | majeur | M |
| NFR-UX-03 | PARTIEL | Composants accessibles ; audit clavier/contraste AA à faire | majeur | M |
| NFR-UX-04 | PARTIEL | Catalogues d'erreur, confirmations console/comptes/droits/référentiel/calendrier ; autres interfaces tenant à créer | majeur | M |
| NFR-UX-05 | PARTIEL | Validation et notifications existantes ; interruptions UI à éprouver | mineur | M |
| NFR-COMP-01 | ABSENT | Matrice de navigateurs non exécutée | majeur | M |
| NFR-I18N-01 | PARTIEL | FR/AR et pseudo-localisation console/comptes/droits/référentiel/calendrier ; autres écrans tenant et recette visuelle, anglais ultérieur | majeur | M |
| NFR-I18N-02 | PARTIEL | PdfTest, polices embarquées ; documents métier et recette visuelle | majeur | M |
| NFR-I18N-03 | PARTIEL | search_key arabe/latin testé ; translittération non généralisée | mineur | M |
| NFR-PORT-01 | PARTIEL | Configuration portable et S3 ; Docker/Compose platform absent | majeur | M |
| NFR-PORT-02 | PARTIEL | Socle PHP/SQL sans GPU ; vérifier futurs processus | mineur | S |
| NFR-MNT-01 | PARTIEL | CI SQL/PHP/migrations ; analyse statique et audit dépendances à ajouter | majeur | M |
| NFR-MNT-02 | PARTIEL | Tests des invariants fondations ; lots 2–4 non réalisés | bloquant | L |
| NFR-MNT-03 | PARTIEL | platform/README ; guides API/administration/exploitation à compléter | majeur | M |
| NFR-MNT-04 | PARTIEL | Migrations et profils versionnés ; paramètres de calcul au lot 3 | majeur | M |

## Suite ordonnée par lot

1. **Lot 1, incrément 14 livré** : entrée du panneau tenant, sélection sûre d'école, comptes/rôles/assignations, sécurité personnelle et bannière support ; tests d'isolation, permissions, séparation, suspension, expiration, MFA et pseudo-localisation. Les actions réutilisent les validations et fonctions SQL existantes. Les refus SQL sont isolés par savepoint pour laisser la transaction du panneau utilisable. Les anciennes applications ne sont pas modifiées.
2. **Lot 1, incrément 15 livré** : types et nœuds du référentiel, catalogue matières/modules, années et périodes ; libellés FR ou AR, parents compatibles, archivage, historique, suppression des matières inutilisées, dates et clôture définitive. Recherche normalisée, versions optimistes et contrôles de permission/isolation repris dans les écrans. Les listes paginent l'affichage ; les API de catalogue chargent encore toutes les lignes.
3. **Lot 1, suite** : activation/récupération publiques, champs personnalisés, paramètres ; journal, tâches, notifications, imports/exports et consentements support. Puis Docker/Compose, sauvegarde/restauration, tests visuels RTL, pagination et sécurité d'exploitation. Le lot 1 ne peut pas être déclaré terminé aujourd'hui.
4. **Lot 2** : commencer par schéma, RLS et invariants TST-03/07/08/18, puis dossiers et parcours élève → inscription → groupe ; import des 500 élèves.
5. **Lot 3** : TST-09–13/19 avant écrans ; centimes exacts, idempotence, séparation, immutabilité, clôture et rapprochement des versements.
6. **Lot 4** : TST-05/15/16 avant écrans ; exclusions concurrentes, publication, remplacements, pointage validé et indicateurs rapprochables.
7. **Lots 5–10** : détailler les exigences et décisions ouvertes avant leur réalisation. Les décisions métier/juridiques du SRS restent à obtenir auprès de leurs responsables.
