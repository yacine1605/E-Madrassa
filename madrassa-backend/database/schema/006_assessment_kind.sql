ALTER TABLE school.assessments ADD COLUMN kind text CHECK (kind IN ('devoir','examen'));
