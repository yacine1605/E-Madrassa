# Intégration au backend

Filament génère une interface Laravel/Livewire côté serveur. Une API REST distincte n’est pas nécessaire pour toute opération PHP ; les services Python restent dédiés à l’optimisation et aux analyses. Laravel autorise et applique les propositions après validation humaine.

## Remplacer l’adaptateur

Les vues utilisent `DemoStore::all`, `find`, `save`, `options`, `paid`, `balance`, `totalPay`. Déplacer les mutations dans des services avec Form Requests/validation, Policies, transactions et audit persistant. Ne pas remplacer simplement la session par une table JSON.

| Domaine | Travail de production restant |
|---|---|
| Identités | Établissement, années, utilisateurs, rôles cumulables, MFA, révocation et récupération |
| Scolarité | Élèves/responsables légaux, matricule stable, formations, groupes, inscriptions et affectations datées ; capacité sous transaction |
| Pédagogie | Compétences, leçons/version, quiz/questions/tentatives, devoirs/dépôts, notes et règles versionnées, validation/publication, bulletins |
| Personnel | Disponibilités, habilitations, contrats/tarifs versionnés, heures assurées validées, remplacements |
| Finance | Décimaux exacts/Money (les floats de démo sont à remplacer), échéances, idempotence, corrections liées, remboursement distinct, clôtures |
| Rémunérations | Génération mensuelle unique, détail figé, versements liés aux dépenses sans double comptage |
| Planning | Calendrier daté, exceptions, conflits entre formations d’un élève, pauses, volumes, habilitations, verrous et versions |
| Import/export | XLSX, mapping, rapprochement, matricule, rejets, reprise idempotente, PDF/ICS et jobs |
| IA | Données versionnées, modèle/solveur, queue, état de tâche, diagnostics, audit, revue puis publication contrôlée |

## Habilitations

Secrétariat : dossiers et inscriptions ; encaissement uniquement sur délégation. Pédagogie : paramètres, groupes, planning, résultats et suivi. Finance : montants, dépenses, rémunérations ; droits de saisie/validation/correction/export séparés. Enseignant : ses groupes, matières et heures. Élève : uniquement ses propres cours autorisés et résultats publiés. Technique : exploitation sans accès automatique aux données scolaires/financières.

Les aperçus enseignant et élève sont des démonstrations sous le compte de direction. Remplacer les identifiants fixes par l’identité authentifiée et vérifier le périmètre à chaque requête, y compris les actions Livewire, exports et fichiers. Cacher un menu ne suffit pas.

## Portée des simulations

Le planning vérifie groupe, salle et enseignant pour une semaine type. Il ne garantit pas les volumes, habilitations, conflits individuels entre formations, jours fériés ou faisabilité globale. Les propositions d’optimisation sont fixes et explicitement non calculées.

Les alertes ML n’exposent aucun score inventé. Les indicateurs descriptifs sont calculés sur la démo. Prévoir comparaison à une règle simple, validation temporelle, qualité des données et possibilité de désactivation. Aucune sanction, décision RH ou décision de passage automatique.

## Mise en service

Tout héberger en Algérie : application, PostgreSQL, workers, services Python, fichiers privés, sauvegardes et journaux. Prévoir TLS, secrets, restriction des fichiers, analyse de contenu, rétention, sauvegardes chiffrées et restauration testée. Valider les règles scolaires/financières avec l’école. Tester confidentialité, accès directs, concurrence, charge et reprise avant déploiement. Aucun engagement légal, fiscal ou de performance n’est certifié par ce frontend.
