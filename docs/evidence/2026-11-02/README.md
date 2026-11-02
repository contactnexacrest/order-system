# Evidence — 2 November 2026

Kept in the repository at the owner's request (2 Nov 2026).

| Folder | What it is | Open first |
|---|---|---|
| `money_visibility_proof/` | Proof that amounts (selling prices, buyer payments, purchase prices, logistics costs, order costs/profit, incentives, CAFIN) are shown only to roles holding the matching permission. Every seeded role was logged in and every staff screen, Kanban view, file download and the dossier ZIP was checked for sentinel amounts. Result: **0 violations on PHP and 0 on Node.** Refreshed 2 Nov 2026 (evening) after the reference selling price (D-240) was added as a checked selling-price amount. The test itself lives in `e2e/tests/12-money-matrix.spec.js` and can be re-run at any time. | `README.html`, then `money_matrix_php.html` / `money_matrix_node.html` |
| `associate_portal_screens/` | Every screen an associate can see (and the public invite page the buyer sees), in each state: active, suspended, bank not verified; phone width for home and order. TEST data only. | `index.html` |

The order-page-by-role screenshot set (all roles × 9 order kinds, about 171 MB) is not stored in git because of its size; it was delivered to the owner as `NexaCrest_Order_Page_By_Role.zip`.
