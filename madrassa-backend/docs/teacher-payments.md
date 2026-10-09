# Teacher payment workflow

Administration: **Finances → Paiement enseignants**, also linked from Enseignants, Heures and Rémunérations. Select a teacher and month to see validated/pending hours, estimated or calculated remuneration, recorded payments, remaining balance, contracts and payment receipts.

Teacher portal: **Ma rémunération**. Each teacher sees only their own ledger, declares actual minutes worked, corrects pending/refused declarations, and prints receipts for their own payments. Planned timetable sessions are not automatically treated as hours worked. A daily declaration contains the total actual minutes for that contract/date. The school database audit records declarations and changes.

1. Add an hourly or monthly contract, with its rate in DA and start/end dates. Contracts cannot overlap; one contract must cover the entire month to calculate remuneration. Mid-month proration is not inferred.
2. The teacher or administration declares hours. Administration validates or refuses each declaration with a reason. Teachers can correct refused declarations and resubmit for review. Signed revisions prevent stale forms from overwriting a later declaration or approval.
3. Calculate the month. Unreviewed declarations block calculation; refused hours are excluded. Hourly remuneration uses validated minutes × rate / 60, rounded to the nearest centime. Monthly remuneration uses the contractual monthly amount. The calculation snapshot and associated expense are created once. Calculating closes the month to further hour changes. Hourly remuneration with no validated hours is rejected.
4. Record actual payments against that remuneration. Partial payments include date, method (cash/bank/CCP/card), and optional reference. All payments use the existing `disbursements` ledger: finance totals and the teacher balance use the same records. Idempotency prevents duplicate retries and PostgreSQL locks prevent concurrent overpayment. Payments and calculated remuneration remain immutable.
5. States: **À calculer**, **À payer**, **Partiellement payé**, **Payé**. Payment receipts describe the individual payment and do not certify that the entire remuneration is settled. They are printable in A4 or saveable as PDF.

Administrative ledger access requires `hr.read`, `payroll.read`, `finance.read`. Contracts/declarations require `hr.write`; review/calculation require `payroll.write`; recording payments requires `finance.write`. Teacher routes check an active membership and linked active teacher on every request, without granting global HR/finance permissions.

Migration `2026_10_05_000001_complete_teacher_payments.php` adds refusal/review information and payment method/reference. The existing paid amounts and calculated remuneration are preserved. Pending hours and post-calculation corrections are governed by the rules above; payroll corrections, reversing an actual payment, and statutory payslip deductions are not implemented by this workflow.

Validation: `node tests/teacher-pay.cjs` uses a disposable school database and verifies declarations, stale edits, rejection/resubmission, contract overlap, hourly/monthly remuneration, pending and closed hours, expense uniqueness, partial payments, retries, key conflicts, concurrent overpayment prevention, teacher privacy, printable receipts, mobile rendering and account revocation.
