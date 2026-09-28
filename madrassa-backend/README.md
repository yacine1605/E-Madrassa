# Madrassa — backend Laravel et PostgreSQL

Extension du projet **Madrassa FilamentPHP** livré précédemment. Compatible Laravel 12 / PHP 8.3+, Filament 5 et PostgreSQL 16+. Elle ajoute une API de gestion, des services transactionnels et **33 tables**. Le frontend de démonstration reste intact : ses écrans utilisent encore `DemoStore` jusqu’au raccordement décrit dans `docs/FILAMENT.md`.

## Ce qui fonctionne dans cette livraison

- Plusieurs établissements, chacun avec ses campus, programmes, niveaux, périodes et utilisateurs habilités.
- Même modèle pour une école primaire/moyenne/secondaire, un centre de langues, une formation professionnelle ou un établissement mixte.
- Élèves, responsables, personnel, catalogue, offres et groupes ; inscriptions simultanées à plusieurs formations, avec capacité et tarif figé.
- Facture par inscription, paiements partiels, remboursements, dépenses, décaissements et soldes calculés. Montants entiers en **centimes de DZD**, jamais en flottants.
- Séances datées, conflits enseignant/salle/groupe et élève entre formations ; présences, notes et publication ; le manque de note n’est pas un zéro.
- Contrats, heures validées, génération mensuelle de rémunération et engagement de dépense associé une seule fois.
- Permissions cumulables, isolation PostgreSQL RLS, clés étrangères incluant l’établissement, audit et événements transactionnels dans une outbox.

L’API est un **backoffice**. Elle n’expose pas encore de portail enseignant/élève/parent. Les tables de documents préparent le stockage privé, mais aucun endpoint de téléversement n’est livré. Les imports XLSX, reçus PDF, échéanciers multiples, avoirs, cotisations fiscales/sociales, quiz, optimisation IA et livraison des notifications restent des extensions explicites. La rémunération calculée est un montant de gestion, pas une fiche de paie réglementaire.

## Installation dans le projet existant

Travailler d’abord sur une copie du projet Filament et une base PostgreSQL vide. Le script refuse d’écraser un fichier différent et n’exécute aucune migration.

```bash
python3 scripts/install.py /chemin/vers/ecole-filament
cd /chemin/vers/ecole-filament
composer install
php artisan optimize:clear
```

Configurer la connexion à partir de `deploy/backend.env.example`. Le fournisseur ajoute la connexion `pgsql` sans remplacer la configuration SQLite existante. Ajouter `pdo_pgsql` à PHP. Pour Redis, installer `phpredis`, les configurations de cache, session et connexion Redis sont fournies. Aucun worker métier n’est activé automatiquement : les événements restent dans l’outbox jusqu’à l’ajout d’un consommateur. À défaut, utiliser `SESSION_DRIVER=file`, `CACHE_STORE=file`, `QUEUE_CONNECTION=sync` pour une seule instance de développement, puis configurer Redis avant réplication.

Pour une base locale de développement :

```bash
# Dans ce dossier ; le mot de passe est à choisir dans l’environnement.
docker compose -f deploy/compose.yaml up -d
```

1. Exécuter `php artisan migrate` avec le compte **propriétaire des migrations**, `DB_CONNECTION=pgsql`. Les migrations frontend créent `public.users` avant `school.*`. En production, utiliser les options Laravel habituelles d’exécution non interactive après validation du déploiement.
2. Exécuter `deploy/runtime-grants.sql` avec le DBA. Définir séparément le mot de passe du rôle `school_runtime`, par exemple avec `\password school_runtime` dans `psql` ; ne pas le placer dans l’historique shell.
3. Créer le premier utilisateur via la commande existante `php artisan school:user adresse@example.dz` avec le compte de provisionnement. Son mot de passe est demandé interactuellement.
4. Provisionner l’établissement et le premier responsable :

```bash
psql "$PROVISION_DATABASE_URL" -v ON_ERROR_STOP=1 \
  -v tenant_name='Mon établissement' -v tenant_kind='mixed' -v user_id=1 \
  -f deploy/provision-tenant.sql
```

Le compte de provisionnement doit pouvoir contourner RLS pour créer l’institution avant sa première adhésion ; il n’est jamais utilisé par l’application. Le script accorde tous les droits métier au premier responsable. Ajuster ensuite les adhésions selon les fonctions réelles. Le rôle technique n’a aucun droit métier implicite.

5. Basculer l’application vers `DB_USERNAME=school_runtime`, puis reconstruire sa configuration. Ne jamais utiliser le superutilisateur du conteneur pour les requêtes HTTP.
6. En local uniquement : `DB_SSLMODE=disable`, `SESSION_SECURE_COOKIE=false` si HTTP. En production : HTTPS, cookies sécurisés, `APP_DEBUG=false`, connexion DB chiffrée et Redis privé/authentifié.

Le backend propose sa propre authentification de session `/school-api/login`. La restriction locale du panneau Filament de démonstration n’est pas supprimée automatiquement.

## Vérification

```bash
npm ci
npm test
php tests/bootstrap.php /chemin/vers/ecole-filament
```

34 contrôles SQL ont été exécutés sur PostgreSQL embarqué PGlite, ainsi que 4 contrôles Laravel de chargement des routes, session CSRF et refus des accès non authentifiés. Tous passent. Le PHP a également été vérifié avec PHP 8.3. Voir `docs/VALIDATION.md` pour les limites et les tests de concurrence natifs fournis.

## Lecture du dossier

| Fichier | Contenu |
|---|---|
| `database/schema/001_school.sql` | Schéma exécutable, contraintes, index, RLS, déclencheurs, vue des soldes |
| `database/migrations/2026_09_27_000001_create_school_backend.php` | Migration Laravel |
| `app/School/Services/SchoolService.php` | Inscription, idempotence, opérations financières, notes et rémunération |
| `config/school_backend.php` | Catalogue d’API, validation, permissions et connexion |
| `docs/API.md` | Routes, authentification, exemples et conventions |
| `docs/SCHEMA.md` | Dictionnaire et relations |
| `docs/ARCHITECTURE.md` | Isolation, performances, déploiement et limites |
| `docs/FILAMENT.md` | Raccordement au frontend existant |

Le schéma se régénère avec `python3 scripts/build_schema.py`. Après un premier déploiement, ne modifier ni une migration déjà appliquée ni le SQL initial : écrire des migrations incrémentales.
