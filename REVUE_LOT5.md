# Revue du lot 5 : pédagogie

Le lot 5 couvre les cours et leurs leçons, les activités (quiz et devoirs maison), les tentatives, les remises, la correction, la publication des résultats, les règles de notation, les moyennes et le carnet de notes (FR-LMS-\*). Le SRS 1.2 le cadre ; `SPECIFICATION_LOT5_PEDAGOGIE.md` le détaille et fixe ses critères d'acceptation : les sept parcours de son §8, les réponses attendues de son §7, et la trace du journal (BR-09). Ses valeurs « par défaut » sont retenues.

Aucune validation humaine n'est exigée par la spécification : la sortie se constate sur les contrôles automatiques. Les choix laissés à l'établissement sont listés plus bas. Toutes les données utilisées sont des exemples.

## Les contrôles

Les contrôles ci-dessous ont tourné les 1er et 2 novembre 2026, à la livraison de l'incrément 106 (les navigateurs Firefox et WebKit le 2, la première série ayant été interrompue par un redémarrage de Docker). La CI GitHub étant bloquée par la facturation du compte, ses tâches de la plateforme ont été exécutées en local, une à une :

- 324 tests PHP sur PostgreSQL 16 (18 545 assertions), dont `LotFiveWalkthroughTest` ;
- 278 contrôles SQL embarqués et 309 contrôles natifs avec concurrence ;
- les 88 migrations annulées sur la base remplie par la suite (schéma `school` vérifié disparu), puis réappliquées ;
- audit des dépendances : deux avis moyens publiés depuis la revue du lot 4, Livewire (contournement d’autorisation par `#[Authorize]`, attribut que la plateforme n’emploie pas) et `symfony/mime` (injection d’en-tête) ; corrigés par mise à jour de correctif (Livewire 4.4.8, Symfony 7.4.22), l’audit est vierge ;
- déploiement de bout en bout, puis sauvegarde et restauration ;
- 308 écrans en Chromium, Firefox et WebKit, sans problème (les écrans de l’élève sont parcourus par `PupilScreensTest`, les comptes de la recette étant du personnel).

Le parcours de bout en bout est `LotFiveWalkthroughTest` : un responsable pédagogique, un enseignant affecté, trois élèves dont une sans accès, les sept parcours dans l'ordre, par l'API. Les écrans sont parcourus par `CourseScreensTest`, `ActivityScreensTest` (enseignant et responsable) et `PupilScreensTest` (élève).

## Les sept parcours (§8)

| Parcours | Exigé | Contrôlé |
|---|---|---|
| 1. Créer et publier un cours | chapitres ordonnés, leçons structurées, aperçu puis publication d'une leçon, d'un chapitre ou du cours ; ressource externe d'un enseignant sans modifier la leçon ; URL non HTTPS et HTML refusés | `LotFiveWalkthroughTest` (§8.1), `CourseTest`, `CourseScreensTest`, tests/sql |
| 2. Publier une activité | l'enseignant affecté publie pour son groupe ; un groupe non enseigné refusé côté serveur, même par requête directe ; le responsable administre l'activité | `LotFiveWalkthroughTest` (§8.2 : `403 lms_group_forbidden` sur l'autre groupe, coefficient changé et republié par le responsable), `ActivityTest`, `ActivityScreensTest` |
| 3. Consulter | l'élève avec accès ne voit que son audience ; sans accès, ni cours ni activité ; le retrait d'accès bloque aussitôt les travaux et garde les notes publiées en lecture | `LotFiveWalkthroughTest` (§8.3 et §1 : élève non validée refusée par adresse directe ; inscription suspendue : plus d'activité ni de remise, résultats et moyenne toujours lus), `PupilScreensTest` |
| 4. Passer un quiz | démarrer, perdre la connexion, reprendre la même tentative avec le temps restant, envoyer ; fin à `min(début + durée, échéance)` ; un renvoi ne crée aucun doublon | `LotFiveWalkthroughTest` (§8.4), `QuizAttemptTest`, `PupilScreensTest` (réponses enregistrées à l'écran, minuteur du serveur), tests/sql (réponse refusée après la fin, clôture à expiration) |
| 5. Remettre un devoir | accusé horodaté ; liens dans l'historique ; lien inaccessible → demande de remplacement datée ; lien interdit refusé ; retard selon la politique et marqué | `LotFiveWalkthroughTest` (§8.5 : relance sans doublon, trois versions, notification avec l'heure limite, retard refusé ou accepté et marqué), `HomeworkSubmissionTest`, `PupilScreensTest`, tests/sql |
| 6. Corriger et publier | QCM corrigé automatiquement, question ouverte à corriger ; aucune note avant publication ; une note seulement dans le compte de son destinataire | `LotFiveWalkthroughTest` (§8.6 : 6 points automatiques et une question en attente ; ni résultat ni moyenne avant publication ; un camarade ne lit ni le résultat ni la tentative ; message sans note), `ResultTest`, `ActivityScreensTest` |
| 7. Calculer la moyenne | seules les notes publiées comptent ; une activité non remise reste manquante jusqu'au zéro explicite ; absence excusée et note en brouillon exclues ; une règle d'une période ultérieure ne change pas l'historique | `LotFiveWalkthroughTest` (§8.7 : 9 puis 7,5 sur 10 ; 5 puis 3,5 après le zéro motivé ; règle suivante sur 20 sans effet sur la période passée), `GradingTest`, tests/sql |

**Verts.** Chaque règle est tenue par la base (fonctions et politiques des SQL 077 à 087) : une requête qui contournerait l'application est refusée de la même façon.

## Les réponses attendues (§7)

| Situation | Réponse | Contrôle |
|---|---|---|
| Enseignant non affecté | `403 lms_group_forbidden`, sans rien révéler du groupe | `LotFiveWalkthroughTest`, `ActivityTest`, tests/sql |
| Élève sans accès ou hors audience | `403 lms_access_denied`, aussi par adresse directe | `LotFiveWalkthroughTest`, `CourseTest` |
| Résultat non publié ou d'un camarade | refus sans révéler l'existence de la note | `LotFiveWalkthroughTest`, `ResultTest` |
| Activité pas ouverte, échéance passée, tentative expirée | `409 lms_activity_not_open`, `lms_deadline_passed`, `lms_attempt_expired` | `LotFiveWalkthroughTest` (pas ouverte, échéance passée), tests/sql (échéance passée, réponse après la fin de la tentative) |
| Tentatives épuisées | `409 lms_attempts_exhausted`, les tentatives restant lisibles par leur auteur | `LotFiveWalkthroughTest`, `QuizAttemptTest` |
| URL sans HTTPS ou hors liste blanche | `422 lms_link_invalid` ou `lms_link_domain_blocked`, sans requête vers l'URL | `LotFiveWalkthroughTest`, `CourseTest`, `HomeworkSubmissionTest`, `PupilScreensTest` |
| Double envoi après coupure | la clé d'idempotence renvoie ce qui existe | `LotFiveWalkthroughTest` (tentative, envoi, remise), tests/sql |
| Question modifiée après une tentative | nouvelle version ; la tentative garde la sienne | `LotFiveWalkthroughTest` (la relecture montre l'énoncé d'origine) |

## Le journal (BR-09)

Auteur, date, objet, ancienne et nouvelle valeur, motif des dérogations, version et adresse IP. Le parcours a trouvé un manque : le motif d'un zéro pour non-remise ou d'une absence excusée était gardé sur la note mais pas dans le journal. **Corrigé par le SQL 087** : le motif accompagne désormais les entrées du journal de ce changement (création de la note et son recalcul, ancien et nouvel état), comme celui d'une recorrection. Contrôlé par tests/sql (résultats) et `LotFiveWalkthroughTest`.

## Les exigences du lot 5

| Exigence | État |
|---|---|
| FR-LMS-01 Leçons | conforme |
| FR-LMS-02 Devoirs maison | conforme |
| FR-LMS-03 Quiz | conforme |
| FR-LMS-04 Notes et moyennes simples | conforme |
| FR-LMS-05 Progression (SHOULD) | **à faire** : ouverture, achèvement déclaré, maîtrise vérifiée. Exigence souhaitable, non requise par les parcours du §8 ; elle n'empêche pas la sortie. |

## Choix à confirmer par l'établissement

Les valeurs par défaut de la spécification sont en place ; chacune est un réglage de l'école ou un choix de l'activité :

- **Résultats** montrés quand l'enseignant les publie, **corrigés** après l'échéance (par activité).
- **Relecture des leçons** désactivée (`lms.lesson.review_required`).
- **Liste blanche des liens** : Google Drive et Docs, GitHub, OneDrive (`lms.link_domains`).
- **Délai de remplacement** d'un lien signalé : 48 heures (par activité).
- **Sans règle de notation** : notes sur 20, au quart de point, arrondi au plus proche, réussite à la moitié.
- **Accès aux cours** : l'inscription validée suffit (OD-05, `enrollment.access.require_payment` = `none`).
- **Export du carnet de notes** : par qui peut lancer des exports (`export.job.create`), que le rôle enseignant n'a pas ; l'accorder à l'enseignant lui permet d'exporter ses seuls groupes.
- **Notifications de l'élève** : activité donnée, dans l'application seulement (e-mail sur choix de l'élève) ; lien à remplacer et résultat publié, aussi par e-mail.
- **Période du carnet et des moyennes de l'élève** : l'année scolaire, du 1er septembre au 31 août, modifiable à l'écran du carnet.

## Conclusion

Les sept parcours, les réponses attendues et la trace du journal sont verts : **le lot 5 peut sortir**. Seule reste FR-LMS-05, souhaitable.

## Rejouer les contrôles

Sur le poste qui a Docker et le dépôt, depuis `platform/` :

```sh
node tests/sql/database.mjs                                   # contrôles SQL, base embarquée
DATABASE_URL=postgres://… node tests/sql/concurrency.mjs      # contrôles SQL et concurrence, PostgreSQL 16
sh deploy/smoke-test.sh && sh deploy/restore-test.sh          # déploiement, sauvegarde et restauration
BROWSERS=chromium,firefox,webkit sh deploy/acceptance-test.sh # tous les écrans
```

Les tests PHP tournent dans l'image `platform-test-php` avec un PostgreSQL 16 jetable, comme la tâche « Platform integration tests » du workflow ; `LotFiveWalkthroughTest` rejoue les sept parcours.
