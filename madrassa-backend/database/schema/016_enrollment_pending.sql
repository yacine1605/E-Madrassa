-- FR-INS-01: an enrollment is created pending and validated by an authorized person.
ALTER TABLE school.enrollments ALTER COLUMN status SET DEFAULT 'pending';
