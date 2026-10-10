ALTER TABLE school.sessions ADD COLUMN activity text NOT NULL DEFAULT 'course' CHECK(activity IN ('course','support'));
