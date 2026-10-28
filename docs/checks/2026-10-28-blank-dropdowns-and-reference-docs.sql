-- QA Round 5, R5-30: saving a form with its text cleared used to wipe data
-- — a built-in reference document (Stage Gate, SOPs, Checklists, Wall
-- Reference) saved with empty content, and (PHP) dropdown options saved
-- with empty names. From this release both are refused.
--
-- READ-ONLY CHECK — changes nothing. Run it once on the live database.
-- Every row it returns was emptied before this release: re-enter the text
-- (Reference Library → the document → Edit) or the option name
-- (Admin → Dropdown options).
SELECT 'reference document' AS what, dt.code AS list_or_code, dt.name AS name, d.updated_at AS last_saved
FROM internal_reference_docs d
JOIN document_types dt ON dt.id = d.document_type_id
WHERE TRIM(d.content) = ''
UNION ALL
SELECT 'dropdown option', o.list_key, CONCAT('option id ', o.id, ' (sort order ', o.sort_order, ')'), NULL
FROM dropdown_options o
WHERE TRIM(o.option_value) = ''
ORDER BY what, list_or_code;
