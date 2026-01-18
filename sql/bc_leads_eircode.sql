-- Add optional address fields to bc_leads (safe, won’t break if you already have some of these columns)
-- Run in phpMyAdmin / Adminer / MySQL console on the SAME database used by /dashboard/_private/db-config.php

ALTER TABLE bc_leads
  ADD COLUMN eircode VARCHAR(10) NULL,
  ADD COLUMN address_line1 VARCHAR(190) NULL,
  ADD COLUMN address_line2 VARCHAR(190) NULL,
  ADD COLUMN city VARCHAR(100) NULL,
  ADD COLUMN county VARCHAR(100) NULL;

-- Optional (helpful for debugging/dedup)
ALTER TABLE bc_leads
  ADD COLUMN lead_event_id VARCHAR(80) NULL,
  ADD COLUMN contact_event_id VARCHAR(80) NULL,
  ADD COLUMN contact_at DATETIME NULL,
  ADD COLUMN went_whatsapp TINYINT(1) NULL;

-- Optional indexes (speed)
CREATE INDEX idx_bc_leads_eircode ON bc_leads (eircode);
CREATE INDEX idx_bc_leads_stage ON bc_leads (stage);
