# NexaCrest PHP/MySQL — Raw QA Inventory (auto-generated, structured facts only)

Sources: `docs/seed.sql`, `public_html/index.php`, `docs/schema.sql`, `app/src/Services/StageGateService.php`, `docs/SOP/*`, `app/src/Controllers/*`, `app/src/Services/*`.

## 1. Roles and permissions

### Roles (8, from `roles` INSERT in seed.sql)

| Role | is_system_role | Description |
|---|---|---|
| Admin | 1 | Full system access, including user/role administration and audit log. |
| Managing Director | 0 | Full business authority: approvals, all order/document actions, reporting. |
| Executive Director | 0 | Full business authority: approvals, all order/document actions, reporting. |
| Export Executive | 0 | Day-to-day order handling, quotations through order confirmation, document generation. |
| Accounts Executive | 0 | Payment tracking, balance follow-up, financial reporting. |
| Logistics Executive | 0 | Packing, freight, BL instruction, shipping-stage documents. |
| Viewer / Auditor | 0 | Read-only access to reports and audit trail. |
| CA / Chartered Accountant | 0 | External or in-house CA — view-only access to CA/Accounting module. No order-management access. |

### Role → Permission grants (from `role_permissions` INSERT logic)

- **Admin** — ALL 34 permissions (`CROSS JOIN` wildcard: `WHERE r.name IN ('Admin','Managing Director','Executive Director')`)
- **Managing Director** — ALL 34 permissions (same wildcard join as above)
- **Executive Director** — ALL 34 permissions (same wildcard join as above)
- **Export Executive** — manage_orders, generate_documents, download_pdf, view_reports, view_client_email_full, cross_verify_documents, view_product_catalog, browse_product_catalog, view_product_pricing, view_archived_orders
- **Accounts Executive** — manage_orders, download_pdf, view_reports, view_client_email_full, cross_verify_documents, view_product_catalog, browse_product_catalog, view_product_pricing, view_archived_orders, ca_module_view, inr_actual_view, inr_actual_edit, ca_module_manage, ca_fy_lock_override
- **Logistics Executive** — manage_orders, generate_documents, download_pdf, cross_verify_documents, view_product_catalog, browse_product_catalog, view_archived_orders
- **Viewer / Auditor** — view_reports, view_audit_log, view_product_catalog
- **CA / Chartered Accountant** — ca_module_view, inr_actual_view

Note: seed.sql's own comment flags this matrix as "a first-cut RBAC model... not a verbatim transcription... treat it as a working default." Individual per-user overrides also exist via `user_permissions` (force-enable/disable), and a Super Admin tier (`users.is_super_admin`, `super_admin_delegations`) bypasses permission checks entirely, orthogonal to roles.

### All permissions (34 total, from `permissions` INSERT), grouped by `category` column

**admin (10):** manage_users, manage_permissions, manage_company_settings, manage_assets, view_audit_log, manage_sample_data, manage_field_protection, delete_assets, manage_signatories, manage_email_templates

**reports (3):** manage_report_definitions, view_reports, view_staff_reports

**orders (3):** manage_orders, view_archived_orders, edit_order_post_confirmation

**documents (6):** generate_documents, approve_documents, edit_locked_data, download_pdf, cross_verify_documents, approve_email_send

**clients (1):** view_client_email_full

**catalog (5):** view_product_catalog, browse_product_catalog, view_product_pricing, manage_product_catalog, manage_hs_codes

**ca (6):** ca_module_view, inr_actual_view, inr_actual_edit, inr_actual_delete, ca_module_manage, ca_fy_lock_override

---

## 2. Route inventory

**Total routes: 282** (from `public_html/index.php`, single router file; `TestModeGate` calls at the bottom are pre-dispatch middleware, not routes; `$router->dispatch(...)` is the dispatch call, not a route registration).

Format: `METHOD path -> Controller::action [middleware/permission]`

### Auth (11)
- GET /login -> AuthController::showLogin [public]
- POST /login -> AuthController::login [CsrfCheck::verify()]
- GET /2fa -> AuthController::show2fa [public]
- POST /2fa -> AuthController::verify2fa [CsrfCheck::verify()]
- GET /logout -> AuthController::logout [public]
- GET /forgot-password -> AuthController::showForgotPassword [public]
- POST /forgot-password -> AuthController::forgotPassword [CsrfCheck::verify()]
- GET /reset-password/{token} -> AuthController::showResetPassword [public]
- POST /reset-password/{token} -> AuthController::resetPassword [CsrfCheck::verify()]
- GET /force-password-change -> AuthController::showForcePasswordChange [SessionAuth::required()]
- POST /force-password-change -> AuthController::forcePasswordChange [SessionAuth::required(), CsrfCheck::verify()]

### Dashboard (1)
- GET / -> DashboardController::index [SessionAuth::required()]

### Client Intake — public forms (6)
- GET /quotation-details -> ClientIntakeController::show [public]
- POST /quotation-details/submit -> ClientIntakeController::submit [CsrfCheck::verify()]
- GET /quotation-details/edit/{token} -> ClientIntakeController::showEdit [public, token-gated]
- POST /quotation-details/edit/{token} -> ClientIntakeController::updateSubmission [CsrfCheck::verify()]
- GET /pi-details/{token} -> PiIntakeController::show [public, token-gated]
- POST /pi-details/{token} -> PiIntakeController::submit [CsrfCheck::verify()]

### Client Intake Review — staff queues (6)
- GET /client-intake -> ClientIntakeReviewController::index [SessionAuth::required(), PermissionCheck::requires('manage_orders')]
- POST /client-intake/{id}/accept -> ClientIntakeReviewController::accept [SessionAuth, manage_orders, Csrf]
- POST /client-intake/{id}/reject -> ClientIntakeReviewController::reject [SessionAuth, manage_orders, Csrf]
- GET /pi-intake-review -> PiIntakeReviewController::index [SessionAuth, manage_orders]
- POST /pi-intake-review/{id}/accept -> PiIntakeReviewController::accept [SessionAuth, manage_orders, Csrf]
- POST /pi-intake-review/{id}/reject -> PiIntakeReviewController::reject [SessionAuth, manage_orders, Csrf]

### Reorder Requests — staff side (4)
- GET /reorder-requests -> ReorderRequestController::index [SessionAuth, manage_orders]
- GET /reorder-requests/{id} -> ReorderRequestController::show [SessionAuth, manage_orders]
- POST /reorder-requests/{id}/approve -> ReorderRequestController::approve [SessionAuth, manage_orders, Csrf]
- POST /reorder-requests/{id}/reject -> ReorderRequestController::reject [SessionAuth, manage_orders, Csrf]

### Client Portal (18)
- GET /client/login -> ClientPortalController::showLogin [public]
- POST /client/login -> ClientPortalController::login [CsrfCheck::verify()]
- GET /client/logout -> ClientPortalController::logout [public]
- GET /client/set-password/{token} -> ClientPortalController::showSetPassword [public, token-gated]
- POST /client/set-password/{token} -> ClientPortalController::setPassword [CsrfCheck::verify()]
- GET /client -> ClientPortalController::dashboard [ClientAuth::required()]
- GET /client/account -> ClientPortalController::showAccount [ClientAuth::required()]
- POST /client/account/password -> ClientPortalController::changePassword [ClientAuth, Csrf]
- GET /client/orders/{id} -> ClientPortalController::showOrder [ClientAuth::required()]
- GET /client/orders/{id}/reorder -> ClientPortalController::showReorderForm [ClientAuth::required()]
- POST /client/orders/{id}/reorder -> ClientPortalController::submitReorder [ClientAuth, Csrf]
- POST /client/orders/{id}/report-payment -> ClientPortalController::reportPayment [ClientAuth, Csrf]
- POST /client/orders/{id}/acknowledge-oc -> ClientPortalController::acknowledgeOc [ClientAuth, Csrf] (Stage 4 gate — client-side path)
- POST /client/orders/{id}/disputes -> ClientPortalController::raiseDispute [ClientAuth, Csrf]
- POST /client/orders/{id}/comments -> ClientPortalController::postComment [ClientAuth, Csrf]
- GET /client/orders/{id}/comment-attachments/{fileId}/download -> ClientPortalController::downloadCommentAttachment [ClientAuth::required()]
- GET /client/orders/{id}/payment-reports/{reportId}/screenshot -> ClientPortalController::downloadPaymentScreenshot [ClientAuth::required()]
- GET /client/documents/{id}/download -> ClientPortalController::downloadDocument [ClientAuth::required()]

### Clients — CRM (9)
- GET /clients -> ClientController::index [SessionAuth, manage_orders]
- GET /clients/inactive -> ClientController::inactiveIndex [SessionAuth, manage_orders]
- GET /clients/create -> ClientController::create [SessionAuth, manage_orders]
- POST /clients -> ClientController::store [SessionAuth, manage_orders, Csrf]
- GET /clients/{id} -> ClientController::show [SessionAuth, manage_orders]
- GET /clients/{id}/edit -> ClientController::editForm [SessionAuth, manage_orders]
- POST /clients/{id}/update -> ClientController::update [SessionAuth, manage_orders, Csrf]
- POST /clients/{id}/toggle-active -> ClientController::toggleActive [SessionAuth, manage_orders, Csrf]
- POST /clients/{id}/override-unique-number -> ClientController::overrideUniqueNumber [SessionAuth, edit_locked_data, Csrf]

### Orders — core pipeline, payments, comments, annexure, supplier/freight/packing/shipping/BL (61)
- GET /orders -> OrderController::index [SessionAuth, manage_orders]
- GET /orders/archived -> OrderController::archivedIndex [SessionAuth, view_archived_orders]
- GET /orders/create -> OrderController::create [SessionAuth, manage_orders]
- POST /orders -> OrderController::store [SessionAuth, manage_orders, Csrf]
- GET /orders/{id} -> OrderController::show [SessionAuth, manage_orders]
- GET /orders/{id}/edit -> OrderController::editDetails [SessionAuth, manage_orders]
- POST /orders/{id}/edit -> OrderController::updateDetails [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/archive -> OrderController::archive [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/unarchive -> OrderController::unarchive [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/duplicate -> OrderController::duplicateOrder [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/buyer-po -> OrderController::recordBuyerPo [SessionAuth, manage_orders, Csrf] (**Stage 2 gate**)
- POST /orders/{id}/buyer-po/documents -> OrderController::uploadBuyerPoDocument [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/products -> OrderController::addProduct [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/products/{productId} -> OrderController::updateProduct [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/products/{productId}/delete -> OrderController::deleteProduct [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/products/{productId}/duplicate -> OrderController::duplicateProduct [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/payment/exchange-rate -> OrderController::recordAssumedExchangeRate [SessionAuth, inr_actual_edit, Csrf]
- POST /orders/{id}/payment/advance/firc -> OrderController::recordAdvanceFirc [SessionAuth, inr_actual_edit, Csrf]
- POST /orders/{id}/payment/balance/firc -> OrderController::recordBalanceFirc [SessionAuth, inr_actual_edit, Csrf]
- POST /orders/{id}/payment/freight/firc -> OrderController::recordFreightFirc [SessionAuth, inr_actual_edit, Csrf]
- POST /orders/{id}/payment/advance/inr-actual -> OrderController::recordAdvanceInrActual [SessionAuth, inr_actual_edit, Csrf]
- POST /orders/{id}/payment/advance/inr-actual/delete -> OrderController::deleteAdvanceInrActual [SessionAuth, inr_actual_delete, Csrf]
- POST /orders/{id}/payment/balance/inr-actual -> OrderController::recordBalanceInrActual [SessionAuth, inr_actual_edit, Csrf]
- POST /orders/{id}/payment/balance/inr-actual/delete -> OrderController::deleteBalanceInrActual [SessionAuth, inr_actual_delete, Csrf]
- POST /orders/{id}/payment/freight/inr-actual -> OrderController::recordFreightInrActual [SessionAuth, inr_actual_edit, Csrf]
- POST /orders/{id}/payment/freight/inr-actual/delete -> OrderController::deleteFreightInrActual [SessionAuth, inr_actual_delete, Csrf]
- POST /orders/{id}/payment/advance -> OrderController::recordAdvancePayment [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/payment-reports/{reportId}/reviewed -> OrderController::markPaymentReportReviewed [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/payment/advance/clear -> OrderController::clearAdvancePayment [SessionAuth, manage_orders, Csrf] (**Stage 3 gate**)
- POST /orders/{id}/production-status -> OrderController::updateProductionStatus [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/dispute-visibility -> OrderController::setDisputeButtonVisible [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/comments -> OrderCommentController::post [SessionAuth, manage_orders, Csrf]
- GET /orders/{id}/comment-attachments/{fileId}/download -> OrderCommentController::downloadAttachment [SessionAuth, download_pdf]
- GET /orders/{id}/annexure -> AnnexureController::index [SessionAuth, manage_orders]
- POST /orders/{id}/annexure/toggle -> AnnexureController::toggleInclude [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/annexure/products -> AnnexureController::createProduct [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/annexure/products/{productId} -> AnnexureController::updateProduct [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/annexure/products/{productId}/delete -> AnnexureController::deleteProduct [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/annexure/products/{productId}/images -> AnnexureController::uploadImage [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/annexure/images/{imageId}/remove -> AnnexureController::removeImage [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/oc-acknowledgment -> OrderController::recordOcAcknowledgment [SessionAuth, manage_orders, Csrf] (**Stage 4 gate** — staff-recorded path)
- POST /orders/{id}/suppliers -> OrderController::createSupplier [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/supplier-po -> OrderController::saveSupplierPo [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/supplier-po/signed -> OrderController::confirmSupplierSigned [SessionAuth, manage_orders, Csrf] (**Stage 5 gate**)
- POST /orders/{id}/supplier-po/documents -> OrderController::uploadSupplierPoDocument [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/freight-terms -> OrderController::saveFreightTerms [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/payment/freight -> OrderController::recordFreightPayment [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/payment/freight/clear -> OrderController::clearFreightPayment [SessionAuth, manage_orders, Csrf] (**Stage 6 gate**)
- POST /orders/{id}/packing -> OrderController::savePacking [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/shipping -> OrderController::saveShipping [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/bl-issued -> OrderController::recordBlIssued [SessionAuth, manage_orders, Csrf] (**Stage 7 gate**)
- POST /orders/{id}/scanned-bl-sent -> OrderController::recordScannedBlSent [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/payment/balance -> OrderController::recordBalancePayment [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/payment/balance/clear -> OrderController::clearBalancePayment [SessionAuth, manage_orders, Csrf] (**Stage 8 gate**)
- POST /orders/{id}/bl-originals-received -> OrderController::recordBlOriginalsReceived [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/bl-endorsed -> OrderController::recordBlEndorsed [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/close -> OrderController::closeOrder [SessionAuth, manage_orders, Csrf] (**Stage 9 gate**)
- POST /orders/{id}/mark-lost -> OrderController::markLost [SessionAuth, manage_orders, Csrf]
- GET /orders/{id}/dossier -> OrderController::downloadDossier [SessionAuth, manage_orders]
- POST /orders/{id}/pi-form-link -> OrderController::generatePiFormLink [SessionAuth, manage_orders, Csrf]
- POST /orders/{id}/override-status-lock -> OrderController::overrideStatusLock [SessionAuth, edit_locked_data, Csrf]

### CA / Accounts (15)
- GET /ca -> CaController::index [SessionAuth, ca_module_view]
- GET /ca/reports -> CaController::reports [SessionAuth, ca_module_view]
- GET /ca/zoho-sync -> CaController::zohoSync [SessionAuth, ca_module_manage]
- POST /ca/zoho-sync/run -> CaController::runZohoSync [SessionAuth, ca_module_manage, Csrf]
- GET /ca/expenses -> CaController::expenses [SessionAuth, ca_module_view]
- POST /ca/expenses/{id}/tds -> CaController::setExpenseTds [SessionAuth, inr_actual_edit, Csrf]
- GET /ca/bank-statement -> CaController::bankStatement [SessionAuth, ca_module_view]
- POST /ca/bank-statement/upload -> CaController::uploadBankStatement [SessionAuth, inr_actual_edit, Csrf]
- POST /ca/bank-statement/{id}/match-revenue -> CaController::matchBankLineToRevenue [SessionAuth, inr_actual_edit, Csrf]
- POST /ca/bank-statement/{id}/match-expense -> CaController::matchBankLineToExpense [SessionAuth, inr_actual_edit, Csrf]
- POST /ca/bank-statement/{id}/unmatch -> CaController::unmatchBankLine [SessionAuth, inr_actual_edit, Csrf]
- GET /ca/reconciliation -> CaController::reconciliation [SessionAuth, ca_module_view]
- GET /ca/fy-locks -> CaController::fyLocks [SessionAuth, ca_module_manage]
- POST /ca/fy-locks/lock -> CaController::lockFinancialYear [SessionAuth, ca_module_manage, Csrf]
- POST /ca/fy-locks/unlock -> CaController::unlockFinancialYear [SessionAuth, ca_module_manage, Csrf]

### Documents — generation, review, deferred email send (16)
- POST /orders/{id}/documents/generate -> DocumentController::generate [SessionAuth, generate_documents, Csrf] (**Stage 1 gate**, for QT)
- POST /orders/{id}/documents/{documentId}/delete -> DocumentController::delete [SessionAuth, generate_documents, Csrf]
- GET /documents/{documentId}/download -> DocumentController::download [SessionAuth, download_pdf]
- GET /file-store/{id}/download -> FileStoreController::download [SessionAuth, manage_orders]
- GET /reviews -> ReviewController::queue [SessionAuth::required()] (row-level ownership enforced in service, not route)
- POST /documents/{documentId}/reviewers -> ReviewController::assign [SessionAuth, approve_documents, Csrf]
- POST /reviews/{reviewId}/approve -> ReviewController::approve [SessionAuth, Csrf]
- POST /reviews/{reviewId}/reject -> ReviewController::reject [SessionAuth, Csrf]
- POST /documents/{documentId}/cross-verify -> ReviewController::crossVerify [SessionAuth, cross_verify_documents, Csrf]
- GET /orders/{id}/email/compose -> EmailDispatchController::composeGeneric [SessionAuth, generate_documents]
- GET /orders/{id}/documents/{documentId}/send -> EmailDispatchController::compose [SessionAuth, generate_documents]
- POST /orders/{id}/documents/{documentId}/send -> EmailDispatchController::requestSend [SessionAuth, generate_documents, Csrf]
- GET /email-approvals -> EmailDispatchController::approvalQueue [SessionAuth, approve_email_send]
- POST /email-log/{emailLogId}/approve -> EmailDispatchController::approve [SessionAuth, approve_email_send, Csrf]
- POST /email-log/{emailLogId}/reject -> EmailDispatchController::reject [SessionAuth, approve_email_send, Csrf]
- POST /email-log/{emailLogId}/cancel -> EmailDispatchController::cancel [SessionAuth, Csrf] (no PermissionCheck — ownership check inside EmailDispatchService::cancelSend())

### Amendments (7)
- GET /orders/{id}/amendments -> AmendmentController::index [SessionAuth, manage_orders]
- POST /orders/{id}/amendments -> AmendmentController::create [SessionAuth, manage_orders, Csrf]
- POST /amendments/{amendmentId}/md-approve -> AmendmentController::mdApprove [SessionAuth, approve_documents, Csrf]
- POST /amendments/{amendmentId}/reject -> AmendmentController::reject [SessionAuth, approve_documents, Csrf]
- POST /amendments/{amendmentId}/generate-document -> AmendmentController::generateDocument [SessionAuth, generate_documents, Csrf]
- POST /amendments/{amendmentId}/signed-copy -> AmendmentController::uploadSignedCopy [SessionAuth, manage_orders, Csrf]
- POST /amendments/{amendmentId}/override-reference -> AmendmentController::overrideReference [SessionAuth, edit_locked_data, Csrf]

### Disputes (5)
- GET /disputes -> DisputeController::index [SessionAuth, manage_orders]
- GET /orders/{id}/disputes -> DisputeController::forOrder [SessionAuth, manage_orders]
- POST /orders/{id}/disputes -> DisputeController::create [SessionAuth, manage_orders, Csrf]
- POST /disputes/{disputeId}/status -> DisputeController::updateStatus [SessionAuth, manage_orders, Csrf]
- POST /disputes/{disputeId}/documents -> DisputeController::uploadDocument [SessionAuth, manage_orders, Csrf]

### Audit Log (2)
- GET /audit-log -> AuditLogController::index [SessionAuth, view_audit_log]
- GET /orders/{id}/audit-log -> AuditLogController::forOrder [SessionAuth, view_audit_log]

### Notifications (1)
- GET /notifications -> NotificationController::index [SessionAuth::required()]

### Reports (14)
- GET /reports -> ReportController::index [SessionAuth, view_reports]
- GET /reports/client/{clientId} -> ReportController::client [SessionAuth, view_reports]
- GET /reports/order/{orderId} -> ReportController::order [SessionAuth, view_reports]
- GET /reports/aggregate -> ReportController::aggregate [SessionAuth, view_reports]
- GET /reports/queues -> ReportController::queues [SessionAuth, view_reports]
- GET /reports/payments -> ReportController::payments [SessionAuth, view_reports]
- GET /reports/disputes -> ReportController::disputes [SessionAuth, view_reports]
- GET /reports/amendments -> ReportController::amendments [SessionAuth, view_reports]
- GET /reports/trends -> ReportController::trends [SessionAuth, view_reports]
- GET /reports/staff -> ReportController::staff [SessionAuth, view_staff_reports]
- POST /reports/save -> ReportController::saveDefinition [SessionAuth, manage_report_definitions, Csrf]
- GET /reports/saved/{reportId}/run -> ReportController::runDefinition [SessionAuth, view_reports]
- POST /reports/saved/{reportId}/update -> ReportController::updateDefinition [SessionAuth, manage_report_definitions, Csrf]
- POST /reports/saved/{reportId}/delete -> ReportController::deleteDefinition [SessionAuth, manage_report_definitions, Csrf]

### Admin / Settings (82)

**Settings (3):**
- GET /settings -> SettingsController::index [SessionAuth, manage_company_settings]
- POST /settings/update -> SettingsController::update [SessionAuth, manage_company_settings, Csrf]
- POST /settings/test-zoho-email -> SettingsController::testZohoEmail [SessionAuth, manage_company_settings, Csrf]

**Holidays (4):**
- GET /holidays -> HolidayController::index [SessionAuth, manage_company_settings]
- POST /holidays -> HolidayController::create [SessionAuth, manage_company_settings, Csrf]
- POST /holidays/{id}/update -> HolidayController::update [SessionAuth, manage_company_settings, Csrf]
- POST /holidays/{id}/delete -> HolidayController::delete [SessionAuth, manage_company_settings, Csrf]

**HS Codes (5):**
- GET /hs-codes -> HsCodeController::index [SessionAuth, manage_hs_codes]
- POST /hs-codes -> HsCodeController::create [SessionAuth, manage_hs_codes, Csrf]
- POST /hs-codes/{id}/update -> HsCodeController::update [SessionAuth, manage_hs_codes, Csrf]
- POST /hs-codes/{id}/toggle -> HsCodeController::toggleActive [SessionAuth, manage_hs_codes, Csrf]
- POST /hs-codes/{id}/delete -> HsCodeController::delete [SessionAuth, manage_hs_codes, Csrf]

**Email Templates (6):**
- GET /email-templates -> EmailTemplateController::index [SessionAuth, manage_email_templates]
- GET /email-templates/create -> EmailTemplateController::create [SessionAuth, manage_email_templates]
- POST /email-templates -> EmailTemplateController::store [SessionAuth, manage_email_templates, Csrf]
- GET /email-templates/{id}/edit -> EmailTemplateController::edit [SessionAuth, manage_email_templates]
- POST /email-templates/{id}/update -> EmailTemplateController::update [SessionAuth, manage_email_templates, Csrf]
- POST /email-templates/{id}/toggle-active -> EmailTemplateController::toggleActive [SessionAuth, manage_email_templates, Csrf]

**Account (self-service) (2):**
- GET /account -> AccountController::edit [SessionAuth::required()]
- POST /account/signature -> AccountController::updateSignature [SessionAuth, Csrf]

**Watermarks (2):**
- GET /watermarks -> WatermarkController::index [SessionAuth, manage_company_settings]
- POST /watermarks/{which} -> WatermarkController::update [SessionAuth, manage_company_settings, Csrf]

**Reference Docs (11):**
- GET /reference-docs -> ReferenceDocController::index [SessionAuth::required()]
- GET /reference-docs/custom/create -> ReferenceDocController::customCreateForm [SessionAuth, manage_company_settings]
- POST /reference-docs/custom -> ReferenceDocController::customCreate [SessionAuth, manage_company_settings, Csrf]
- GET /reference-docs/custom/{id} -> ReferenceDocController::customShow [SessionAuth::required()]
- GET /reference-docs/custom/{id}/edit -> ReferenceDocController::customEditForm [SessionAuth, manage_company_settings]
- POST /reference-docs/custom/{id}/update -> ReferenceDocController::customUpdate [SessionAuth, manage_company_settings, Csrf]
- POST /reference-docs/custom/{id}/delete -> ReferenceDocController::customDelete [SessionAuth, manage_company_settings, Csrf]
- GET /reference-docs/custom/{id}/download -> ReferenceDocController::customDownload [SessionAuth::required()]
- GET /reference-docs/{code} -> ReferenceDocController::show [SessionAuth::required()]
- GET /reference-docs/{code}/edit -> ReferenceDocController::edit [SessionAuth, manage_company_settings]
- POST /reference-docs/{code} -> ReferenceDocController::update [SessionAuth, manage_company_settings, Csrf]

**SOP Viewer (3):**
- GET /sop -> SopController::index [SessionAuth::required()]
- GET /sop-assets/{file} -> SopController::asset [SessionAuth::required()]
- GET /sop/{chapter} -> SopController::show [SessionAuth::required()]

**Company Assets (4):**
- GET /company-assets -> AssetController::index [SessionAuth, manage_assets]
- GET /company-assets/preview -> AssetController::preview [SessionAuth::required()]
- POST /company-assets/replace -> AssetController::replace [SessionAuth, manage_assets, Csrf]
- POST /company-assets/{id}/delete -> AssetController::delete [SessionAuth, delete_assets, Csrf]

**Signatories (11):**
- GET /signatories -> SignatoryController::index [SessionAuth, manage_signatories]
- POST /signatories/designations -> SignatoryController::createDesignation [SessionAuth, manage_signatories, Csrf]
- POST /signatories/designations/{id}/toggle -> SignatoryController::toggleDesignation [SessionAuth, manage_signatories, Csrf]
- POST /signatories/designations/{id}/update -> SignatoryController::updateDesignation [SessionAuth, manage_signatories, Csrf]
- POST /signatories/designations/{id}/delete -> SignatoryController::deleteDesignation [SessionAuth, manage_signatories, Csrf]
- POST /signatories/users/{id}/eligibility -> SignatoryController::setEligibility [SessionAuth, manage_signatories, Csrf]
- POST /signatories/users/{id}/upload -> SignatoryController::uploadUserAsset [SessionAuth, manage_signatories, Csrf]
- POST /signatories/user-assets/{id}/deactivate -> SignatoryController::deactivateUserAsset [SessionAuth, manage_signatories, Csrf]
- GET /signatories/user-assets/{id}/preview -> SignatoryController::previewUserAsset [SessionAuth, manage_signatories]
- POST /signatories/global-default -> SignatoryController::setGlobalDefault [SessionAuth, manage_signatories, Csrf]
- POST /signatories/document-types/{id} -> SignatoryController::setDocumentTypeDefault [SessionAuth, manage_signatories, Csrf]

**Super Admin (4):**
- GET /super-admin -> SuperAdminController::index [SessionAuth, SuperAdminOnly::required()]
- POST /super-admin/delegations -> SuperAdminController::grantDelegation [SessionAuth, SuperAdminOnly, Csrf]
- POST /super-admin/delegations/{id}/revoke -> SuperAdminController::revokeDelegation [SessionAuth, SuperAdminOnly, Csrf]
- POST /super-admin/set-permanent -> SuperAdminController::setPermanent [SessionAuth, SuperAdminOnly, Csrf]

**Permission Admin / RBAC (13):**
- GET /admin/permissions -> PermissionAdminController::index [SessionAuth, manage_permissions]
- POST /admin/permissions/grant -> PermissionAdminController::grantOverride [SessionAuth, manage_permissions, Csrf]
- POST /admin/permissions/{id}/remove -> PermissionAdminController::removeOverride [SessionAuth, manage_permissions, Csrf]
- POST /admin/roles -> PermissionAdminController::roleCreate [SessionAuth, manage_permissions, Csrf]
- GET /admin/roles/{id}/edit -> PermissionAdminController::roleEditForm [SessionAuth, manage_permissions]
- POST /admin/roles/{id}/update -> PermissionAdminController::roleUpdate [SessionAuth, manage_permissions, Csrf]
- POST /admin/roles/{id}/delete -> PermissionAdminController::roleDelete [SessionAuth, manage_permissions, Csrf]
- GET /admin/roles/{id}/permissions -> PermissionAdminController::rolePermissionsForm [SessionAuth, manage_permissions]
- POST /admin/roles/{id}/permissions -> PermissionAdminController::rolePermissionsUpdate [SessionAuth, manage_permissions, Csrf]
- POST /admin/permission-definitions -> PermissionAdminController::permissionCreate [SessionAuth, manage_permissions, Csrf]
- GET /admin/permission-definitions/{id}/edit -> PermissionAdminController::permissionEditForm [SessionAuth, manage_permissions]
- POST /admin/permission-definitions/{id}/update -> PermissionAdminController::permissionUpdate [SessionAuth, manage_permissions, Csrf]
- POST /admin/permission-definitions/{id}/delete -> PermissionAdminController::permissionDelete [SessionAuth, manage_permissions, Csrf]

**Admin Overrides — locked-field system (4):**
- GET /admin/overrides -> AdminOverrideController::index [SessionAuth, edit_locked_data]
- POST /admin/overrides/document-types -> AdminOverrideController::updateDocumentTypes [SessionAuth, edit_locked_data, Csrf]
- POST /admin/overrides/tc-clauses -> AdminOverrideController::updateTcClauses [SessionAuth, edit_locked_data, Csrf]
- POST /admin/overrides/payment-presets -> AdminOverrideController::updatePaymentPresets [SessionAuth, edit_locked_data, Csrf]

**Field Protection (4):**
- GET /admin/field-protection -> FieldProtectionController::index [SessionAuth, manage_field_protection]
- POST /admin/field-protection/request -> FieldProtectionController::createRequest [SessionAuth, manage_field_protection, Csrf]
- POST /admin/field-protection/{requestId}/approve -> FieldProtectionController::approve [SessionAuth, manage_field_protection, Csrf]
- POST /admin/field-protection/{requestId}/reject -> FieldProtectionController::reject [SessionAuth, manage_field_protection, Csrf]

**User Management (6):**
- GET /users -> UserController::index [SessionAuth, manage_users]
- POST /users/create -> UserController::create [SessionAuth, manage_users, Csrf]
- GET /users/{id}/edit -> UserController::editForm [SessionAuth, manage_users]
- POST /users/{id}/update -> UserController::update [SessionAuth, manage_users, Csrf]
- POST /users/{id}/toggle-active -> UserController::toggleActive [SessionAuth, manage_users, Csrf]
- POST /users/{id}/force-reset-password -> UserController::forceResetPassword [SessionAuth, manage_users, Csrf]

### Test Mode (5)
- GET /test-mode -> TestModeController::index [SessionAuth, SuperAdminOnly::required()]
- POST /test-mode/enable -> TestModeController::enable [SessionAuth, SuperAdminOnly, Csrf]
- POST /test-mode/disable -> TestModeController::disable [SessionAuth, SuperAdminOnly, Csrf]
- POST /test-mode/test-email -> TestModeController::updateTestEmail [SessionAuth, SuperAdminOnly, Csrf]
- POST /test-mode/delete-test-data -> TestModeController::deleteTestData [SessionAuth, SuperAdminOnly, Csrf]

Plus 2 pre-dispatch middleware calls (not routes): `TestModeGate::blockClientFacingInTestMode($requestPath)` and `TestModeGate::blockAdminWritesInTestMode($method, $requestPath)`, run on every request before `$router->dispatch()`.

### Sample Data Playground (3)
- GET /sample-data -> SampleDataController::index [SessionAuth, manage_sample_data]
- POST /sample-data/load -> SampleDataController::load [SessionAuth, manage_sample_data, Csrf]
- POST /sample-data/clear -> SampleDataController::clear [SessionAuth, manage_sample_data, Csrf]

### Product Catalog — internal (16)
- GET /products -> ProductController::index [SessionAuth, view_product_catalog]
- GET /products/create -> ProductController::create [SessionAuth, manage_product_catalog]
- POST /products/create -> ProductController::store [SessionAuth, manage_product_catalog, Csrf]
- GET /products/{id} -> ProductController::show [SessionAuth, view_product_catalog]
- GET /products/{id}/edit -> ProductController::edit [SessionAuth, manage_product_catalog]
- POST /products/{id}/update -> ProductController::update [SessionAuth, manage_product_catalog, Csrf]
- POST /products/{id}/delete -> ProductController::delete [SessionAuth, manage_product_catalog, Csrf]
- POST /products/{id}/images/upload -> ProductController::uploadImage [SessionAuth, manage_product_catalog, Csrf]
- POST /products/images/{imageId}/delete -> ProductController::deleteImage [SessionAuth, manage_product_catalog, Csrf]
- GET /products/images/{imageId}/view -> ProductController::viewImage [SessionAuth, view_product_catalog]
- POST /products/{id}/suppliers/add -> ProductController::addSupplier [SessionAuth, manage_product_catalog, Csrf]
- POST /products/suppliers/{supplierId}/update -> ProductController::updateSupplier [SessionAuth, manage_product_catalog, Csrf]
- POST /products/suppliers/{supplierId}/delete -> ProductController::deleteSupplier [SessionAuth, manage_product_catalog, Csrf]
- POST /products/suppliers/{supplierId}/set-primary -> ProductController::setPrimarySupplier [SessionAuth, manage_product_catalog, Csrf]
- POST /products/{id}/misc-charges/add -> ProductController::addMiscCharge [SessionAuth, manage_product_catalog, Csrf]
- POST /products/misc-charges/{chargeId}/delete -> ProductController::deleteMiscCharge [SessionAuth, manage_product_catalog, Csrf]

---

## 3. Database schema inventory

**Total tables: 84** (from `docs/schema.sql`; the file's own trailing comment says "71 tables" but that count predates several later sections — AB through AJ add more; actual `CREATE TABLE` count is 84).

**Tables with a direct foreign key to `orders.id` (24 — order-lifecycle-dependent):** order_stages, order_products, order_payment_status, order_production, order_supplier_po, order_packing, order_crates, order_freight, order_shipping, order_annexure_products, documents, amendments, file_store, email_log, notifications, disputes, ca_bank_statement_lines, client_logins, order_buyer_po_documents, pi_intake_submissions, client_payment_reports, order_oc_acknowledgments, order_comments, order_reorder_requests.

### Admin / Settings / RBAC (34 tables)
- company_settings — key-value system configuration, admin-editable
- ports — loading/discharge port master list
- incoterms — FOB/CFR/CIF incoterm definitions and label templates
- currencies — supported quote/settlement currencies (USD/EUR/GBP)
- payment_presets — advance/balance % and trigger templates per buyer tier
- document_types — master list of generatable document types (QT/PI/OC/etc.)
- document_sections — named sections within a document type
- tc_clauses — terms & conditions clause library
- tc_clause_documents — join: which clauses appear on which document type
- email_templates — subject/body/footer templates for staff sends
- watermark_settings — draft vs final PDF watermark configuration
- docx_generation_settings — per-document-type toggle for optional DOCX output
- file_upload_contexts — allowed extensions/size per upload context key
- dropdown_options — generic admin-editable dropdown value lists
- roles — RBAC role definitions
- permissions — RBAC permission definitions
- role_permissions — join: which permissions a role holds
- users — staff user accounts
- user_permissions — per-user permission override (force-enable/disable)
- password_reset_tokens — staff forgot-password reset tokens (hashed)
- login_attempts — staff login attempt audit trail
- two_fa_backup_codes — one-time 2FA recovery codes
- assets — shared company logo/signature/seal/watermark images
- designations — signatory job-title list (Director, MD, etc.)
- user_signature_assets — per-user signature/designation-seal image uploads
- company_default_signatory — global fallback signatory (singleton row)
- document_type_signatories — per-document-type default signatory override
- field_protection_requests — peer-approval workflow to lock/unlock protected fields
- super_admin_delegations — temporary revocable Super Admin capability grants
- company_holidays — admin-managed holiday calendar for working-day math
- internal_reference_docs — content for fixed internal reference docs (Stage Gate Ref, Wall Ref, checklists, SOPs)
- reference_library_documents — free-form add/delete internal reference library entries
- hs_codes — master list of valid HS/tariff codes
- reference_sequences — monotonic per-day counter backing reference-number generation

### Orders — core pipeline (19 tables)
- stages_master — the 9-stage pipeline definition
- orders — the central order record
- order_stages — per-order stage-gate status tracking
- order_products — order line items (product/qty/price)
- order_payment_status — advance/balance/freight payment + INR/FIRC/Zoho tracking
- order_production — production start/completion tracking per order
- order_supplier_po — supplier purchase order details per order
- order_packing — packing/weight/CBM/fumigation data per order
- order_crates — per-crate packing detail rows
- order_freight — freight rate/forwarder/FDN/clearance data
- order_shipping — vessel/BL/container shipping detail
- order_annexure_products — Annexure A product spec rows per order
- order_annexure_images — images attached to annexure product rows
- order_buyer_po_documents — uploaded evidence of buyer's signed PO
- order_supplier_po_documents — uploaded evidence of supplier's signed PO ack
- order_oc_acknowledgments — buyer OC acknowledgment record (portal/email/auto-48h)
- suppliers — supplier master records
- products — order-pipeline product master (distinct from internal catalog)
- product_images — images for order-pipeline products

### Order Comments / Chat (2 tables)
- order_comments — two-way staff/client chat thread per order
- order_comment_attachments — files attached to a chat comment

### Documents (6 tables)
- documents — generated document records (QT/PI/OC/etc.), status, snapshots
- document_revisions — immutable per-revision history/snapshot
- document_reviews — reviewer assignment/approval/rejection per document
- document_cross_verifications — independent pass/fail quality check per document
- file_store — all uploaded/generated files (generated, received, manual)
- email_log — deferred-send email queue with 2-level approval

### Amendments (1 table)
- amendments — payment-terms amendment requests, MD approval, signed-copy activation

### Disputes (2 tables)
- disputes — dispute records with working-day response deadline
- dispute_documents — files attached to a dispute

### CA / Accounts (4 tables)
- zoho_sync_log — Zoho Books sync attempt audit/error log
- ca_expenses — expenses imported one-way from Zoho Books
- ca_bank_statement_lines — imported bank statement lines for reconciliation
- ca_fy_locks — financial-year lock/unlock history

### Reports (1 table)
- report_definitions — saved report filter/column configurations

### Client Portal / Client-facing (8 tables)
- clients — client/buyer master records
- client_intake_submissions — public quotation-stage intake form submissions
- client_logins — client portal login credentials (provisioned at Stage 3)
- client_password_reset_tokens — client portal password-reset tokens (hashed)
- pi_intake_submissions — PI-stage client intake form submissions
- client_payment_reports — client-submitted "I've paid" self-reports
- order_reorder_requests — client-initiated repeat-order requests
- order_reorder_request_products — product lines for a reorder request

### Product Catalog — internal, order-pipeline-independent (4 tables)
- catalog_products — internal staff reference product catalog
- catalog_product_images — images for catalog products
- catalog_product_suppliers — multiple sourcing options per catalog product
- catalog_product_misc_charges — informational misc charges per catalog product

### Test Mode (1 table)
- test_mode_settings — singleton global Test Mode on/off + test email

### Notifications / Audit (2 tables)
- notifications — in-app notification bell entries
- audit_log — immutable system-wide audit trail (no delete/update grant)

---

## 4. Stage pipeline

9-stage order lifecycle (from `stages_master` seed data, `StageGateService.php` docblock, and `docs/SOP/01`–`09-*.md` + `ca-*`). Gate-passing call sites confirmed via `grep passAndUnlockNext` across controllers.

| # | Stage slug | Stage name | Gate condition | Passing controller::action | Route |
|---|---|---|---|---|---|
| 1 | quotation | Enquiry & Quotation | QT document generated | `DocumentController::generate` | POST /orders/{id}/documents/generate |
| 2 | buyer_po | Buyer Purchase Order | Buyer's signed PO reference recorded | `OrderController::recordBuyerPo` | POST /orders/{id}/buyer-po |
| 3 | pi | Proforma Invoice | Advance payment marked CLEARED | `OrderController::clearAdvancePayment` | POST /orders/{id}/payment/advance/clear |
| 4 | oc_production | Order Confirmation | Buyer acknowledges the OC | `OrderController::recordOcAcknowledgment` (staff-recorded email ack) **or** `ClientPortalController::acknowledgeOc` (client-portal self-ack); also auto-confirms after 48h via cron (`app/cron/auto_confirm_oc_acknowledgments.php`, per schema Section AE) | POST /orders/{id}/oc-acknowledgment ; POST /client/orders/{id}/acknowledge-oc |
| 5 | supplier_po | Supplier Purchase Order | Supplier's signed PO acknowledgment recorded | `OrderController::confirmSupplierSigned` | POST /orders/{id}/supplier-po/signed |
| 6 | freight | Freight Payment | Freight payment marked cleared (CFR/CIF only — auto-skipped for FOB via `StageGateService::maybeAutoSkipFreightStage()`, called right after Stage 5 passes) | `OrderController::clearFreightPayment` | POST /orders/{id}/payment/freight/clear |
| 7 | bl_instruction | Packing & BL Instruction | BL issuance recorded | `OrderController::recordBlIssued` | POST /orders/{id}/bl-issued |
| 8 | commercial_invoice | Commercial Invoice & Balance | Balance payment marked cleared | `OrderController::clearBalancePayment` | POST /orders/{id}/payment/balance/clear |
| 9 | closure | Document Despatch & Closure | Order manually closed (COO received + BL originals endorsed + couriered) | `OrderController::closeOrder` | POST /orders/{id}/close |

Mechanics (`app/src/Services/StageGateService.php`):
- `passAndUnlockNext(orderId, stageNumber, userId)` — idempotent; returns false (refuses) if the stage isn't currently `in_progress`, preventing any out-of-order gate-skip.
- `maybeAutoSkipFreightStage(orderId, userId)` — auto-skips Stage 6 for FOB orders (buyer arranges own freight), called right after Stage 5 passes.
- `isUnlocked(orderId, stageNumber)` — pre-flight check used before a stage-advancing controller action performs any writes.
- `currentStage(orderId)` — returns the order's current non-passed/non-skipped stage for UI display.

Sales-tier SOP variants (docs/SOP/): Tier A (`SOP_A_SALES`, "Standard — New Buyer" preset, no MD approval) and Tier B (`SOP_B_SALES`, "Established Buyer — Post-BL" preset, requires MD approval, balance due date computed from BL issuance date instead of advance clearance) — both follow the identical 9-stage flow above.

---

## 5. Other major workflows (brief pointers)

**Amendments module** — `app/src/Controllers/AmendmentController.php`, `app/src/Services/AmendmentService.php`. Payment Terms Amendment workflow (schema Section G / SOP `10-amendments.md`): staff creates an amendment request against an order → MD approval (`mdApprove`) → AMD document generated → countersigned copy uploaded → amendment activates and overrides that order's advance/balance terms (`orders.advance_pct_override` etc.) without touching the shared `payment_presets` row.

**Disputes module** — `app/src/Controllers/DisputeController.php`, table `disputes`/`dispute_documents`. Dispute logging with a working-days response deadline (`app/src/Services/WorkingDaysCalculator.php`, skips `weekly_off_days` + `company_holidays`), status tracked via `dropdown_options('dispute_status')`, evidence file uploads. Client can also raise a dispute from the portal if `orders.dispute_button_visible_to_client` is toggled on by staff (schema Section AF).

**Client Portal** (full chain: intake → review → account → orders → acknowledge OC → disputes) — `app/src/Controllers/ClientIntakeController.php` (public Quotation-stage intake form, never auto-creates a client), `ClientIntakeReviewController.php` (staff accept/reject queue → creates real `clients` row), `PiIntakeController.php`/`PiIntakeReviewController.php` (separate PI-stage intake form + review, generated per-order by staff), `ClientPortalController.php` (post-advance-payment client login: dashboard, order view, reorder request, payment self-report, OC acknowledgment, dispute raise, chat, document downloads), `app/src/Services/ClientPortalService.php` (auto-provisions `client_logins` at the Stage 3 advance-cleared gate).

**Order-Edit / reorder-request flow** — `OrderController::editDetails`/`updateDetails` (order-level edits), `app/src/Services/OrderEditGuard.php` (governs edits after Order Confirmation is issued, gated by `edit_order_post_confirmation` permission with mandatory reason), `app/src/Services/OrderDuplicationService.php` (order duplication via `OrderController::duplicateOrder`); separately, `app/src/Controllers/ReorderRequestController.php` (staff approve/reject) + `ClientPortalController::submitReorder` (client-initiated repeat-order request from the portal, pre-filled from a past order's line items, always staff-reviewed before becoming a real order — schema Section AJ).

**Test Mode (sample data generation)** — `app/src/Controllers/TestModeController.php`, `app/src/Services/TestModeService.php`, `app/src/Middleware/TestModeGate.php` (blocks client-facing routes and admin-settings writes globally while enabled, applied pre-dispatch in index.php rather than as per-route middleware). Distinct from the separate **Sample Data Playground** (`SampleDataController.php`/`SampleDataService.php`) — a one-shot canned demo dataset vs. Test Mode's live walk-the-real-UI-then-bulk-delete toggle; both use `is_test_data`/`is_sample_data` flags respectively on clients/orders/suppliers.

**CA module phases** — `app/src/Controllers/CaController.php` fronts all four phases: (1) Zoho Books revenue sync — `app/src/Services/CaSyncService.php` + `ZohoBooksService.php`, logged to `zoho_sync_log`; (2) expenses — read-only mirror imported from Zoho Books into `ca_expenses`, no manual entry; (3) bank reconciliation — `app/src/Services/BankStatementCsvParser.php` imports CSV statement lines into `ca_bank_statement_lines`, manually matched to a settlement leg or expense; (4) FY lock — `app/src/Services/CaFyLockGuard.php` enforces `ca_fy_locks`, with `ca_fy_lock_override` permission allowing a logged, exceptional backdated entry.
