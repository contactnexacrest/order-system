'use strict';

const path = require('path');
const env = require('./config/env'); // loads .env as a side effect — must be first
const express = require('express');
const session = require('express-session');
const { ConnectSessionKnexStore } = require('connect-session-knex');
const knex = require('knex');
const multer = require('multer');
const nunjucks = require('nunjucks');

const csrf = require('./helpers/csrf');
const flash = require('./helpers/flash');
const mask = require('./helpers/mask');
const dates = require('./helpers/dates');
const logger = require('./helpers/logger');

// Process-level safety net — without these, an error thrown outside any
// Express request handler (a stray async callback, a timer) used to just
// print to console and vanish, or crash the process with nothing recorded.
// Still exits after logging: process state after a truly uncaught error is
// unknown, and a process manager (PM2/systemd — see the deployment guide)
// is what should restart it, not this process limping on.
process.on('uncaughtException', (err) => {
  logger.error('UNCAUGHT EXCEPTION', err);
  process.exit(1);
});
process.on('unhandledRejection', (reason) => {
  logger.error('UNHANDLED REJECTION', reason instanceof Error ? reason : new Error(String(reason)));
  process.exit(1);
});
const sessionAuth = require('./middleware/sessionAuth');
const permissionCheck = require('./middleware/permissionCheck');
const csrfCheck = require('./middleware/csrfCheck');
const testModeGate = require('./middleware/testModeGate');

const authController = require('./controllers/authController');
const dashboardController = require('./controllers/dashboardController');
const settingsController = require('./controllers/settingsController');
const holidayController = require('./controllers/holidayController');
const referenceDocController = require('./controllers/referenceDocController');
const assetController = require('./controllers/assetController');
const clientsController = require('./controllers/clientsController');
const clientIntakeController = require('./controllers/clientIntakeController');
const clientIntakeReviewController = require('./controllers/clientIntakeReviewController');
const clientPortalController = require('./controllers/clientPortalController');
const ordersController = require('./controllers/ordersController');
const annexureController = require('./controllers/annexureController');
const documentController = require('./controllers/documentController');
const fileStoreController = require('./controllers/fileStoreController');
const reviewController = require('./controllers/reviewController');
const amendmentController = require('./controllers/amendmentController');
const disputeController = require('./controllers/disputeController');
const emailDispatchController = require('./controllers/emailDispatchController');
const auditLogController = require('./controllers/auditLogController');
const notificationController = require('./controllers/notificationController');
const reportController = require('./controllers/reportController');
const userController = require('./controllers/userController');
const adminOverrideController = require('./controllers/adminOverrideController');
const fieldProtectionController = require('./controllers/fieldProtectionController');
const sampleDataController = require('./controllers/sampleDataController');
const signatoryController = require('./controllers/signatoryController');
const superAdminController = require('./controllers/superAdminController');
const permissionAdminController = require('./controllers/permissionAdminController');
const productsController = require('./controllers/productsController');
const testModeController = require('./controllers/testModeController');
const superAdminOnly = require('./middleware/superAdminOnly');
const clientAuth = require('./middleware/clientAuth');

const app = express();
const viewsDir = path.join(__dirname, '..', 'views');

// --- View engine (Nunjucks) ---------------------------------------------
const njkEnv = nunjucks.configure(viewsDir, {
  autoescape: true,
  express: app,
  watch: false, // template edits need a process restart; avoids an extra chokidar dependency
});
njkEnv.addFilter('maskEmail', mask.maskEmail);
njkEnv.addFilter('maskPhone', mask.maskPhone);
njkEnv.addFilter('humanDate', dates.human);
// nl2br mirrors PHP's nl2br(htmlspecialchars($v)) — escape first (autoescape
// is on globally, so this filter must do its own escaping since it returns
// markup), then turn newlines into <br>, then mark safe.
njkEnv.addFilter('nl2br', (value) => {
  const escaped = nunjucks.runtime.suppressValue(value == null ? '' : String(value), true);
  return new nunjucks.runtime.SafeString(escaped.replace(/\r\n|\r|\n/g, '<br>\n'));
});
// PHP's date('Y-m-d') used inline in a view for a date <input>'s default
// value — a callable global (not a fixed value baked in at configure()
// time) so every request gets the render-time date, not the server's boot
// date.
njkEnv.addGlobal('today', () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
});

// --- Session store (MySQL-backed via knex, not PHP's file-session
// equivalent — Node is a long-running multi-connection process, so a
// shared, persistent session store is the correct analog, not a behavior
// change) ------------------------------------------------------------------
const sessionKnex = knex({
  client: 'mysql2',
  connection: {
    host: env.get('DB_HOST', '127.0.0.1'),
    port: env.getInt('DB_PORT', 3306),
    database: env.get('DB_DATABASE'),
    user: env.get('DB_USERNAME'),
    password: env.get('DB_PASSWORD', ''),
  },
});

app.set('trust proxy', 1);
app.use(express.urlencoded({ extended: true }));
app.use(express.json());
app.use(express.static(path.join(__dirname, '..', 'public')));

app.use(session({
  store: new ConnectSessionKnexStore({ knex: sessionKnex, createTable: true, tableName: 'sessions', sidFieldName: 'sid' }),
  name: 'nexacrest_session',
  secret: env.get('SESSION_SECRET_KEY', 'change-this-in-.env'),
  resave: false,
  saveUninitialized: false,
  rolling: false,
  cookie: {
    path: '/',
    httpOnly: true,
    secure: !env.isLocal(), // HTTPS cookie flag off only for local http:// testing
    sameSite: 'lax',
    maxAge: null, // session cookie — idle timeout is enforced in app logic (SessionAuth), not the cookie
  },
}));

// asyncHandler is declared further below (used by every route registration);
// these three global middleware run earlier in the stack and need the same
// promise-rejection-to-next(err) wrapping, so a small local copy lives here
// too rather than hoisting the route-handler one up past its usual spot.
function wrapAsyncMiddleware(fn) {
  return (req, res, next) => Promise.resolve(fn(req, res, next)).catch(next);
}

// Test Mode's two access gates (docs/schema.sql Section V) run before
// everything else: the client-facing block must intercept public routes
// that never reach requireAuth, and the admin-settings freeze must apply
// regardless of role, so neither can be expressed as per-route middleware
// alongside requirePermission().
app.use(wrapAsyncMiddleware(testModeGate.injectLocals()));
app.use(wrapAsyncMiddleware(testModeGate.blockClientFacingInTestMode()));
app.use(wrapAsyncMiddleware(testModeGate.blockAdminWritesInTestMode()));

// --- res.renderView(viewPath, data, layout) — Express's res.render()
// doesn't have a layout concept by default, so this reproduces the PHP
// View::render() contract: render the inner template, then wrap it in a
// layout template that receives {content}. Common context (CSRF token,
// flash messages, current user, permissions, unread count) is merged in
// automatically so every controller doesn't have to thread it through. ----

app.use((req, res, next) => {
  res.renderView = function renderView(viewPath, data = {}, layout = 'layout/base') {
    const common = {
      csrfToken: csrf.token(req),
      flashes: flash.pull(req),
      currentUser: req.user || null,
      permissions: req.permissions || {},
      isSuperAdmin: req.isSuperAdmin || false,
      unreadCount: req.unreadCount || 0,
      testModeEnabled: req.testModeEnabled || false,
    };
    const context = Object.assign({}, common, data);
    const content = njkEnv.render(`${viewPath}.njk`, context);
    if (layout === null) {
      res.send(content);
      return;
    }
    res.send(njkEnv.render(`${layout}.njk`, Object.assign({}, context, { content })));
  };
  next();
});

const upload = multer({ storage: multer.memoryStorage(), limits: { fileSize: 5 * 1024 * 1024 } });
// Amendment signed copies and dispute documents allow up to 10MB per
// file_upload_contexts (see fileUploadService, which enforces the real
// per-context limit from that table) — this instance's own cap is just
// generous enough not to reject a valid upload before fileUploadService
// gets a chance to check it against the DB-configured limit.
const uploadLarge = multer({ storage: multer.memoryStorage(), limits: { fileSize: 15 * 1024 * 1024 } });

function asyncHandler(fn) {
  return (req, res, next) => Promise.resolve(fn(req, res, next)).catch(next);
}

const requireAuth = asyncHandler(sessionAuth.required());
function requirePermission(key) {
  return asyncHandler(permissionCheck.requires(key));
}
const verifyCsrf = csrfCheck.verify();

// =========================================================================
// Routes — mirrors public_html/index.php 1:1. Phases not yet built keep
// their route block commented out immediately below where it belongs, so
// the next phase's routes drop in at the same spot with the same
// middleware chain the PHP original used.
// =========================================================================

// --- Public (unauthenticated) routes ---
app.get('/login', asyncHandler(authController.showLogin));
app.post('/login', verifyCsrf, asyncHandler(authController.login));
app.get('/2fa', asyncHandler(authController.show2fa));
app.post('/2fa', verifyCsrf, asyncHandler(authController.verify2fa));
app.get('/logout', asyncHandler(authController.logout));
app.get('/forgot-password', asyncHandler(authController.showForgotPassword));
app.post('/forgot-password', verifyCsrf, asyncHandler(authController.forgotPassword));
app.get('/reset-password/:token', asyncHandler(authController.showResetPassword));
app.post('/reset-password/:token', verifyCsrf, asyncHandler(authController.resetPassword));

// Public quotation-stage intake form — the actual entry point into the
// system for a Zoho-qualified prospect. No auth: staff sends this link
// directly. Submitting only ever creates a client_intake_submissions row,
// never a client or order (see clientIntakeController's docblock).
app.get('/quotation-details', asyncHandler(clientIntakeController.show));
app.post('/quotation-details/submit', verifyCsrf, asyncHandler(clientIntakeController.submit));

// Client portal login/set-password — public (unauthenticated) by nature,
// gated instead by the client_logins row provisioned at the Stage 3
// advance-cleared gate (clientPortalService.provisionIfNeeded).
app.get('/client/login', asyncHandler(clientPortalController.showLogin));
app.post('/client/login', verifyCsrf, asyncHandler(clientPortalController.login));
app.get('/client/logout', asyncHandler(clientPortalController.logout));
app.get('/client/set-password/:token', asyncHandler(clientPortalController.showSetPassword));
app.post('/client/set-password/:token', verifyCsrf, asyncHandler(clientPortalController.setPassword));

// --- Authenticated routes ---
app.get('/force-password-change', requireAuth, asyncHandler(authController.showForcePasswordChange));
app.post('/force-password-change', requireAuth, verifyCsrf, asyncHandler(authController.forcePasswordChange));

app.get('/', requireAuth, asyncHandler(dashboardController.index));

app.get('/settings', requireAuth, requirePermission('manage_company_settings'), asyncHandler(settingsController.index));
app.post('/settings/update', requireAuth, requirePermission('manage_company_settings'), verifyCsrf, asyncHandler(settingsController.update));
app.get('/holidays', requireAuth, requirePermission('manage_company_settings'), asyncHandler(holidayController.index));
app.post('/holidays', requireAuth, requirePermission('manage_company_settings'), verifyCsrf, asyncHandler(holidayController.create));
app.post('/holidays/:id/delete', requireAuth, requirePermission('manage_company_settings'), verifyCsrf, asyncHandler(holidayController.remove));
app.get('/reference-docs', requireAuth, asyncHandler(referenceDocController.index));
app.get('/reference-docs/:code', requireAuth, asyncHandler(referenceDocController.show));
app.get('/reference-docs/:code/edit', requireAuth, requirePermission('manage_company_settings'), asyncHandler(referenceDocController.edit));
app.post('/reference-docs/:code', requireAuth, requirePermission('manage_company_settings'), verifyCsrf, asyncHandler(referenceDocController.update));

// NOTE: deliberately "/company-assets", not "/assets" — that path collides
// with the static public/ folder (css/js/img), same reasoning as the PHP original.
app.get('/company-assets', requireAuth, requirePermission('manage_assets'), asyncHandler(assetController.index));
app.get('/company-assets/preview', requireAuth, asyncHandler(assetController.preview));
app.post('/company-assets/replace', requireAuth, requirePermission('manage_assets'), upload.single('file'), verifyCsrf, asyncHandler(assetController.replace));
app.post('/company-assets/:id/delete', requireAuth, requirePermission('delete_assets'), verifyCsrf, asyncHandler(assetController.remove));

app.get('/signatories', requireAuth, requirePermission('manage_signatories'), asyncHandler(signatoryController.index));
app.post('/signatories/designations', requireAuth, requirePermission('manage_signatories'), verifyCsrf, asyncHandler(signatoryController.createDesignation));
app.post('/signatories/designations/:id/toggle', requireAuth, requirePermission('manage_signatories'), verifyCsrf, asyncHandler(signatoryController.toggleDesignation));
app.post('/signatories/users/:id/eligibility', requireAuth, requirePermission('manage_signatories'), verifyCsrf, asyncHandler(signatoryController.setEligibility));
app.post('/signatories/users/:id/upload', requireAuth, requirePermission('manage_signatories'), upload.single('file'), verifyCsrf, asyncHandler(signatoryController.uploadUserAsset));
app.post('/signatories/user-assets/:id/deactivate', requireAuth, requirePermission('manage_signatories'), verifyCsrf, asyncHandler(signatoryController.deactivateUserAsset));
app.post('/signatories/global-default', requireAuth, requirePermission('manage_signatories'), verifyCsrf, asyncHandler(signatoryController.setGlobalDefault));
app.post('/signatories/document-types/:id', requireAuth, requirePermission('manage_signatories'), verifyCsrf, asyncHandler(signatoryController.setDocumentTypeDefault));

const requireSuperAdmin = asyncHandler(superAdminOnly.required());
app.get('/super-admin', requireAuth, requireSuperAdmin, asyncHandler(superAdminController.index));
app.post('/super-admin/delegations', requireAuth, requireSuperAdmin, verifyCsrf, asyncHandler(superAdminController.grantDelegation));
app.post('/super-admin/delegations/:id/revoke', requireAuth, requireSuperAdmin, verifyCsrf, asyncHandler(superAdminController.revokeDelegation));
app.post('/super-admin/set-permanent', requireAuth, requireSuperAdmin, verifyCsrf, asyncHandler(superAdminController.setPermanent));

// Test Mode (docs/schema.sql Section V) — Super Admin only, same tier as
// /super-admin itself, given how broadly it affects the whole system
// (client access, every business email, every admin settings screen).
app.get('/test-mode', requireAuth, requireSuperAdmin, asyncHandler(testModeController.index));
app.post('/test-mode/enable', requireAuth, requireSuperAdmin, verifyCsrf, asyncHandler(testModeController.enable));
app.post('/test-mode/disable', requireAuth, requireSuperAdmin, verifyCsrf, asyncHandler(testModeController.disable));
app.post('/test-mode/test-email', requireAuth, requireSuperAdmin, verifyCsrf, asyncHandler(testModeController.updateTestEmail));
app.post('/test-mode/delete-test-data', requireAuth, requireSuperAdmin, verifyCsrf, asyncHandler(testModeController.deleteTestData));

app.get('/admin/permissions', requireAuth, requirePermission('manage_permissions'), asyncHandler(permissionAdminController.index));
app.post('/admin/permissions/grant', requireAuth, requirePermission('manage_permissions'), verifyCsrf, asyncHandler(permissionAdminController.grantOverride));
app.post('/admin/permissions/:id/remove', requireAuth, requirePermission('manage_permissions'), verifyCsrf, asyncHandler(permissionAdminController.removeOverride));
app.post('/admin/roles', requireAuth, requirePermission('manage_permissions'), verifyCsrf, asyncHandler(permissionAdminController.roleCreate));
app.get('/admin/roles/:id/edit', requireAuth, requirePermission('manage_permissions'), asyncHandler(permissionAdminController.roleEditForm));
app.post('/admin/roles/:id/update', requireAuth, requirePermission('manage_permissions'), verifyCsrf, asyncHandler(permissionAdminController.roleUpdate));
app.post('/admin/roles/:id/delete', requireAuth, requirePermission('manage_permissions'), verifyCsrf, asyncHandler(permissionAdminController.roleDelete));
app.get('/admin/roles/:id/permissions', requireAuth, requirePermission('manage_permissions'), asyncHandler(permissionAdminController.rolePermissionsForm));
app.post('/admin/roles/:id/permissions', requireAuth, requirePermission('manage_permissions'), verifyCsrf, asyncHandler(permissionAdminController.rolePermissionsUpdate));
app.post('/admin/permission-definitions', requireAuth, requirePermission('manage_permissions'), verifyCsrf, asyncHandler(permissionAdminController.permissionCreate));
app.get('/admin/permission-definitions/:id/edit', requireAuth, requirePermission('manage_permissions'), asyncHandler(permissionAdminController.permissionEditForm));
app.post('/admin/permission-definitions/:id/update', requireAuth, requirePermission('manage_permissions'), verifyCsrf, asyncHandler(permissionAdminController.permissionUpdate));
app.post('/admin/permission-definitions/:id/delete', requireAuth, requirePermission('manage_permissions'), verifyCsrf, asyncHandler(permissionAdminController.permissionDelete));

// --- Phase B: clients / orders / stage gates / document generation ---
app.get('/clients', requireAuth, requirePermission('manage_orders'), asyncHandler(clientsController.index));
app.get('/clients/create', requireAuth, requirePermission('manage_orders'), asyncHandler(clientsController.create));
app.post('/clients', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(clientsController.store));
app.get('/clients/:id', requireAuth, requirePermission('manage_orders'), asyncHandler(clientsController.show));

// Staff review queue for public quotation-details submissions.
app.get('/client-intake', requireAuth, requirePermission('manage_orders'), asyncHandler(clientIntakeReviewController.index));
app.post('/client-intake/:id/accept', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(clientIntakeReviewController.accept));
app.post('/client-intake/:id/reject', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(clientIntakeReviewController.reject));

app.get('/orders', requireAuth, requirePermission('manage_orders'), asyncHandler(ordersController.index));
app.get('/orders/archived', requireAuth, requirePermission('view_archived_orders'), asyncHandler(ordersController.archivedIndex));
app.get('/orders/create', requireAuth, requirePermission('manage_orders'), asyncHandler(ordersController.create));
app.post('/orders', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.store));
app.get('/orders/:id', requireAuth, requirePermission('manage_orders'), asyncHandler(ordersController.show));
app.post('/orders/:id/archive', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.archive));
app.post('/orders/:id/unarchive', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.unarchive));
app.post('/orders/:id/buyer-po', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.recordBuyerPo));
app.post('/orders/:id/buyer-po/documents', requireAuth, requirePermission('manage_orders'), uploadLarge.single('document'), verifyCsrf, asyncHandler(ordersController.uploadBuyerPoDocument));
app.post('/orders/:id/payment/advance', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.recordAdvancePayment));
app.post('/orders/:id/payment/advance/clear', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.clearAdvancePayment));
app.post('/orders/:id/production-status', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.updateProductionStatus));

// Annexure A — Product Technical Specifications (schema tables shipped
// with no screen ever built against them; this is that missing piece).
app.get('/orders/:id/annexure', requireAuth, requirePermission('manage_orders'), asyncHandler(annexureController.index));
app.post('/orders/:id/annexure/toggle', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(annexureController.toggleInclude));
app.post('/orders/:id/annexure/products', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(annexureController.createProduct));
app.post('/orders/:id/annexure/products/:productId', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(annexureController.updateProduct));
app.post('/orders/:id/annexure/products/:productId/delete', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(annexureController.deleteProduct));
app.post('/orders/:id/annexure/products/:productId/images', requireAuth, requirePermission('manage_orders'), upload.single('image'), verifyCsrf, asyncHandler(annexureController.uploadImage));
app.post('/orders/:id/annexure/images/:imageId/remove', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(annexureController.removeImage));

app.post('/orders/:id/buyer-acknowledged', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.confirmBuyerAcknowledged));

app.post('/orders/:id/suppliers', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.createSupplier));
app.post('/orders/:id/supplier-po', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.saveSupplierPo));
app.post('/orders/:id/supplier-po/signed', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.confirmSupplierSigned));
app.post('/orders/:id/supplier-po/documents', requireAuth, requirePermission('manage_orders'), uploadLarge.single('document'), verifyCsrf, asyncHandler(ordersController.uploadSupplierPoDocument));

app.post('/orders/:id/freight-terms', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.saveFreightTerms));
app.post('/orders/:id/payment/freight', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.recordFreightPayment));
app.post('/orders/:id/payment/freight/clear', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.clearFreightPayment));

app.post('/orders/:id/packing', requireAuth, requirePermission('manage_orders'), upload.single('buyer_approval'), verifyCsrf, asyncHandler(ordersController.savePacking));
app.post('/orders/:id/shipping', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.saveShipping));
app.post('/orders/:id/bl-issued', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.recordBlIssued));
app.post('/orders/:id/scanned-bl-sent', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.recordScannedBlSent));

app.post('/orders/:id/payment/balance', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.recordBalancePayment));
app.post('/orders/:id/payment/balance/clear', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.clearBalancePayment));

app.post('/orders/:id/bl-originals-received', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.recordBlOriginalsReceived));
app.post('/orders/:id/bl-endorsed', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.recordBlEndorsed));
app.post('/orders/:id/close', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.closeOrder));
app.post('/orders/:id/mark-lost', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.markLost));

app.post('/orders/:id/documents/generate', requireAuth, requirePermission('generate_documents'), verifyCsrf, asyncHandler(documentController.generate));
app.get('/documents/:documentId/download', requireAuth, requirePermission('download_pdf'), asyncHandler(documentController.download));
app.get('/file-store/:id/download', requireAuth, requirePermission('manage_orders'), asyncHandler(fileStoreController.download));

// Admin field-override routes for clients/orders (Spec Section 13) — wired
// here alongside their owning controllers rather than deferred to Phase E,
// since clientsController/ordersController already exist.
app.post('/clients/:id/override-unique-number', requireAuth, requirePermission('edit_locked_data'), verifyCsrf, asyncHandler(clientsController.overrideUniqueNumber));
app.post('/orders/:id/override-status-lock', requireAuth, requirePermission('edit_locked_data'), verifyCsrf, asyncHandler(ordersController.overrideStatusLock));

// --- Product Interface (internal product catalog, docs/schema.sql Section U) ---
app.get('/products', requireAuth, requirePermission('view_product_catalog'), asyncHandler(productsController.index));
app.get('/products/create', requireAuth, requirePermission('manage_product_catalog'), asyncHandler(productsController.create));
app.post('/products/create', requireAuth, requirePermission('manage_product_catalog'), verifyCsrf, asyncHandler(productsController.store));
app.get('/products/:id', requireAuth, requirePermission('view_product_catalog'), asyncHandler(productsController.show));
app.get('/products/:id/edit', requireAuth, requirePermission('manage_product_catalog'), asyncHandler(productsController.edit));
app.post('/products/:id/update', requireAuth, requirePermission('manage_product_catalog'), verifyCsrf, asyncHandler(productsController.update));
app.post('/products/:id/delete', requireAuth, requirePermission('manage_product_catalog'), verifyCsrf, asyncHandler(productsController.remove));
app.post('/products/:id/images/upload', requireAuth, requirePermission('manage_product_catalog'), upload.single('image'), verifyCsrf, asyncHandler(productsController.uploadImage));
app.post('/products/images/:imageId/delete', requireAuth, requirePermission('manage_product_catalog'), verifyCsrf, asyncHandler(productsController.deleteImage));
app.get('/products/images/:imageId/view', requireAuth, requirePermission('view_product_catalog'), asyncHandler(productsController.viewImage));
app.post('/products/:id/suppliers/add', requireAuth, requirePermission('manage_product_catalog'), verifyCsrf, asyncHandler(productsController.addSupplier));
app.post('/products/suppliers/:supplierId/update', requireAuth, requirePermission('manage_product_catalog'), verifyCsrf, asyncHandler(productsController.updateSupplier));
app.post('/products/suppliers/:supplierId/delete', requireAuth, requirePermission('manage_product_catalog'), verifyCsrf, asyncHandler(productsController.deleteSupplier));
app.post('/products/suppliers/:supplierId/set-primary', requireAuth, requirePermission('manage_product_catalog'), verifyCsrf, asyncHandler(productsController.setPrimarySupplier));
app.post('/products/:id/misc-charges/add', requireAuth, requirePermission('manage_product_catalog'), verifyCsrf, asyncHandler(productsController.addMiscCharge));
app.post('/products/misc-charges/:chargeId/delete', requireAuth, requirePermission('manage_product_catalog'), verifyCsrf, asyncHandler(productsController.deleteMiscCharge));

// --- Phase C: Stages 4-9 ---
// (routes land here once Phase C's controllers exist)

// --- Phase D: review/approval, deferred send, amendments, disputes, audit log ---
// Reviewer assignment/approval/rejection ordering matters: the assigned
// reviewer check for approve/reject happens inside reviewWorkflowService
// (any authenticated user can hit the route; the service throws if they're
// not the assigned reviewer for that specific review row) — mirrors the
// PHP router's identical permission list (approve/reject take no
// PermissionCheck, only SessionAuth::required()).
app.get('/reviews', requireAuth, asyncHandler(reviewController.queue));
app.post('/documents/:documentId/reviewers', requireAuth, requirePermission('approve_documents'), verifyCsrf, asyncHandler(reviewController.assign));
app.post('/reviews/:reviewId/approve', requireAuth, verifyCsrf, asyncHandler(reviewController.approve));
app.post('/reviews/:reviewId/reject', requireAuth, verifyCsrf, asyncHandler(reviewController.reject));
app.post('/documents/:documentId/cross-verify', requireAuth, requirePermission('cross_verify_documents'), verifyCsrf, asyncHandler(reviewController.crossVerify));

app.get('/orders/:id/documents/:documentId/send', requireAuth, requirePermission('generate_documents'), asyncHandler(emailDispatchController.compose));
app.post('/orders/:id/documents/:documentId/send', requireAuth, requirePermission('generate_documents'), verifyCsrf, asyncHandler(emailDispatchController.requestSend));
app.get('/email-approvals', requireAuth, requirePermission('approve_email_send'), asyncHandler(emailDispatchController.approvalQueue));
app.post('/email-log/:emailLogId/approve', requireAuth, requirePermission('approve_email_send'), verifyCsrf, asyncHandler(emailDispatchController.approve));
app.post('/email-log/:emailLogId/reject', requireAuth, requirePermission('approve_email_send'), verifyCsrf, asyncHandler(emailDispatchController.reject));
// No requirePermission wrapper: a Level-2 approver may cancel any row, but a
// plain requester may also cancel their own — that ownership check can only
// happen once the row is loaded, so it lives in emailDispatchService.cancelSend().
app.post('/email-log/:emailLogId/cancel', requireAuth, verifyCsrf, asyncHandler(emailDispatchController.cancel));

app.get('/orders/:id/amendments', requireAuth, requirePermission('manage_orders'), asyncHandler(amendmentController.index));
app.post('/orders/:id/amendments', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(amendmentController.create));
app.post('/amendments/:amendmentId/md-approve', requireAuth, requirePermission('approve_documents'), verifyCsrf, asyncHandler(amendmentController.mdApprove));
app.post('/amendments/:amendmentId/reject', requireAuth, requirePermission('approve_documents'), verifyCsrf, asyncHandler(amendmentController.reject));
app.post('/amendments/:amendmentId/generate-document', requireAuth, requirePermission('generate_documents'), verifyCsrf, asyncHandler(amendmentController.generateDocument));
app.post('/amendments/:amendmentId/signed-copy', requireAuth, requirePermission('manage_orders'), uploadLarge.single('signed_copy'), verifyCsrf, asyncHandler(amendmentController.uploadSignedCopy));
app.post('/amendments/:amendmentId/override-reference', requireAuth, requirePermission('edit_locked_data'), verifyCsrf, asyncHandler(amendmentController.overrideReference));

app.get('/disputes', requireAuth, requirePermission('manage_orders'), asyncHandler(disputeController.index));
app.get('/orders/:id/disputes', requireAuth, requirePermission('manage_orders'), asyncHandler(disputeController.forOrder));
app.post('/orders/:id/disputes', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(disputeController.create));
app.post('/disputes/:disputeId/status', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(disputeController.updateStatus));
app.post('/disputes/:disputeId/documents', requireAuth, requirePermission('manage_orders'), uploadLarge.single('document'), verifyCsrf, asyncHandler(disputeController.uploadDocument));

app.get('/audit-log', requireAuth, requirePermission('view_audit_log'), asyncHandler(auditLogController.index));
app.get('/orders/:id/audit-log', requireAuth, requirePermission('view_audit_log'), asyncHandler(auditLogController.forOrder));
app.get('/orders/:id/dossier', requireAuth, requirePermission('manage_orders'), asyncHandler(ordersController.downloadDossier));

app.get('/notifications', requireAuth, asyncHandler(notificationController.index));

// --- Phase E: dashboard/reports, admin field-override system, user management, sample data ---
app.get('/reports', requireAuth, requirePermission('view_reports'), asyncHandler(reportController.index));
app.get('/reports/client/:clientId', requireAuth, requirePermission('view_reports'), asyncHandler(reportController.client));
app.get('/reports/order/:orderId', requireAuth, requirePermission('view_reports'), asyncHandler(reportController.order));
app.get('/reports/aggregate', requireAuth, requirePermission('view_reports'), asyncHandler(reportController.aggregate));
app.get('/reports/queues', requireAuth, requirePermission('view_reports'), asyncHandler(reportController.queues));
app.post('/reports/save', requireAuth, requirePermission('manage_report_definitions'), verifyCsrf, asyncHandler(reportController.saveDefinition));
app.get('/reports/saved/:reportId/run', requireAuth, requirePermission('view_reports'), asyncHandler(reportController.runDefinition));
app.post('/reports/saved/:reportId/delete', requireAuth, requirePermission('manage_report_definitions'), verifyCsrf, asyncHandler(reportController.deleteDefinition));

app.get('/admin/overrides', requireAuth, requirePermission('edit_locked_data'), asyncHandler(adminOverrideController.index));
app.post('/admin/overrides/document-types', requireAuth, requirePermission('edit_locked_data'), verifyCsrf, asyncHandler(adminOverrideController.updateDocumentTypes));
app.post('/admin/overrides/tc-clauses', requireAuth, requirePermission('edit_locked_data'), verifyCsrf, asyncHandler(adminOverrideController.updateTcClauses));
app.post('/admin/overrides/payment-presets', requireAuth, requirePermission('edit_locked_data'), verifyCsrf, asyncHandler(adminOverrideController.updatePaymentPresets));

app.get('/admin/field-protection', requireAuth, requirePermission('manage_field_protection'), asyncHandler(fieldProtectionController.index));
app.post('/admin/field-protection/request', requireAuth, requirePermission('manage_field_protection'), verifyCsrf, asyncHandler(fieldProtectionController.createRequest));
app.post('/admin/field-protection/:requestId/approve', requireAuth, requirePermission('manage_field_protection'), verifyCsrf, asyncHandler(fieldProtectionController.approve));
app.post('/admin/field-protection/:requestId/reject', requireAuth, requirePermission('manage_field_protection'), verifyCsrf, asyncHandler(fieldProtectionController.reject));

app.get('/users', requireAuth, requirePermission('manage_users'), asyncHandler(userController.index));
app.post('/users/create', requireAuth, requirePermission('manage_users'), verifyCsrf, asyncHandler(userController.create));
app.get('/users/:id/edit', requireAuth, requirePermission('manage_users'), asyncHandler(userController.editForm));
app.post('/users/:id/update', requireAuth, requirePermission('manage_users'), verifyCsrf, asyncHandler(userController.update));
app.post('/users/:id/toggle-active', requireAuth, requirePermission('manage_users'), verifyCsrf, asyncHandler(userController.toggleActive));
app.post('/users/:id/force-reset-password', requireAuth, requirePermission('manage_users'), verifyCsrf, asyncHandler(userController.forceResetPassword));

app.get('/sample-data', requireAuth, requirePermission('manage_sample_data'), asyncHandler(sampleDataController.index));
app.post('/sample-data/load', requireAuth, requirePermission('manage_sample_data'), verifyCsrf, asyncHandler(sampleDataController.load));
app.post('/sample-data/clear', requireAuth, requirePermission('manage_sample_data'), verifyCsrf, asyncHandler(sampleDataController.clear));

// --- Client portal (authenticated, structurally separate from staff /
// requireAuth) — gated by clientAuth.required(), a different session key. ---
const requireClientAuth = clientAuth.required();
app.get('/client', requireClientAuth, asyncHandler(clientPortalController.dashboard));
app.get('/client/account', requireClientAuth, asyncHandler(clientPortalController.showAccount));
app.post('/client/account/password', requireClientAuth, verifyCsrf, asyncHandler(clientPortalController.changePassword));
app.get('/client/orders/:id', requireClientAuth, asyncHandler(clientPortalController.showOrder));
app.get('/client/documents/:id/download', requireClientAuth, asyncHandler(clientPortalController.downloadDocument));

// --- 404 fallback (mirrors the PHP Router's default) ---
app.use((req, res) => {
  res.status(404).send('404 Not Found');
});

// --- Error handler (never leak stack traces outside APP_ENV=local, same rule as bootstrap.php's display_errors) ---
app.use((err, req, res, next) => { // eslint-disable-line no-unused-vars
  logger.error('UNHANDLED ERROR', err, { method: req.method, url: req.originalUrl, user: req.user ? req.user.id : null });
  if (env.isLocal()) {
    res.status(500).send(`<pre>${escapeHtml(err.stack || String(err))}</pre>`);
  } else {
    res.status(500).send('<h1>500 — Something went wrong</h1><p>The error has been logged.</p>');
  }
});

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

const port = env.getInt('PORT', 3000);
app.listen(port, () => {
  console.log(`NexaCrest (Node/MySQL) listening on port ${port} [APP_ENV=${env.get('APP_ENV', 'production')}]`);
});

// --- Background jobs: optional in-process scheduler ---
// Off by default — the deployment guide's default setup uses external
// OS-level cron/systemd timers instead (see src/jobs/scheduler.js's own
// docblock for the tradeoffs of each option; never run both, or alerts
// and deferred emails double-fire). Set RUN_JOBS_IN_PROCESS=true in .env
// to have this same process run them on an internal node-cron schedule
// instead of configuring separate cron entries.
if (env.getBool('RUN_JOBS_IN_PROCESS', false)) {
  require('./jobs/scheduler').start();
}

module.exports = app;
