# Revue du lot 4 : exploitation

Le lot 4 couvre le planning manuel et ses conflits, les remplacements, le pointage des enseignants, l'appel des élèves et les tableaux de bord (FR-PLN-\*, FR-PTG-\*, FR-PRE-01, FR-TDB-\*). Ses critères de sortie (SRS 1.2, plan de livraison) sont :

1. `TST-05`, `TST-15` et `TST-16` verts ;
2. les heures assurées et validées intégrées aux rémunérations.

Contrairement au lot 3, aucune validation humaine n'est exigée : la sortie se constate sur les contrôles automatiques. Toutes les données utilisées sont des exemples.

## Les critères, contrôle par contrôle

Les contrôles ci-dessous ont tourné le 17 octobre 2026, à la livraison de l'incrément 86. La CI GitHub étant bloquée par la facturation du compte, ses tâches de la plateforme ont été exécutées en local, une à une :

- 303 tests PHP sur PostgreSQL 16 (15 750 assertions) ;
- 267 contrôles SQL embarqués et 298 contrôles natifs avec concurrence ;
- toutes les migrations annulées puis réappliquées sur la base remplie par la suite ;
- audit des dépendances : aucune vulnérabilité ;
- déploiement de bout en bout, puis sauvegarde et restauration ;
- 284 écrans en Chromium, Firefox et WebKit.

### TST-05 : séparation des tâches

Exigé : l'auteur n'est jamais le valideur, pour le pointage, les dépenses, les remboursements, les rémunérations et les contre-opérations, y compris quand une personne cumule les rôles.

| Opération | Contrôle (tests/sql) | Test d'intégration |
|---|---|---|
| Règle générale, message explicite | who entered an operation cannot approve it, and the message names the rule | `AuthorizationTest`, `TeacherAttendanceTest` |
| Pointage (cumul surveillant et responsable pédagogique) | a session begun is entered once, locks the session, corrected by its author, decided by another person only | `TeacherAttendanceTest::test_the_supervisor_enters_and_another_person_validates_never_their_own` |
| Paiements et contre-passations | a payment enters the balance once validated by someone else… | `PaymentTest` |
| Remboursements | a refund is asked, approved by someone else, then executed… | `RefundTest` |
| Remises au-delà du seuil | an adjustment needs its reason; beyond the threshold the direction approves it, never its author | `FinanceScheduleTest` |
| Dépenses, leurs paiements et corrections | an expense is its author's draft…; a validated expense is paid in parts… | `ExpenseTest`, `ExpensePaymentTest`, `ExpenseCorrectionTest` |
| Rémunérations et versements | a member's pay for a month…; a validated pay paid in parts… | `PayrollRunTest`, `PayrollPayoutTest` |
| Réouverture d'une période close | (contrôle des périodes financières) | `FinancePeriodTest` |

**Vert.** La règle est tenue par la base (`school.check_segregation`) : elle refuse même une requête qui contournerait l'application.

### TST-15 : conflits de planning

Exigé : les chevauchements d'un enseignant, d'une salle, d'un groupe ou d'un élève sont refusés, y compris en concurrence.

| Contrôle | Où |
|---|---|
| a teacher, a room, a group never have two sessions at once; nor a pupil in two groups of different programmes | tests/sql, base embarquée et native |
| five sessions of one teacher at the same hour sent at once: one planned; two groups sharing a pupil planned at once: one | tests/sql, PostgreSQL 16 natif, requêtes réellement simultanées |
| `ClassSessionTest::test_the_conflicts_are_refused_and_the_warnings_told` | tests PHP |

**Vert.** Les contraintes d'exclusion de PostgreSQL et un verrou par élève tiennent la règle, même entre requêtes concurrentes. Remplacements et pointage passent par les mêmes contraintes.

### TST-16 : rapprochement des indicateurs

Exigé : les totaux des tableaux de bord égalent la somme des opérations sources.

| Contrôle | Où |
|---|---|
| the finance dashboard's figures equal their source operations, month by month; a payout is never counted twice : chaque mois de l'année de la suite, encaissements nets = rapprochement du mois calculé à part (SQL 053), dépenses payées + rémunérations versées = tous les paiements de dépenses | tests/sql |
| the operational dashboard counts a group's fill as its composition list and its sessions' attendance from the rows | tests/sql |
| `FinanceDashboardTest`, `OperationsDashboardTest` : chiffres, opérations sources, exports | tests PHP |

**Vert.** Chaque chiffre est recalculé à la lecture depuis les opérations, sans cache.

### Heures assurées validées dans les rémunérations

| Contrôle | Où |
|---|---|
| a month's hours are the sum of its validated sessions, which its pay counts; a correction is traced, never once the month is paid | tests/sql |
| `DeliveredHoursTest::test_the_validated_sessions_make_the_hours_the_pay_counts_and_a_correction_is_traced` | tests PHP |

**Vert.** Seules les séances au pointage validé font des unités de rémunération, y compris celles d'un remplaçant. Les heures sont les heures effectives, à défaut les heures prévues moins le retard. Une correction est tracée et refusée une fois le mois payé.

## Les exigences du lot 4

Toutes sont livrées en partie (« PARTIEL » dans `RAPPORT_ECART_SRS_1_2.md`). Ce qui reste n'empêche pas la sortie :

| Exigence | Reste |
|---|---|
| FR-PLN-01 à 03 | rien de bloquant ; les avertissements (heures par jour et semaine, volume, temps de trajet) ne bloquent jamais, comme voulu |
| FR-PLN-04 | vue mensuelle (livrée depuis, à l'incrément 89), emploi du temps des élèves et des parents avec le portail (lot 10) |
| FR-PLN-05 | portée de publication retenue : toute l'école, par période (proposée le 17 octobre, sans réponse contraire) |
| FR-PLN-06 | rien de bloquant |
| FR-PTG-01 à 03 | rien de bloquant |
| FR-PRE-01 | seuils d'absence (livrés depuis, à l'incrément 90) ; responsables légaux prévenus par e-mail et WhatsApp (livrés, incréments 91 et 92 : FR-PRE-01 conforme) |
| FR-TDB-01 | convention du résultat de gestion non paramétrable ; charges communes non réparties entre programmes (livrées depuis, à l'incrément 87 : FR-TDB-01 conforme) |
| FR-TDB-02 | absences par élève et tendances (livrées depuis, à l'incrément 88 : FR-TDB-02 conforme) |
| FR-TDB-03 | cache invalidé sur événement, à faire seulement si la lecture devient lente |

## Points à confirmer par l'établissement

- **Portée de publication du planning** : toute l'école, par période (proposition du 17 octobre).
- **Résultat de gestion** = encaissements nets − dépenses payées − rémunérations versées. Ce n'est ni une trésorerie ni un résultat comptable certifié. Depuis l'incrément 87, l'établissement peut choisir la base facturée et engagée, et répartir les charges communes entre les programmes (paramètres de l'école).
- **Tableau de bord opérationnel d'un enseignant** : il suit la portée de son rôle. Avec une portée « personnelle », l'enseignant voit ses seuls groupes. Avec une portée « tout l'établissement », il voit toute l'école. Attribuer donc le rôle enseignant en portée personnelle.

## Conclusion

Les quatre critères de sortie du lot 4 sont verts : **le lot 4 peut sortir**.

Le lot 3 attend encore sa validation humaine : le rapprochement d'un mois validé par la finance (`REVUE_LOT3.md`). Les reçus arabes ont été acceptés le 17 octobre 2026.

## Rejouer les contrôles

Sur le poste qui a Docker et le dépôt, depuis `platform/` :

```sh
node tests/sql/database.mjs                                   # contrôles SQL, base embarquée
DATABASE_URL=postgres://… node tests/sql/concurrency.mjs      # contrôles SQL et concurrence, PostgreSQL 16
sh deploy/smoke-test.sh && sh deploy/restore-test.sh          # déploiement, sauvegarde et restauration
BROWSERS=chromium,firefox,webkit sh deploy/acceptance-test.sh # tous les écrans
```

Les tests PHP tournent dans l'image `platform-test-php` avec un PostgreSQL 16 jetable, comme la tâche « Platform integration tests » du workflow.
