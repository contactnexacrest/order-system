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
const sessionAuth = require('./middleware/sessionAuth');
const permissionCheck = require('./middleware/permissionCheck');
const csrfCheck = require('./middleware/csrfCheck');

const authController = require('./controllers/authController');
const dashboardController = require('./controllers/dashboardController');
const settingsController = require('./controllers/settingsController');
const assetController = require('./controllers/assetController');
const clientsController = require('./controllers/clientsController');
const ordersController = require('./controllers/ordersController');
const documentController = require('./controllers/documentController');
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
const superAdminOnly = require('./middleware/superAdminOnly');

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

// --- Authenticated routes ---
app.get('/force-password-change', requireAuth, asyncHandler(authController.showForcePasswordChange));
app.post('/force-password-change', requireAuth, verifyCsrf, asyncHandler(authController.forcePasswordChange));

app.get('/', requireAuth, asyncHandler(dashboardController.index));

app.get('/settings', requireAuth, requirePermission('manage_company_settings'), asyncHandler(settingsController.index));
app.post('/settings/update', requireAuth, requirePermission('manage_company_settings'), verifyCsrf, asyncHandler(settingsController.update));

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

app.get('/admin/permissions', requireAuth, requirePermission('manage_permissions'), asyncHandler(permissionAdminController.index));
app.post('/admin/permissions/grant', requireAuth, requirePermission('manage_permissions'), verifyCsrf, asyncHandler(permissionAdminController.grantOverride));
app.post('/admin/permissions/:id/remove', requireAuth, requirePermission('manage_permissions'), verifyCsrf, asyncHandler(permissionAdminController.removeOverride));

// --- Phase B: clients / orders / stage gates / document generation ---
app.get('/clients', requireAuth, requirePermission('manage_orders'), asyncHandler(clientsController.index));
app.get('/clients/create', requireAuth, requirePermission('manage_orders'), asyncHandler(clientsController.create));
app.post('/clients', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(clientsController.store));
app.get('/clients/:id', requireAuth, requirePermission('manage_orders'), asyncHandler(clientsController.show));

app.get('/orders', requireAuth, requirePermission('manage_orders'), asyncHandler(ordersController.index));
app.get('/orders/create', requireAuth, requirePermission('manage_orders'), asyncHandler(ordersController.create));
app.post('/orders', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.store));
app.get('/orders/:id', requireAuth, requirePermission('manage_orders'), asyncHandler(ordersController.show));
app.post('/orders/:id/buyer-po', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.recordBuyerPo));
app.post('/orders/:id/payment/advance', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.recordAdvancePayment));
app.post('/orders/:id/payment/advance/clear', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.clearAdvancePayment));
app.post('/orders/:id/production-status', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.updateProductionStatus));

app.post('/orders/:id/buyer-acknowledged', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.confirmBuyerAcknowledged));

app.post('/orders/:id/suppliers', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.createSupplier));
app.post('/orders/:id/supplier-po', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.saveSupplierPo));
app.post('/orders/:id/supplier-po/signed', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.confirmSupplierSigned));

app.post('/orders/:id/freight-terms', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.saveFreightTerms));
app.post('/orders/:id/payment/freight', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.recordFreightPayment));
app.post('/orders/:id/payment/freight/clear', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.clearFreightPayment));

app.post('/orders/:id/packing', requireAuth, requirePermission('manage_orders'), verifyCsrf, asyncHandler(ordersController.savePacking));
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

// Admin field-override routes for clients/orders (Spec Section 13) — wired
// here alongside their owning controllers rather than deferred to Phase E,
// since clientsController/ordersController already exist.
app.post('/clients/:id/override-unique-number', requireAuth, requirePermission('edit_locked_data'), verifyCsrf, asyncHandler(clientsController.overrideUniqueNumber));
app.post('/orders/:id/override-status-lock', requireAuth, requirePermission('edit_locked_data'), verifyCsrf, asyncHandler(ordersController.overrideStatusLock));

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
app.post('/users/:id/toggle-active', requireAuth, requirePermission('manage_users'), verifyCsrf, asyncHandler(userController.toggleActive));
app.post('/users/:id/force-reset-password', requireAuth, requirePermission('manage_users'), verifyCsrf, asyncHandler(userController.forceResetPassword));

app.get('/sample-data', requireAuth, requirePermission('manage_sample_data'), asyncHandler(sampleDataController.index));
app.post('/sample-data/load', requireAuth, requirePermission('manage_sample_data'), verifyCsrf, asyncHandler(sampleDataController.load));
app.post('/sample-data/clear', requireAuth, requirePermission('manage_sample_data'), verifyCsrf, asyncHandler(sampleDataController.clear));

// --- 404 fallback (mirrors the PHP Router's default) ---
app.use((req, res) => {
  res.status(404).send('404 Not Found');
});

// --- Error handler (never leak stack traces outside APP_ENV=local, same rule as bootstrap.php's display_errors) ---
app.use((err, req, res, next) => { // eslint-disable-line no-unused-vars
  console.error('[UNHANDLED ERROR]', err);
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
