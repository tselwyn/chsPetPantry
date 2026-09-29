-- Development seed data. Never loaded in prod (bin/seed.php refuses). Safe to run repeatedly.

INSERT INTO `site` (`name`, `street_address`, `city`, `state`, `postal_code`, `time_zone`) VALUES
  ('Dev Site North', '100 Example Street', 'Charleston', 'SC', '29401', 'America/New_York'),
  ('Dev Site South', '200 Sample Avenue', 'Charleston', 'SC', '29407', 'America/New_York')
ON DUPLICATE KEY UPDATE `name` = `name`;

INSERT INTO `policy_document` (`doc_type`, `version`, `language_code`, `body`, `effective_from`) VALUES
  ('Confidentiality Agreement', '0-dev', 'en',
   'DRAFT FOR DEVELOPMENT ONLY. The client will supply the real text.

As a volunteer or staff member of the pet pantry I will keep confidential all information about participants, their households and their pets that I see or hear while volunteering.

I will use participant information only to provide pantry services, I will not copy, photograph or share it, and I will sign out of shared devices when I leave them.

I understand that breaking this agreement may end my access to the system.',
   '2026-01-01')
ON DUPLICATE KEY UPDATE `document_id` = `document_id`;
