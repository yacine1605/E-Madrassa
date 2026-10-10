# Validation de la livraison

Vérifié le 27 septembre 2026 ; suite SQL réparée et étendue le 10 octobre 2026. L’intégration continue (`.github/workflows/ci.yml`) rejoue ces contrôles à chaque envoi.

| Vérification exécutée | Résultat |
|---|---|
| Schéma complet (`database/schema/0NN_*.sql`, les mêmes fichiers que les migrations) sous PostgreSQL embarqué PGlite 0.5.8 | 54 contrôles réussis |
| Même suite sous PostgreSQL 16 natif, avec trois courses sur connexions indépendantes (paiements, dernière place, référence de paiement) | 57 contrôles réussis |
| Séparation des tâches (heures, rémunérations), brouillon de paie, archivage, codes définitifs, audit avant/après masqué, contre-opération de paiement, référence unique | Réussite |
| Suites navigateur Docker (10 octobre 2026) : `teacher-pay`, `modular-schools`, `student-lifecycle`, `teacher-portal`, `backups`, `dedicated-schools` (avec `backend-browser`), `report-cards`, `report-card-archives`, `backend-design` | 9 réussies, application servie par le rôle restreint `school_runtime` |
| Rôle des connexions web après `config:cache` lancé en propriétaire (journal des connexions PostgreSQL) | `school_runtime` uniquement |
| Rôle SQL runtime sans BYPASSRLS, adhésions actives/inactives, lecture/écriture entre tenants | Réussite |
| Capacité, prix figé, facturation, dépassements, remboursements et décaissements | Réussite |
| Planning ressources et élève entre formations, notes publiées, présences et contrats | Réussite |
| Syntaxe PHP 8.3 | Réussite |
| Installation dans une copie du frontend existant et chargement Laravel | 19 routes `school-api` enregistrées |
| Route CSRF, lecture non authentifiée, mutation sans CSRF | Respectivement 200, 401 et 419 |

Le test Laravel charge les dépendances du frontend existant. Les quatre contrôles de bootstrap sont distincts des 34 contrôles de la base. La base PostgreSQL embarquée utilise un seul processus : ces résultats ne démontrent pas la concurrence réseau, le débit, le parcours HTTP authentifié complet, les permissions de chaque profil ou la reprise après incident.

## Reproduire

```bash
npm ci
npm test
php tests/bootstrap.php /chemin/absolu/ecole-filament
```

Le test natif utilise un **nouveau schéma dans une base vide jetable** ; son nom doit finir par `_test`. Le compte de test doit pouvoir créer le rôle runtime et exécuter `SET ROLE`. Ne pas fournir une base contenant des données à conserver.

```bash
# Créer d’abord, avec le DBA, une base vide madrassa_test.
# DATABASE_URL doit viser cette base avec le compte d’administration de test.
npm run test:concurrency
```

Avec `DATABASE_URL` défini, ce test exécute la même suite sur PostgreSQL natif puis trois courses sur connexions indépendantes : deux paiements de 7 000 sur une dette de 10 000, deux paiements portant la même référence, et deux inscriptions sur la dernière place. Il exige exactement un succès et un rejet de contrainte dans chaque course. Elle a été exécutée le 10 octobre 2026 (57 contrôles) et tourne en intégration continue. Recréer une base vide pour chaque passage ; le test ne supprime pas automatiquement les données.

L’idempotence applicative est implémentée dans `SchoolService` mais n’a pas de test HTTP authentifié exécuté ici. La recette d’intégration doit envoyer deux corps identiques avec la même clé, puis un corps différent avec cette clé, et vérifier respectivement le même identifiant puis 409. Étendre aussi la recette aux révocations d’adhésion, doublons de commandes de paie, sessions Redis partagées et restauration de sauvegarde.
