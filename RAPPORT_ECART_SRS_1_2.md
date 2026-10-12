# Madrassa — état d'application du SRS 1.2

Analyse du 11 octobre 2026, mise à jour avec les incréments 14 à 26. Référence : `SRS_Madrassa_1_2.md`, décisions §10.1 et rapport 1.1 déjà intégré. La suite reprend le plan existant ; aucune nouvelle décision d'architecture n'est introduite. Le document SRS source n'est pas modifié.

## Base retenue et preuves

`platform/` est la nouvelle application normative (ND-09) : Laravel 12, Filament 5, PostgreSQL, schéma incrémental, rôles SQL restreints, API et services. `ecole-filament/` et `madrassa-backend/` restent l'application antérieure ; leur couverture ne vaut pas conformité de la nouvelle base. Les bulletins et la démonstration sont hors périmètre (ND-02, ND-04).

Validation de l'incrément 26 : 160 tests PHP/PostgreSQL 16 et 153 contrôles SQL embarqués, sans fichier `.env` local ; 108 écrans vérifiés dans chacun des navigateurs Chromium, Firefox et WebKit, en français et en arabe, sur ordinateur et téléphone (`platform/deploy/acceptance-test.sh`, en CI) ; déploiement Docker Compose vérifié de bout en bout par `platform/deploy/smoke-test.sh`, sauvegarde chiffrée puis restauration dans une installation neuve par `platform/deploy/restore-test.sh` (les deux en CI) ; contrôles SQL natifs avec concurrence en CI sur PostgreSQL 16. La revue de sécurité du lot 1 est dans `platform/SECURITY-REVIEW.md`. Les tests utilisent des bases jetables, jamais les données de l'application antérieure. « PARTIEL » peut désigner une API testée dont le parcours utilisateur manque ; aucune ligne n'affirme la conformité complète du produit avant recette.

Abréviations de fichiers : **SQL** = `platform/database/schema/`, **API** = `platform/app/Http/Controllers/`, **services** = `platform/app/Support/`, **tests** = `platform/tests/Feature/` et `platform/tests/sql/`, **UI** = `platform/app/Filament/`, **CI** = `.github/workflows/ci.yml`. S/M/L : effort relatif d'un complément, pas un engagement calendaire.

## Exigences fonctionnelles

| ID | Statut | Preuves et complément nécessaire | Risque | Effort |
|---|---|---|---|---|
| FR-PLT-01 | PARTIEL | SQL, UI/Console, ConsoleTest, LotOneWalkthroughTest : deux établissements créés depuis la console, activés et utilisés de bout en bout ; recette utilisateur à terminer | majeur | S |
| FR-PLT-02 | PARTIEL | SQL, ConsoleTest : dépendances, quotas de stockage, lecture seule ; quota élèves avec lot 2 | majeur | M |
| FR-PLT-03 | PARTIEL | SQL, ReferentialTest : profils versionnés ; validation des libellés métier OD-13 | mineur | S |
| FR-PLT-04 | PARTIEL | SupportTest, SchoolPanelTest, SchoolAdministrationPanelTest, UI/School/SupportAccess : consentement, retrait, consultations, lecture seule et bannière ; recette utilisateur à terminer | majeur | S |
| FR-PLT-05 | PARTIEL | UI/Console/Supervision, ConsoleTest : comptages ; supervision et alertes d'exploitation à compléter | majeur | M |
| FR-IAM-01 | PARTIEL | AccountController, UI/School/Accounts et Activation, AccountTest, SchoolPanelTest, SchoolPublicPagesTest : cycle de vie et activation dans le navigateur ; recette utilisateur à terminer | majeur | S |
| FR-IAM-02 | PARTIEL | SessionController, CredentialController, SchoolLogin, Credentials, Recovery, acceptance-test : connexion avec MFA vérifiée dans un navigateur, verrouillage, mot de passe provisoire et oublié ; recette utilisateur | majeur | S |
| FR-IAM-03 | PARTIEL | RoleController, SQL, UI/School/Roles et Assignments, SchoolPanelTest ; recette utilisateur et présentation groupée des permissions à compléter | majeur | M |
| FR-IAM-04 | PARTIEL | Permissions, RoleAssignmentController ; portées groupe/personnelles à compléter avec lot 2 | bloquant | L |
| FR-IAM-05 | PARTIEL | SQL, AuthorizationTest : auto-attribution et auto-élévation refusées ; validations métier avec lots 3/4 | bloquant | M |
| FR-REF-01 | PARTIEL | ReferentialController, UI/School/ReferenceTypes, ReferentialTest, SchoolReferencePanelTest ; recette utilisateur à terminer | majeur | S |
| FR-REF-02 | PARTIEL | ReferentialController, UI/School/ReferenceNodes, SchoolReferencePanelTest ; références des lots suivants et recette utilisateur | majeur | M |
| FR-REF-03 | PARTIEL | SubjectController, UI/School/Subjects, SchoolReferencePanelTest ; utilisation par programmes au lot 2 et recette utilisateur | majeur | M |
| FR-REF-04 | PARTIEL | CustomFieldController, SQL, UI/School/CustomFields, SchoolAdministrationPanelTest ; saisie des valeurs avec les dossiers du lot 2 | majeur | M |
| FR-REF-05 | PARTIEL | CalendarController, UI/School/Years et Periods, SchoolReferencePanelTest ; clôture des dossiers futurs au lot 2 | majeur | M |
| FR-REF-06 | PARTIEL | SettingController, SQL, UI/School/Settings, SchoolAdministrationPanelTest ; logo, seuils finance et créneaux planning avec leurs lots | majeur | M |
| FR-AUD-01 | PARTIEL | AuditController, SQL, ServicesTest, UI/School/Journal, SchoolAdministrationPanelTest : filtres et détail avant/après ; objets métier futurs | majeur | M |
| FR-I18-01 | PARTIEL | Locales, lang/fr, lang/ar, LocalizationTest, PseudoLocalizationTest, acceptance-test : chaque écran du lot 1 en arabe, de droite à gauche, sans débordement sur ordinateur ni téléphone (AC1), captures en CI ; relecture des libellés arabes par un locuteur natif | majeur | S |
| FR-IMP-01 | PARTIEL | Imports, ImportController, ImportTest, UI/School/Imports, SchoolServicesPanelTest : modèle, aperçu, rejets, confirmation, annulation ; import des entités du lot 2 | majeur | M |
| FR-IMP-02 | PARTIEL | Exporter, ExportController, ExportTest, UI/School/Exports, SchoolServicesPanelTest ; jeux de données métier futurs | majeur | M |
| FR-FIC-01 | PARTIEL | Files, FileController, FileTest, stockage S3 Garage du déploiement (smoke-test) ; interfaces métier des lots suivants | majeur | M |
| FR-NOT-01 | PARTIEL | Outbox, NotificationController, ServicesTest, UI/School/Notifications et Deliveries, SchoolServicesPanelTest : compteur des non-lues, préférences, renvoi ; événements futurs | majeur | M |
| FR-JOB-01 | PARTIEL | Tasks, WithinSchool, JobController, ServicesTest, UI/School/Tasks, SchoolServicesPanelTest : suivi, annulation, relance, téléchargement ; supervision d'exploitation | majeur | M |
| FR-PDF-01 | PARTIEL | Pdf, vues/pdf, PdfTest ; modèles métier et recette visuelle arabe | majeur | M |
| FR-ELV-01 | ABSENT | platform : fiche élève à créer au lot 2 | majeur | L |
| FR-ELV-02 | ABSENT | platform : responsables et liens à créer au lot 2 | majeur | M |
| FR-ELV-03 | ABSENT | platform : statuts et archivage des élèves | majeur | M |
| FR-ELV-04 | ABSENT | platform : recherche et doublons des élèves | majeur | M |
| FR-CRM-01 | ABSENT | platform : prospects, pipeline et historique | majeur | M |
| FR-CRM-02 | ABSENT | platform : conversion transactionnelle | majeur | M |
| FR-SLL-01 | PARTIEL | SiteController, SQL, AuthorizationTest, UI/School/Sites, SchoolPublicPagesTest ; compléments lot 2 | majeur | M |
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
| BR-18 | PARTIEL | Codes SQL immuables, labels FR/AR de tous les catalogues de la plateforme (017) et rôles copiés ; futurs catalogues | majeur | M |
| BR-19 | PARTIEL | Notifications sans détails sensibles ; publication planning future | majeur | M |
| BR-20 | PARTIEL | RequireModule, Permissions, jobs, menu des sites absent sans son module (SchoolPublicPagesTest) ; menus et calculs des modules futurs | bloquant | M |

## Exigences non fonctionnelles

| ID | Statut | Preuves et complément nécessaire | Risque | Effort |
|---|---|---|---|---|
| NFR-PERF-01 | ABSENT | Test de charge et mesures p95 à produire | majeur | L |
| NFR-PERF-02 | ABSENT | Scénario mixte et environnement de mesure à documenter | majeur | M |
| NFR-PERF-03 | ABSENT | 50 tenants/25 000 élèves à mesurer après lot 2 | majeur | L |
| NFR-PERF-04 | PARTIEL | Jobs et exports > 2 000 en arrière-plan ; listes qui grandissent paginées (25 par défaut, 100 au plus) dans l'API et à l'écran (Paging, PagingTest) ; mesures de charge avec le lot 2 | majeur | S |
| NFR-PERF-05 | ABSENT | Poids compressé des pages à mesurer | mineur | M |
| NFR-SEC-01 | PARTIEL | Proxy Caddy : HTTPS seul, HSTS, cookies sécurisés, proxys de confiance (platform/compose.yaml, smoke-test) ; TLS entre conteneurs non requis sur le réseau privé, à revoir en multi-hôte | majeur | S |
| NFR-SEC-02 | PARTIEL | Argon2id et variables d'environnement ; rotation en exploitation à documenter | majeur | M |
| NFR-SEC-03 | PARTIEL | CSRF, Blade, SQL paramétré, fichiers et exports testés ; aucun HTML brut à l'écran ; CSP avec nonce sur chaque réponse (ContentSecurityPolicyTest, recette navigateur sans blocage dans trois navigateurs) ; reste `'unsafe-eval'` pour Alpine (constat F4) | majeur | S |
| NFR-SEC-04 | PARTIEL | Revue de sécurité du lot 1 (`platform/SECURITY-REVIEW.md`) : matrice d'accès de chaque route et écran, générée par `platform:access-matrix` et vérifiée par PermissionCoverageTest ; SchoolPanelTest, SchoolReferencePanelTest : permissions retirées, IDs étrangers et onglets périmés refusés ; identifiants réservés au rôle `platform_auth` (F2, F3 corrigés) ; contexte RLS non signé (F7) au lot 2 | bloquant | M |
| NFR-SEC-05 | PARTIEL | LoginThrottle, SchoolLogin, pages d'activation et de récupération limitées comme l'API, tests ; revue d'exploitation | majeur | S |
| NFR-SEC-06 | PARTIEL | Refusal, DatabaseErrors, liens chiffrés ; revue exploitation/logs à terminer | majeur | M |
| NFR-SEC-07 | PARTIEL | Audit droits et verrouillage ; alertes anormales non livrées | majeur | M |
| NFR-SEC-08 | PARTIEL | `composer audit --locked` dans la CI à chaque envoi (job PHP de platform) ; images Docker pas encore analysées | mineur | S |
| NFR-SEC-09 | PARTIEL | Sauvegardes de platform chiffrées (GnuPG AES-256), phrase secrète hors des archives, contrôles d'intégrité (platform/docker/backup) ; copie hors site à organiser par l'exploitant | majeur | S |
| NFR-TEN-01 | PARTIEL | SQL, deploy/roles.sql, tests ; RLS forcée vérifiée sur toute table `school`, présente et future, fonctions fermées à PUBLIC (tests/sql, revue de sécurité) ; base, Redis (avec mot de passe) et stockage sur un réseau interne hors de portée du proxy (smoke-test) ; revalider pour les tables des lots suivants | bloquant | S |
| NFR-TEN-02 | PARTIEL | SchoolIsolationTest, LotOneWalkthroughTest (deux établissements, API et écrans), fichiers/exports/jobs, SchoolPanelTest, SchoolReferencePanelTest ; étendre aux écrans des lots suivants | bloquant | M |
| NFR-TEN-03 | PARTIEL | CurrentSchool, WithinSchool, Files ; déploiement et prochains modules | bloquant | M |
| NFR-AVL-01 | ABSENT | SLO et mesure d'exploitation à contractualiser | majeur | M |
| NFR-REC-01 | PARTIEL | Sauvegarde quotidienne de la base, du bucket et de la clé, 14 conservées, santé du service (DEPLOY.md) ; copie hors site et RPO à valider en exploitation | bloquant | S |
| NFR-REC-02 | PARTIEL | Restauration testée en CI dans une installation neuve : école, connexion, fichiers et clé ; exercice de reprise réel et RTO à mesurer | bloquant | S |
| NFR-REC-03 | ABSENT | Validation RPO par la finance requise hors code | majeur | S |
| NFR-OPS-01 | PARTIEL | CI migrations, request_id, déploiement Compose et DEPLOY.md ; supervision et environnements de recette à compléter | majeur | M |
| NFR-DATA-01 | PARTIEL | Aucun cloud imposé ; résidence des services à vérifier au déploiement | bloquant | M |
| NFR-DATA-02 | ABSENT | Validation juridique OD-08 hors code | bloquant | L |
| NFR-DATA-03 | ABSENT | Politique OD-07, purge et anonymisation à créer | majeur | L |
| NFR-DATA-04 | PARTIEL | Exports par domaine ; export tenant complet et procédure de rectification absents | majeur | L |
| NFR-DATA-05 | PARTIEL | Fichiers privés, export signé, sauvegardes chiffrées ; procédure de purge des sauvegardes selon OD-07 | majeur | S |
| NFR-DATA-06 | PARTIEL | Tests avec données fictives ; règles à maintenir avec lots suivants | majeur | S |
| NFR-UX-01 | PARTIEL | Console et panneau école Filament ; autres écrans et recette utilisateur à terminer | majeur | L |
| NFR-UX-02 | PARTIEL | Composants Filament ; ordinateur et téléphone vérifiés en navigateur (acceptance-test) ; tablette et recette utilisateur | majeur | S |
| NFR-UX-03 | PARTIEL | Composants accessibles ; audit clavier/contraste AA à faire | majeur | M |
| NFR-UX-04 | PARTIEL | Catalogues d'erreur, confirmations console/comptes/droits/référentiel/calendrier/administration ; autres interfaces tenant à créer | majeur | M |
| NFR-UX-05 | PARTIEL | Validation et notifications existantes ; interruptions UI à éprouver | mineur | M |
| NFR-COMP-01 | PARTIEL | Chromium (Chrome en CI, Edge en local), Firefox et WebKit (moteur de Safari) : chaque écran en CI ; Safari sur un appareil Apple et tablette à la recette | mineur | S |
| NFR-I18N-01 | PARTIEL | FR/AR et pseudo-localisation console/comptes/droits/référentiel/calendrier/administration ; autres écrans tenant et recette visuelle, anglais ultérieur | majeur | M |
| NFR-I18N-02 | PARTIEL | PdfTest, polices embarquées ; documents métier et recette visuelle | majeur | M |
| NFR-I18N-03 | PARTIEL | search_key arabe/latin testé ; translittération non généralisée | mineur | M |
| NFR-PORT-01 | PARTIEL | Image Docker et Compose de platform (app, worker, scheduler, migrations, PostgreSQL, Redis, Garage, Caddy) ; hébergement réel à valider | majeur | S |
| NFR-PORT-02 | PARTIEL | Socle PHP/SQL sans GPU ; vérifier futurs processus | mineur | S |
| NFR-MNT-01 | PARTIEL | CI SQL/PHP/migrations ; analyse statique et audit dépendances à ajouter | majeur | M |
| NFR-MNT-02 | PARTIEL | Tests des invariants fondations ; lots 2–4 non réalisés | bloquant | L |
| NFR-MNT-03 | PARTIEL | platform/README ; guides API/administration/exploitation à compléter | majeur | M |
| NFR-MNT-04 | PARTIEL | Migrations et profils versionnés ; paramètres de calcul au lot 3 | majeur | M |

## Suite ordonnée par lot

1. **Lot 1, incrément 14 livré** : entrée du panneau tenant, sélection sûre d'école, comptes/rôles/assignations, sécurité personnelle et bannière support ; tests d'isolation, permissions, séparation, suspension, expiration, MFA et pseudo-localisation. Les actions réutilisent les validations et fonctions SQL existantes. Les refus SQL sont isolés par savepoint pour laisser la transaction du panneau utilisable. Les anciennes applications ne sont pas modifiées.
2. **Lot 1, incrément 15 livré** : types et nœuds du référentiel, catalogue matières/modules, années et périodes ; libellés FR ou AR, parents compatibles, archivage, historique, suppression des matières inutilisées, dates et clôture définitive. Recherche normalisée, versions optimistes et contrôles de permission/isolation repris dans les écrans. Les listes paginent l'affichage ; les API de catalogue chargent encore toutes les lignes.
3. **Lot 1, incrément 16 livré** : paramètres, champs personnalisés, consentements support et journal ; libellés arabes de tous les catalogues de la plateforme.
4. **Lot 1, incrément 17 livré** : mes tâches, notifications et préférences, e-mails non envoyés, imports et exports à l'écran.
5. **Lot 1, incrément 18 livré** : activation et récupération dans le navigateur, sites selon leur module.
6. **Lot 1, incrément 19 livré** : déploiement Docker Compose (HTTPS, rôles restreints, stockage S3, worker, planificateur), vérifié en CI.
7. **Lot 1, incrément 20 livré** : sauvegardes chiffrées et restauration vérifiée dans une installation neuve, en CI.
8. **Lot 1, incrément 21 livré** : recette de chaque écran dans un navigateur, en français et en arabe, ordinateur et téléphone, en CI.
9. **Lot 1, incrément 22 livré** : pagination des listes qui grandissent, dans l'API et à l'écran, avec recherche tolérante.
10. **Lot 1, incrément 23 livré** : parcours de sortie (deux établissements créés depuis la console et utilisés de bout en bout, R01, R09, R10) ; revue de sécurité des permissions (`platform/SECURITY-REVIEW.md`), qui a fermé à PUBLIC toutes les fonctions des schémas ; audit des dépendances en CI.
11. **Lot 1, incrément 24 livré** : Redis protégé par mot de passe, base, Redis et stockage sur un réseau interne (constat F5) ; chaque écran vérifié dans Chromium, Firefox et WebKit (constat F6).
12. **Lot 1, suite** : relecture des libellés arabes par un locuteur natif, sur les captures de la recette, avec le choix d'une police arabe (la police des écrans n'a pas de lettres arabes). Le lot 1 ne peut pas être déclaré terminé avant ce point.
13. **Lot 2, incrément 25 livré** : identifiants (empreintes de mot de passe, secrets MFA, codes de secours) réservés au rôle `platform_auth` ; `school_runtime` n'en lit ni n'en modifie aucun (constats F2 et F3).
14. **Lot 2, incrément 26 livré** : politique de sécurité du contenu (CSP) avec nonce sur chaque réponse, vérifiée dans trois navigateurs (constat F4, `'unsafe-eval'` restant) ; avatars dessinés par la plateforme au lieu de ui-avatars.com, qui recevait les initiales de la personne connectée (constat F8).
15. **Lot 2, suite** : contexte de requête signé et vérifié par la base (constat F7) ; version d'Alpine sans évaluation (reste de F4) ; ensuite schéma, RLS et invariants TST-03/07/08/18, puis dossiers et parcours élève → inscription → groupe ; import des 500 élèves.
16. **Lot 3** : TST-09–13/19 avant écrans ; centimes exacts, idempotence, séparation, immutabilité, clôture et rapprochement des versements.
17. **Lot 4** : TST-05/15/16 avant écrans ; exclusions concurrentes, publication, remplacements, pointage validé et indicateurs rapprochables.
18. **Lots 5–10** : détailler les exigences et décisions ouvertes avant leur réalisation. Les décisions métier/juridiques du SRS restent à obtenir auprès de leurs responsables.
