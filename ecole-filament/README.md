# Madrassa — école privée algérienne

Interface **Laravel 12 + Filament 5 + Livewire**, en français, pour le primaire (1AP–5AP), le moyen (1AM–4AM) et le secondaire (1AS–3AS). Adaptée au cahier des charges unifié fourni.

## Ce que contient cette livraison

Le Docker Compose à la racine active le **backend PostgreSQL connecté** (`SCHOOL_BACKEND=true`, `SCHOOL_DEMO=false`). Chaque déploiement dispose d’une école fixe, de sa base de données et de ses comptes de connexion. Aucun sélecteur d’établissement n’est affiché. Les pages utilisent l’API authentifiée avec contrôles de droits et données persistantes. Voir `../DOCKER.md` pour créer des déploiements séparés. Les descriptions de démonstration ci-dessous concernent uniquement le mode autonome avec `SCHOOL_BACKEND=false` ; les fonctionnalités sans endpoint backend sont signalées comme indisponibles en mode connecté.

30 écrans avec navigation Filament, formulaires et modales natifs, notifications, tableaux, fiches détaillées, filtres, recherche, pagination, export CSV, planning hebdomadaire et tableaux de bord. Le jeu fictif initial contient 226 enregistrements.

C’est un **frontend exécutable avec adaptateur de démonstration**, pas un backend scolaire prêt à la production. Les données métier sont dans la session ; SQLite conserve uniquement les comptes de connexion. Ne pas importer de vrais dossiers d’enfants. Les espaces enseignant/élève illustrent les parcours sans créer des autorisations séparées.

## Installation locale

Prérequis : PHP 8.3 ou 8.4, Composer 2, extensions `intl`, `mbstring`, `pdo_sqlite`, `dom`, `xml`, `fileinfo`, `curl`, `zip`, `openssl`, `session`, `ctype`, `tokenizer` et les extensions Laravel habituelles.

Aucun build Node n’est nécessaire. Filament fournit ses assets ; le complément visuel est du CSS local. Polices et avatars n’appellent aucun service externe.

```bash
composer install
cp .env.example .env
php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"
php artisan key:generate
php artisan migrate
php artisan filament:assets
php artisan school:user direction@example.test
php artisan serve --host=127.0.0.1 --port=8000
```

Le mot de passe est demandé dans le terminal (12 caractères minimum). Ouvrir **http://127.0.0.1:8000/admin**.

Sous PowerShell : `Copy-Item .env.example .env` remplace `cp` si nécessaire. Laravel Herd, Laragon et WSL conviennent avec les extensions requises. PostgreSQL n’est pas nécessaire pour cette démonstration.

Le panel nécessite `SCHOOL_DEMO=true` et `APP_ENV=local`. Son accès est désactivé en production, jusqu’à l’implémentation des permissions et services métier.

## Écrans

| Ensemble | Écrans |
|---|---|
| Pilotage | Tableau de bord |
| Scolarité | Élèves, admissions, inscriptions, classes/groupes, formations |
| Pédagogie | Matières/compétences, leçons, évaluations/devoirs, notes, présences, suivi |
| Organisation | Emploi du temps, salles |
| Équipe | Enseignants, personnel, heures assurées |
| Finances | Paiements, dépenses, rémunérations, synthèse |
| Communication | Annonces |
| Intelligence pédagogique | Affectations/optimisation, analyses/alertes |
| Espaces | Enseignant, élève |
| Administration | Import, comptes/habilitations, paramètres, journal |

## Parcours à essayer

1. Rechercher un élève, filtrer son cycle, ouvrir sa fiche et consulter ses inscriptions.
2. Créer un élève avec le bouton « Nouvel élève » puis inscrire ce dossier dans une formation/groupe compatibles.
3. Enregistrer un paiement partiel, consulter le solde, puis constater que l’état pédagogique reste indépendant.
4. Consulter le planning, basculer semaine/liste et essayer un créneau en conflit.
5. Faire l’appel avec les boutons P/A/R ; modifier une note et consulter le journal.
6. Télécharger le modèle CSV, préparer un fichier, examiner son aperçu puis confirmer l’import.
7. Consulter les espaces enseignant/élève et le parcours simulé de validation d’une proposition IA.

## Interactions implémentées

- Création et modification par formulaires Filament, avec validation ; changements conservés dans la session.
- Recherche, filtres, pagination (10 lignes), export des résultats filtrés en CSV compatible Excel.
- Fiches détaillées, solde d’inscription, calcul fixe/horaire des rémunérations ; impression navigateur du détail ou du reçu fictif.
- Cohérence cycle/niveau, capacité de classe, inscription en double, contrôle des montants, chevauchement groupe/enseignant/salle.
- Paiements/dépenses/rémunérations validés figés ; une correction liée reste à implémenter dans le backend.
- Présent/absent/retard ; note manquante distincte de zéro ; moyenne des notes publiées pondérée par coefficient.
- Pièces jointes fictives PDF/JPEG/PNG, 10 Mo maximum, stockage local privé et téléchargement authentifié depuis les fiches de la session.
- Import CSV avec vérification des en-têtes, dates, niveaux et doublons de nom ; les erreurs bloquent la confirmation.
- Journal avant/après, préférences de démonstration, réinitialisation avec confirmation.
- UI d’optimisation et d’alertes avec états explicites : **aucun modèle ML ni solveur n’est connecté**, aucune décision automatique.

Les préférences de politique d’accès sont uniquement illustratives. Les contrôles de démo ne garantissent ni transactions concurrentes, ni sécurité multiutilisateur de production. La trésorerie et les montants affichés sont des calculs fictifs, sans portée comptable.

## À connecter pour la production

PostgreSQL et isolation par établissement, rôles/policies/MFA, transactions et idempotence, fichiers sécurisés et conservation, imports XLSX, exports PDF/ICS, corrections/remboursements et clôtures, génération salariale liée aux dépenses, devoirs/quiz/dépôts, bulletins, notifications, jobs, OR-Tools et API Python. Voir `docs/INTEGRATION.md`.

Application, base, fichiers, sauvegardes, traitements et journaux doivent être hébergés en Algérie conformément au cahier des charges. Aucun déploiement ni service externe n’est créé par cette livraison.

## Code et tests

- `app/Filament/Pages/` : les 30 pages/routes.
- `app/Support/SchoolPage.php` : formulaires, actions et états de l’interface.
- `app/Support/DemoStore.php` : adaptateur session à remplacer par les services métier.
- `resources/demo/` : catalogue des champs et données fictives.
- `resources/views/filament/pages/` : vues Blade.
- `public/css/school.css` : thème responsive.
- `tests/FrontendTest.php` : chargement des pages et contrôles d’interactions.

```bash
vendor/bin/phpunit
```

Les éventuelles captures et le rapport de vérification sont dans `docs/`. Les uploads de démonstration restent dans `storage/app/private/demo-uploads` après une réinitialisation des données : prévoir leur nettoyage selon votre environnement.

Références : https://filamentphp.com/docs/5.x/introduction/installation · https://filamentphp.com/docs/5.x/navigation/custom-pages
