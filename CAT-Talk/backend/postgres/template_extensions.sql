-- This file is only run when PROJECT_NAME env var is "template"
-- Add any statements here that should only be run for the acutal template site,
-- not implementations of the template site.

-- Resource Class Extensions
BEGIN;

CREATE TABLE IF NOT EXISTS documents (
    resource_id VARCHAR(36) PRIMARY KEY REFERENCES resources(id) ON DELETE CASCADE ON UPDATE CASCADE,
    name TEXT NOT NULL,
    content TEXT
);

COMMIT;