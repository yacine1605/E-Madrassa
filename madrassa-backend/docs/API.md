# API de gestion

Base : `/school-api/v1/tenants/{tenant_uuid}`. JSON UTF-8, dates civiles `YYYY-MM-DD`, séances avec décalage explicite comme `2026-10-01T08:00:00+01:00`. `10000` centimes = `100,00 DZD`. Les identifiants métier sont des UUID ; `users.id` conserve le bigint Laravel existant.

## Session et CSRF

Client sur la même origine que Laravel :

```javascript
let { csrf_token } = await fetch('/school-api/csrf', {
  credentials: 'same-origin', headers: {Accept: 'application/json'}
}).then(r => r.json());

async function send(path, method, body) {
  const r = await fetch(path, {
    method, credentials: 'same-origin',
    headers: {Accept: 'application/json', 'Content-Type': 'application/json',
              'X-CSRF-TOKEN': csrf_token},
    body: JSON.stringify(body)
  });
  if (!r.ok) throw await r.json();
  return r.status === 204 ? null : r.json();
}
await send('/school-api/login', 'POST', {email, password});
({ csrf_token } = await fetch('/school-api/csrf', {
  credentials: 'same-origin', headers: {Accept: 'application/json'}
}).then(r => r.json()));
```

Le token CSRF doit être conservé dans une variable renouvelable après connexion/déconnexion ; le fragment illustre l’appel initial. Pas de token d’accès dans localStorage, pas de CORS ouvert. Ne pas désactiver CSRF pour faciliter l’intégration.

Après connexion, `GET /school-api/school` renvoie l’école fixe de ce déploiement, son UUID et les permissions de l’utilisateur dans l’objet `data`. Utiliser cet UUID dans les routes métier. Il n’y a plus de liste ni de sélection d’établissements : chaque école dispose de son propre déploiement, de sa base PostgreSQL et de ses comptes. Les routes rejettent tout autre UUID, même si une adhésion supplémentaire existe. `POST /school-api/logout` ferme la session et invalide son token CSRF.

`POST /school-api/v1/tenants/{tenant}/students/import` accepte `{"rows":[...]}` avec 1 à 500 lignes CSV déjà décodées. Chaque ligne suit les règles de création d’un élève. Toutes les lignes sont validées avant l’insertion ; elles sont ensuite créées avec leur journal d’audit dans la même transaction. Une erreur indique les lignes concernées et n’enregistre aucun élève.

## Routes métier

| Méthode / suffixe | Permission | Fonction |
|---|---|---|
| GET `/{resource}?limit=50&after={uuid}&q={texte}` | droit `.read` de la ressource | Liste paginée, maximum 100 ; recherche français/arabe dans les champs textuels autorisés |
| POST `/{resource}` | droit `.write` du catalogue | Création avec champs autorisés seulement |
| PUT `/{resource}/{id}` | droit `.write` du catalogue | Modification des ressources modifiables ; validation complète |
| DELETE `/{resource}/{id}` | droit `.write` du catalogue | Suppression des ressources modifiables, refusée si des données liées existent |
| POST `/enrollments` | `enrollments.write` | Inscription et facture, atomiques |
| POST `/payments` | `finance.write` | Encaissement validé |
| POST `/refunds` | `finance.write` | Remboursement lié à un paiement |
| POST `/disbursements` | `finance.write` | Décaissement sur dépense |
| PUT `/attendance` | `pedagogy.write` | Présence ou correction motivée |
| PUT `/grades` | `pedagogy.write` | Résultat ou correction motivée avant publication |
| POST `/payroll` | `payroll.write` | Génération unique personnel/mois |
| POST `/{resource}/{id}/transition` | droit correspondant | Publication, validation d’heures, suspension/annulation |
| GET `/finance/summary` | `finance.read` | Facturé, encaissé, remboursé, restant, engagé et décaissé |

Les ressources de création sont : `campuses`, `periods`, `programs`, `levels`, `subjects`, `curriculum`, `offerings`, `cohorts`, `rooms`, `students`, `guardians`, `student_guardians`, `staff`, `contracts`, `work_logs`, `sessions`, `lesson_versions`, `assessments`, `announcements`, `expenses`.

Lectures additionnelles : `enrollments`, `invoices`, `invoice_balances`, `payments`, `refunds`, `disbursements`, `payroll_runs`, `grades`, `attendance`, `audit_events`. Les routes PUT/DELETE ne concernent que `campuses`, `periods`, `programs`, `levels`, `subjects`, `curriculum`, `offerings`, `cohorts`, `rooms`, `students`, `guardians`, `student_guardians` et `staff`. Les factures, paiements, contrats, dépenses et versions publiées restent protégés. Une classe avec inscriptions ne peut pas changer d’offre ni réduire sa capacité sous l’effectif actif ; une offre utilisée ne peut pas changer de niveau, période ou campus. Les contraintes de référence bloquent la suppression d’un parent utilisé.

La liste renvoie `{ "data": [...], "next": "uuid-ou-null" }`. Le curseur suit l’UUID et non la date : il évite OFFSET, mais ne représente pas un export figé si des créations se produisent pendant la lecture. Un export de clôture doit utiliser un snapshot transactionnel dédié.
La recherche `q` est limitée à 100 caractères, insensible à la casse et aux accents français ; les caractères `%` et `_` sont cherchés littéralement. Elle s’applique avant la pagination et reste limitée à l’établissement et aux permissions de lecture du compte.

## Corps de requêtes

Inscription : le prix est lu sur l’offre et n’est pas accepté du client.

```json
{
  "student_id": "uuid-eleve",
  "offering_id": "uuid-offre",
  "cohort_id": "uuid-groupe",
  "due_on": "2026-10-05",
  "idempotency_key": "uuid-stable-pour-cette-operation"
}
```

Paiement :

```json
{
  "invoice_id": "uuid-facture",
  "amount_minor": 1500000,
  "method": "ccp",
  "reference": "Référence du versement",
  "idempotency_key": "uuid-stable-pour-cette-operation"
}
```

Réutiliser la **même clé et le même corps** après un délai réseau. Les inscriptions, paiements, remboursements et décaissements sérialisent la clé en transaction. Une clé réutilisée avec un contenu différent renvoie 409. La réponse rejouée conserve 201, avec le même identifiant. Les créations de catalogue ne sont pas idempotentes : vérifier le résultat après un délai réseau avant de recréer.

Remboursement : `payment_id`, `amount_minor`, `reason`, `idempotency_key`. Décaissement : `expense_id`, `amount_minor`, `paid_on`, `idempotency_key`.

Présence : `session_id`, `enrollment_id`, `status` parmi `present/absent/late/excused`, `reason` pour une correction. L’absence de ligne signifie **non renseigné**.

Note : `assessment_id`, `enrollment_id`, `status` parmi `marked/absent/exempt/missing`, `score` uniquement pour `marked`, `reason` pour une correction. La note doit être comprise entre zéro et le maximum de l’évaluation.

Rémunération : `{ "staff_id": "uuid", "month": "2026-10-01" }`. Un seul contrat doit couvrir le mois entier. Un contrat horaire utilise les minutes effectivement validées, arrondies au centime, pas les séances planifiées. Le résultat et le calcul sont figés ; une modification en milieu de mois est refusée plutôt qu’une proratisation implicite. Ce module n’applique pas la fiscalité, les cotisations ou les règles d’une paie légale.

Transition : `reason` obligatoire ; `status` également pour `enrollments` (`validated/suspended/cancelled`) ou `sessions` (`published/cancelled`). Pour `assessments`, `lesson_versions`, `announcements` et `work_logs`, la date de publication/validation est fixée côté serveur.

## Erreurs

401 : non connecté. 403 : adhésion ou droit manquant. 404 : ressource inconnue/invisible. 419 : CSRF/session à renouveler. 422 : champs invalides. 409 : conflit métier, unicité, référence invalide ou verrou indisponible. 429 : limitation de débit. Une erreur SQL ne renvoie ni requête ni données personnelles ; consulter les journaux privés côté opérateur. L’application doit afficher la réponse sans annoncer un succès anticipé.
