# Raccordement au frontend livré

Le frontend possède désormais un mode connecté, activé par `SCHOOL_BACKEND=true` dans le Docker Compose racine. `SchoolPage` sélectionne `BackendPage` dans ce mode et conserve `DemoPage` pour la démonstration autonome. Les actions passent par l’API same-origin existante ; les contrôles d’adhésion, les permissions, la validation et les transactions de `TenantContext` s’appliquent à chaque requête.

Le navigateur charge automatiquement l’école fixe du déploiement via `/school-api/school`, puis les ressources de la rubrique avec pagination. Aucun sélecteur d’établissement n’est affiché. Chaque école a sa base PostgreSQL et ses comptes de connexion, dans un projet Compose distinct. Les formulaires utilisent les règles du catalogue et les opérations dédiées : inscriptions, import CSV atomique des élèves, paiements, remboursements, décaissements, présences, notes et rémunérations. Les transitions nécessitent un motif. Le panneau exige une adhésion active à l’école du déploiement ; `school:provision` prépare celle du premier utilisateur en local, puis rattache les autres comptes à cette même école.

Les admissions, interventions, importations autres que les élèves CSV, paramètres, gestion des utilisateurs, optimisation et portails restent indisponibles faute d’endpoints dédiés. Il n’y a pas de modification générique ni de suppression des enregistrements : seules les opérations exposées par le backend sont proposées. Les tableaux connectés remplacent les vues et statistiques de démonstration ; le planning connecté affiche actuellement les séances en liste.

Voir `../../DOCKER.md` et `../../tests/backend-browser.cjs` pour l’installation et la vérification navigateur. Les correspondances ci-dessous décrivent les domaines ; les conseils suivants restent applicables aux futures extensions.

| Démonstration | Backend |
|---|---|
| `students` | `students`, `guardians`, `student_guardians` |
| `formations` | `programs` → `levels` → `offerings` |
| `groups` | `cohorts` |
| `enrollments` | `enrollments` + `invoices` |
| `payments`, `finance` | `payments`, `refunds`, `invoice_balances`, résumé |
| `schedule` | `sessions`, dates réelles et fuseau explicite |
| `grades`, `assessments`, `attendance` | mêmes domaines, UUID et notes nullables |
| `teachers`, `staff` | `staff` ; enseignant = `is_teacher` |
| `hours`, `payroll`, `expenses` | `work_logs`, `contracts`, `payroll_runs`, `expenses`, `disbursements` |
| `lessons` | `lesson_versions` |
| `audit` | `audit_events` |

## Ordre conseillé

1. Résoudre l’école depuis `school.deployment`, côté serveur. Ne jamais accepter un choix navigateur comme une autorisation. Le middleware vérifie l’UUID fixe du déploiement, l’adhésion active et les droits à chaque appel.
2. Remplacer les appels à `DemoStore::all/save` par un adaptateur propre à chaque domaine. Les payloads diffèrent : une inscription n’accepte plus de prix libre et un paiement vise une facture. Les identifiants ne sont plus numériques.
3. Les actions Filament/PHP qui appellent directement `SchoolService` doivent ouvrir un contexte transactionnel **à chaque requête Livewire**, avec exactement les contrôles du middleware `TenantContext` : utilisateur connecté, adhésion active, réglages `SET LOCAL`, droits dans la requête. Ne pas se contenter du middleware de chargement initial du panneau. Préférer l’API same-origin pour la première intégration, ou extraire ce contexte dans un service partagé testé avant de l’utiliser côté Livewire.
4. Remplacer `canAccessPanel` de la démonstration par une vérification d’adhésion adaptée ; conserver les autorisations serveur par opération. Cacher un menu ne protège pas une requête.
5. Utiliser les montants entiers et formater à l’affichage seulement. Dériver l’état financier du solde et de l’échéance ; ne pas modifier l’état pédagogique lors d’un paiement.
6. Ne pas réutiliser les fixtures de démonstration comme comptes, rôles ou données réelles. Préparer un import contrôlé avec correspondances d’UUID et rapport d’erreurs.

Les portails enseignant/élève doivent avoir leurs propres endpoints filtrés sur l’identité et les affectations. Ne leur accorder aucun des droits de lecture globaux du backoffice en attendant ces endpoints. Les documents doivent rester hors `public/`, avec autorisation sur l’objet pour chaque téléchargement.
