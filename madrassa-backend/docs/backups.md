# Sauvegarde automatique et restauration

Le service Docker `backup` sauvegarde PostgreSQL, tous les fichiers de `storage/app` (documents privés et fichiers publics) et la clé de chiffrement persistante. Redis contient les sessions et le cache : il repart vide après restauration, les utilisateurs se reconnectent.

## Activer

```powershell
docker-compose --profile backup up --build -d
docker-compose ps backup
docker-compose logs --tail 20 backup
```

Par défaut : chaque jour à **03:00, heure d'Algérie**, **14 sauvegardes** conservées, dossier `backups/madrassa`. Le premier démarrage et les sauvegardes manquées pendant un arrêt déclenchent une sauvegarde immédiate. Docker doit fonctionner pour exécuter le planning. Après `docker-compose down`, redémarrer avec `--profile backup` pour relancer aussi le service de sauvegarde.

Chaque sauvegarde place brièvement l'application en maintenance (HTTP 503), attend 15 secondes pour les requêtes en cours, puis sauvegarde les données et les fichiers. Ne pas lancer en parallèle d'importation ou de commandes administratives qui modifient les données. Un verrou empêche deux sauvegardes simultanées. Un échec libère la maintenance ; le service réessaie après cinq minutes. Une maintenance de sauvegarde interrompue brutalement est libérée au prochain essai. Les sauvegardes partielles ne sont jamais publiées comme réussies.

Variables dans le fichier `.env` du projet :

```dotenv
BACKUP_HOUR=3
BACKUP_KEEP=14
BACKUP_DIR=D:/SchoolBackups/madrassa
```

Choisir un dossier distinct par école. Recréer `backup` après un changement. Les sauvegardes contiennent des données privées et une clé secrète : conserver le dossier privé et copier régulièrement les sauvegardes sur un support externe sécurisé. Une copie sur le même disque protège contre les erreurs de données, mais pas contre la panne du disque. Les archives ne sont pas chiffrées par ce service. Elles sont exclues de Git et des images Docker.

## Vérifier ou sauvegarder maintenant

```powershell
docker-compose run --rm --no-deps backup backup
docker-compose run --rm --no-deps backup health
docker-compose run --rm --no-deps backup verify backup-YYYYMMDDTHHMMSSffffffZ
```

Chaque dossier terminé contient `database.dump`, `files.tar.gz` et `manifest.json` : date UTC, projet source, version PostgreSQL, nombre de lignes de chaque table et sommes SHA-256 des archives. `status.json` indique la dernière réussite ou erreur. Le contrôle de santé Docker signale un échec ou une dernière sauvegarde trop ancienne (>26 heures). Consulter les logs en cas de service `unhealthy` ; aucun courriel automatique n'est envoyé.

## Restaurer dans un nouveau déploiement

Utiliser les mêmes sources/images de l'application que la sauvegarde, puis migrer après restauration si nécessaire. Garder les images ou le code versionné avec le plan de reprise. Utiliser PostgreSQL 16. Le processus refuse toute base ou tout volume de fichiers non vide ; il ne supprime jamais les données de l'école existante.

Créer `recovery.env` à partir de `school.env.example` avec un **nouveau** nom de projet et un port libre :

```dotenv
COMPOSE_PROJECT_NAME=school-recovery
APP_PORT=8002
APP_URL=http://localhost:8002
DB_DATABASE=school_recovery
DB_PASSWORD=choose-a-new-private-password
BACKUP_DIR=D:/SchoolBackups/madrassa
```

Ne pas définir `APP_KEY` : la clé originale sera récupérée dans l'archive. Construire les images puis démarrer **seulement** PostgreSQL et Redis. Démarrer l'application avant la restauration créerait des tables et empêcherait la restauration.

```powershell
docker-compose --env-file recovery.env --profile backup build app backup
docker-compose --env-file recovery.env up -d postgres redis
docker-compose --env-file recovery.env run --rm --no-deps backup restore backup-YYYYMMDDTHHMMSSffffffZ
docker-compose --env-file recovery.env up -d app
```

La restauration vérifie les sommes des archives avant d'écrire, refuse les chemins de fichiers dangereux, restaure PostgreSQL en une transaction et compare le nombre de lignes de toutes les tables. La clé originale reste inchangée au démarrage de l'application. Comptes, permissions, bulletins, notes, inscriptions et paiements restent dans la base restaurée. Les fichiers privés conservent leur contenu. Les mots de passe de connexion restent les mêmes. Le mot de passe PostgreSQL vient du nouveau déploiement.

Ouvrir `http://localhost:8002/admin` et vérifier les dossiers, fichiers et connexions avant de basculer les utilisateurs. Modifier ensuite `BACKUP_DIR` pour donner au déploiement récupéré son **propre** dossier de sauvegarde et activer `--profile backup`. Conserver le déploiement d'origine jusqu'à validation. Les rôles PostgreSQL supplémentaires d'une configuration de production doivent être provisionnés séparément ; le Compose local utilise `school_owner`.

## Test de reprise

```powershell
docker-compose --profile backup build app backup
node tests/backups.cjs
```

Le test crée deux déploiements jetables : compte utilisateur et fichier privé chiffré dans le premier, sauvegarde, restauration dans le second, puis connexion avec le mot de passe original. Il vérifie la persistance de la clé après remplacement du conteneur, les comptes et fichiers restaurés, le refus d'une archive altérée, le refus d'écraser une base existante, la rétention et le rattrapage automatique. Il supprime uniquement les volumes des projets de test. Les archives fictives restent dans le dossier ignoré `backups/test-*`.

Pour répéter un exercice de reprise sur une sauvegarde existante de l'école, sans toucher à sa base :

```powershell
node tests/restore-backup.cjs backups/madrassa backup-YYYYMMDDTHHMMSSffffffZ
```

Cet exercice vérifie toutes les tables, les archives et le démarrage de l'application dans un déploiement temporaire sur le port 18194, puis supprime uniquement les volumes temporaires. Le rapport sans données personnelles est écrit dans `tests/artifacts/backup-restore-report.json`. Prévoir un exercice après une évolution majeure et régulièrement selon le plan de reprise de l'école.
