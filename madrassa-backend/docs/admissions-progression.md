# Admissions and annual progression

## Admissions

Open **Scolarité → Admissions**. Create an application with the requested offering/year, planned matricule, identity and optional contact/guardian/previous-school information. For an existing pupil, open their school history and choose **Nouvelle candidature pour cet élève**; the application references the existing student record.

States: **Dossier reçu**, **En attente**, **Accepté**, **Refusé**, **Retiré**, **Inscrit**. Review requires a reason. Acceptance requires confirming the dossier is complete. Acceptance alone does not reserve a seat. The checklist is an administrative confirmation; this module does not upload or inspect supporting documents.

Convert an accepted application by choosing a class belonging to the requested offering and an invoice due date. This creates the new pupil (if necessary), optional guardian/link, enrollment and invoice in one transaction. A full class or another conflict rolls everything back. The offering's price becomes the enrollment/invoice snapshot. Repeated conversion reuses the same enrollment; changed class/date conflicts. Existing matricules cannot silently merge a new candidate with a registered pupil.

Application creation has an idempotency key and content hash. Review uses a signed revision to reject stale forms. Refused/withdrawn/enrolled dossiers are closed; an accepted dossier can still be put on hold, refused or withdrawn before conversion. Applicant details are retained as submitted; correcting identity before enrollment currently requires withdrawal and a replacement application.

## Annual progression

Open **Scolarité → Passage annuel** and choose the original class/year. Create the following year's periods, offerings and classes first in the existing management screens. Select pupils (25 per displayed page) and record an explicit decision and reason/reference:

- **Passage**: different level in a later, nonoverlapping school year.
- **Redoublement**: same level in a later school year.
- **Sortie de l’établissement** or **Fin de cycle**: retain the historical record without creating a new enrollment or invoice.

The module does not infer promotion from marks or implement national automatic pass thresholds. The establishment selects the destination, including changes of cycle/track where appropriate. Target offerings must be active. Class capacity and active enrollment duplicates are checked by the existing enrollment flow. One pupil may still enroll in different offerings, as supported by the existing multi-training model.

A selected batch is atomic: any incompatible decision/full class rolls back the entire batch. Each source enrollment gets one final, immutable decision. An identical retry is safe; a conflicting final decision is rejected. Promotion/repetition reuses the student record and creates a new enrollment and invoice at the target year's price. Previous enrollments, marks, guardian links, payments and invoices are not moved or erased. The history screen lists years/classes and links back to that year's bulletins. Final-decision reversal is not implemented.

## Permissions and validation

Reading requires `students.read`, `enrollments.read`, `catalog.read`. Application creation/conversion requires `students.write` and `enrollments.write`; review requires `enrollments.write`. Annual decisions require `enrollments.write` and `pedagogy.write`. Routes use the fixed deployment school, PostgreSQL transactions and RLS. New tables have audit triggers; lifecycle operations emit outbox events without sending external notifications.

Migration: `2026_10_06_000001_create_admissions_and_progression.php`. Existing pupil/year records are preserved.

Run `node tests/student-lifecycle.cjs` for isolated integration checks of states, dossier validation, conversion and retries, full-class rollback, atomic batches, promotion/repetition/completion/departure, history/grade/invoice preservation, duplicate prevention, school access and mobile rendering. Test artifacts are fictitious.
