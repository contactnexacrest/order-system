# NexaCrest Order System — CRM

Internal order-management CRM covering NexaCrest International's complete
export order lifecycle, start to end: from the moment a Zoho-qualified buyer
is sent a quotation-stage form link, through all 9 pipeline stages, to final
document despatch and order closure. Lead capture/nurture stays in Zoho and
is out of scope here.

Two parallel, always-in-parity stacks:

- `PHP_MySQL_Version/` — PHP + MySQL, deployable on Bluehost shared hosting
  today (DOMPDF/PHPWord/Twig, no Composer step needed to run it).
- `NODE_MySQL_Version/` — Node + MySQL, VPS-ready so the org can shift to it
  at any time without redevelopment (Puppeteer-based PDF generation).

Both stacks share the same MySQL schema and business rules, and every
feature is built and verified in both before being considered done.

See each folder's own README for setup and deployment instructions, and
`docs/` for the architecture record and the running gap/requirements log
this build is being executed against.
