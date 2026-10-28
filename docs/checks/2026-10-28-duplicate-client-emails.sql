-- QA Round 5, R5-02 (owner decision 2026-10-28): every client must have its
-- own email — the client portal logs in by email. From this release the
-- app refuses a second client with an email another client already uses.
--
-- READ-ONLY CHECK — changes nothing. Run it once on the live database:
-- every row it returns is an email shared by more than one client today.
-- Fix each one by editing the client (Clients → Edit) and giving it its
-- own email (or leaving the email blank on the duplicate record). Until
-- then those clients cannot log in to the portal (login refuses an
-- email shared by two clients).
SELECT LOWER(TRIM(email)) AS shared_email,
       COUNT(*) AS clients_using_it,
       GROUP_CONCAT(CONCAT(client_unique_number, ' — ', company_legal_name) ORDER BY id SEPARATOR ' | ') AS clients
FROM clients
WHERE email IS NOT NULL AND TRIM(email) <> ''
GROUP BY LOWER(TRIM(email))
HAVING COUNT(*) > 1
ORDER BY shared_email;
