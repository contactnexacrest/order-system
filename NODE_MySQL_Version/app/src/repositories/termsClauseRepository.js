'use strict';

const db = require('../config/db');

/** @returns {Promise<Array<{title:string,text:string,is_locked:boolean}>>} ordered clauses for a document type code */
async function forDocumentTypeCode(documentTypeCode) {
  const rows = await db.query(
    `SELECT c.clause_title, c.clause_text, c.is_locked
     FROM tc_clauses c
     JOIN tc_clause_documents cd ON cd.clause_id = c.id
     JOIN document_types dt ON dt.id = cd.document_type_id
     WHERE dt.code = :code AND c.status = 'active'
     ORDER BY c.clause_order`,
    { code: documentTypeCode }
  );
  return rows.map((row) => ({ title: row.clause_title, text: row.clause_text, is_locked: !!row.is_locked }));
}

module.exports = { forDocumentTypeCode };
