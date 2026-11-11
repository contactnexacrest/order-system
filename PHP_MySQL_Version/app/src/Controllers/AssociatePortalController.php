<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Env;
use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AssociateBankRepository;
use App\Repositories\AssociateDocumentRepository;
use App\Repositories\AssociateEventRepository;
use App\Repositories\AssociateInvitationRepository;
use App\Repositories\AssociatePortalRepository;
use App\Repositories\AssociateRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\IncentiveRepository;
use App\Services\AssociateCodeService;
use App\Services\AssociatePortalService;
use App\Services\AssociateService;
use App\Services\FileUploadService;

/**
 * Owner decision 1 Nov 2026 ("AS all as recommended"): the associate's own
 * portal. Every page shows only the signed-in associate's own clients,
 * orders, commission and profile — the queries are scoped to their id on
 * the server, never by anything the browser sends.
 */
final class AssociatePortalController
{
    private const LAYOUT = 'layout/associate';

    private static function me(): array
    {
        return AssociatePortalService::current() ?? [];
    }

    /** Q-R8-2: refuses a change by a suspended associate; true when refused (the redirect is sent). */
    private static function refuseWhenSuspended(array $a, string $back): bool
    {
        if (!AssociatePortalService::isSuspended($a)) {
            return false;
        }
        Flash::set('error', AssociatePortalService::SUSPENDED_REFUSED);
        header('Location: ' . $back);
        return true;
    }

    /** A missing record (or another associate's) inside the portal layout: 404, same wording, a way back home. */
    private static function notFound(string $message): void
    {
        http_response_code(404);
        self::render('not_found', ['message' => $message]);
    }

    private static function render(string $view, array $data = []): void
    {
        $a = self::me();
        View::render('associate_portal/' . $view, $data + ['me' => $a, 'unread' => $a ? AssociateEventRepository::unreadNotices((int) $a['id']) : 0,
            'access' => $a ? \App\Services\AssociateAccess::features($a) : []], self::LAYOUT); // D-261: what the access level shows
    }

    // ---------------------------------------------------------------- login

    public function showLogin(array $params): void
    {
        View::render('associate_portal/login', [], self::LAYOUT);
    }

    public function login(array $params): void
    {
        $r = AssociatePortalService::attemptLogin(trim((string) ($_POST['email'] ?? '')), (string) ($_POST['password'] ?? ''));
        if ($r['status'] !== 'code_sent') {
            Flash::set('error', match ($r['status']) {
                'locked' => 'Too many wrong attempts — try again in 15 minutes.',
                'disabled' => 'Your portal access is not active. Please contact your NexaCrest relationship manager.',
                default => 'E-mail or password is not correct.',
            });
            header('Location: /associate/login');
            return;
        }
        if (AssociatePortalService::showCodeOnScreen()) {
            Flash::set('info', 'Development only — your login code is ' . $r['code'] . '.');
        }
        header('Location: /associate/login/code');
    }

    public function showCode(array $params): void
    {
        if (AssociatePortalService::pendingId() === null) {
            header('Location: /associate/login');
            return;
        }
        View::render('associate_portal/code', [], self::LAYOUT);
    }

    public function verifyCode(array $params): void
    {
        if (!AssociatePortalService::verifyCode((string) ($_POST['code'] ?? ''))) {
            Flash::set('error', 'That code is not correct or has expired. Log in again to get a new code.');
            header('Location: /associate/login/code');
            return;
        }
        header('Location: /associate');
    }

    public function logout(array $params): void
    {
        // QA ACC-15: signing out changes the session, so only the posted form (with
        // its CSRF token) does it; a plain link or <img src> to the address does nothing.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            header('Location: ' . (AssociatePortalService::currentId() !== null ? '/associate' : '/associate/login'));
            return;
        }
        if (AssociatePortalService::viewAsStaffId() !== null) {
            $id = AssociatePortalService::endViewAs();
            header('Location: /associates/' . (int) $id);
            return;
        }
        AssociatePortalService::logout();
        header('Location: /associate/login');
    }

    /** D-282: "Forgot password?" for associates. */
    public function showForgotPassword(array $params): void
    {
        View::render('associate_portal/forgot_password', [], self::LAYOUT);
    }

    public function forgotPassword(array $params): void
    {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', $email === '' ? 'Enter your e-mail address.' : 'Enter a valid e-mail address.');
            header('Location: /associate/forgot-password');
            return;
        }
        $link = AssociatePortalService::sendResetLink($email);
        Flash::set('success', "If {$email} belongs to an associate account, we have sent a link to set a new password. The link is valid for 72 hours and works once.");
        if ($link !== null && AssociatePortalService::showCodeOnScreen()) {
            Flash::set('info', 'Development only — link: ' . $link);
        }
        header('Location: /associate/login');
    }

    public function showSetPassword(array $params): void
    {
        $a = AssociatePortalService::associateForSetPassword((string) $params['token']);
        View::render('associate_portal/set_password', ['valid' => $a !== null, 'token' => (string) $params['token'], 'email' => $a['email'] ?? ''], self::LAYOUT);
    }

    public function setPassword(array $params): void
    {
        $token = (string) $params['token'];
        $p = (string) ($_POST['new_password'] ?? '');
        $c = (string) ($_POST['confirm_password'] ?? '');
        $error = match (true) {
            mb_strlen($p) < 10 || !preg_match('/[A-Za-z]/', $p) || !preg_match('/\d/', $p) => 'Use at least 10 characters, with letters and digits.',
            $p !== $c => 'The two passwords do not match.',
            default => null,
        };
        if ($error !== null) {
            Flash::set('error', $error);
            header("Location: /associate/set-password/{$token}");
            return;
        }
        if (!AssociatePortalService::setPassword($token, $p)) {
            Flash::set('error', 'This link has expired or was already used. Use "Forgot password?" on the login page to get a new one.');
            header("Location: /associate/set-password/{$token}");
            return;
        }
        Flash::set('success', 'Password set. Log in with your e-mail and the new password.');
        header('Location: /associate/login');
    }

    // ---------------------------------------------------------------- pages

    public function home(array $params): void
    {
        $id = (int) self::me()['id'];
        self::render('home', [
            'totals' => AssociatePortalRepository::totals($id),
            'clients' => AssociatePortalRepository::clients($id),
            'orders' => self::visibleOrders(array_slice(AssociatePortalRepository::orders($id), 0, 5)),
            'events' => AssociateEventRepository::forAssociate($id, null, 10),
            'invitations' => AssociateInvitationRepository::forAssociate($id),
        ]);
        AssociateEventRepository::markNoticesRead($id);
    }

    public function clients(array $params): void
    {
        $id = (int) self::me()['id'];
        self::render('clients', [
            'clients' => AssociatePortalRepository::clients($id),
            'invitations' => AssociateInvitationRepository::forAssociate($id),
            'newLink' => $_SESSION['_associate_new_link'] ?? null,
            'codeLink' => self::codeLink(self::me()),
            // D-254: the code change "like a captcha refresh" and every code the associate has had.
            'proposal' => ($p = $_SESSION['associate_code_proposal'] ?? null) && (int) ($p['associate_id'] ?? 0) === $id ? $p : null,
            'codes' => AssociateCodeService::codes($id),
            'codeChangesPerYear' => AssociateCodeService::changesPerYear(),
            'codeChangesUsed' => AssociateCodeService::changesInLastYear($id),
        ]);
        unset($_SESSION['_associate_new_link']);
    }

    /** D-261: orders with what the associate's access level does not show taken out on the server (FOB value, commission %). */
    private static function visibleOrders(array $orders): array
    {
        $me = self::me();
        return array_map(static fn (array $o): array => \App\Services\AssociateAccess::filterOrder($me, $o), $orders);
    }

    public static function codeLink(array $a): ?string
    {
        return empty($a['personal_code']) ? null : rtrim((string) Env::get('APP_URL', ''), '/') . '/quotation-details?code=' . rawurlencode((string) $a['personal_code']);
    }

    public function createInvitation(array $params): void
    {
        $a = self::me();
        if (self::refuseWhenSuspended($a, '/associate/clients')) {
            return;
        }
        $company = trim((string) ($_POST['company_name'] ?? ''));
        $country = trim((string) ($_POST['country'] ?? ''));
        if ($a['status'] !== 'active') {
            Flash::set('error', 'You can invite clients once your account is active.');
        } elseif (mb_strlen($company) < 2 || mb_strlen($company) > 255 || $country === '' || mb_strlen($country) > 100) {
            Flash::set('error', 'Enter the company name and its country.');
        } else {
            $inv = AssociateInvitationRepository::createLink((int) $a['id'], $company, $country);
            $_SESSION['_associate_new_link'] = rtrim((string) Env::get('APP_URL', ''), '/') . '/invite/' . $inv['token'];
            AssociateEventRepository::add((int) $a['id'], 'link_created', "Buyer registration link created for {$company} ({$country}).", null, null, $inv['id'], null, false);
            Flash::set('success', "Buyer registration link for {$company} is ready — copy it below and send it to your buyer.");
        }
        header('Location: /associate/clients');
    }

    public function cancelInvitation(array $params): void
    {
        $a = self::me();
        if (self::refuseWhenSuspended($a, '/associate/clients')) {
            return;
        }
        $inv = AssociateInvitationRepository::find((int) $params['id']);
        if ($inv === null || (int) $inv['associate_id'] !== (int) $a['id']) {
            self::notFound('Invitation not found.');
            return;
        }
        if (!AssociateInvitationRepository::isUsable($inv)) {
            Flash::set('error', 'This invitation can no longer be cancelled.');
        } else {
            AssociateInvitationRepository::setStatus((int) $inv['id'], 'cancelled');
            AssociateEventRepository::add((int) $a['id'], 'link_cancelled', "Buyer registration link for {$inv['company_name']} cancelled.", null, null, (int) $inv['id'], null, false);
            Flash::set('success', 'Invitation cancelled.');
        }
        header('Location: /associate/clients');
    }

    /** The associate may choose the readable part of their code once (part 4.4-A2). */
    /**
     * Owner decision D-254 (A5): the associate changes their personal code like a captcha refresh —
     * "New ending" proposes a code (nothing saved), "Use this code" switches to it. Old codes keep
     * bringing buyers to them. At most N changes a year (setting, default 3).
     */
    public function proposeCode(array $params): void
    {
        $a = self::me();
        if (self::refuseWhenSuspended($a, '/associate/clients')) {
            return;
        }
        $part = AssociateCodeService::readablePart((string) ($_POST['readable'] ?? ''));
        if (strlen($part) < 3) {
            Flash::set('error', 'Use 3 to 12 letters or digits.');
        } else {
            $_SESSION['associate_code_proposal'] = ['associate_id' => (int) $a['id'], 'code' => AssociateCodeService::propose($part), 'readable' => $part];
        }
        header('Location: /associate/clients#code');
    }

    public function chooseCode(array $params): void
    {
        $a = self::me();
        if (self::refuseWhenSuspended($a, '/associate/clients')) {
            return;
        }
        $proposal = $_SESSION['associate_code_proposal'] ?? null;
        $code = is_array($proposal) && (int) ($proposal['associate_id'] ?? 0) === (int) $a['id'] ? (string) $proposal['code'] : '';
        $limit = AssociateCodeService::changesPerYear();
        if ($code === '' || ($_POST['code'] ?? '') !== $code) {
            Flash::set('error', 'Press "New ending" to get a code first, then "Use this code".');
        } elseif (!empty($a['personal_code']) && AssociateCodeService::changesInLastYear((int) $a['id']) >= $limit) {
            Flash::set('error', "You can change your code {$limit} times a year — you have used them. Ask your relationship manager if it must change.");
        } else {
            try {
                $code = AssociateCodeService::issue((int) $a['id'], (string) $proposal['readable'], false, null, AssociateCodeService::isValid($code) ? $code : null);
            } catch (\PDOException $e) {
                // Someone took this code in the meantime (unique): propose again.
                unset($_SESSION['associate_code_proposal']);
                Flash::set('error', 'That code was just taken — press "New ending" for another one.');
                header('Location: /associate/clients#code');
                return;
            }
            unset($_SESSION['associate_code_proposal']);
            AssociateEventRepository::add((int) $a['id'], 'code_changed', "Personal code set to {$code}" . (!empty($a['personal_code']) ? " (was {$a['personal_code']}; the old code still brings buyers to you)" : '') . '.', null, null, null, null, false);
            AuditLogRepository::log(null, 'ASSOCIATE_CODE_CHOSEN', 'associates', (int) $a['id'], 'personal_code', $a['personal_code'] ?? null, $code);
            Flash::set('success', "Your personal code is now {$code}." . (!empty($a['personal_code']) ? ' Your old code keeps working.' : ''));
        }
        header('Location: /associate/clients#code');
    }

    public function orders(array $params): void
    {
        self::render('orders', ['orders' => self::visibleOrders(AssociatePortalRepository::orders((int) self::me()['id']))]);
    }

    public function order(array $params): void
    {
        $id = (int) self::me()['id'];
        $rows = AssociatePortalRepository::orders($id, (int) $params['id']);
        if ($rows === []) {
            self::notFound('Order not found.');
            return;
        }
        self::render('order', ['o' => self::visibleOrders([$rows[0]])[0], 'events' => array_values(array_filter(AssociateEventRepository::forAssociate($id), static fn (array $e): bool => (int) $e['order_id'] === (int) $rows[0]['id']))]);
    }

    public function commission(array $params): void
    {
        $id = (int) self::me()['id'];
        // D-263: the portal shows only the last N months of commission (plus anything still unpaid).
        $months = \App\Services\AssociateCommission::portalMonths();
        $payouts = AssociatePortalRepository::portalPayouts($id, $months);
        if (($_GET['format'] ?? '') === 'csv') {
            // QA Round 8, R8-N03: through the shared CSV writer, so a cell like =HYPERLINK(...) is plain text in Excel.
            // D-264: the order currency for every associate; rupees (fixed amount, TDS, paid) only for an associate in India.
            if (\App\Services\AssociatePayoutService::isIndia(self::me())) {
                \App\Helpers\Csv::send('commission-statement.csv',
                    ['Incentive month', 'Order', 'Client', 'Commission (order currency)', 'Currency', 'You are paid (INR, fixed)', 'Fixed at (INR per unit)', 'Fixed on', 'Status', 'Paid on', 'Paid INR', 'TDS INR', 'Reference', 'Note'],
                    array_map(static fn (array $p): array => [$p['incentive_month'], $p['order_reference'], $p['company_legal_name'], $p['amount_fc'], $p['currency_code'], $p['amount_inr'], $p['fixed_rate'], $p['fixed_on'], $p['status'], $p['paid_on'], $p['paid_inr'], $p['tds_inr'], $p['paid_reference'] ?? '', $p['note'] ?? $p['hold_reason'] ?? ''], $payouts));
                return;
            }
            \App\Helpers\Csv::send('commission-statement.csv',
                ['Incentive month', 'Order', 'Client', 'Commission', 'Currency', 'Status', 'Paid on', 'Reference', 'Note'],
                array_map(static fn (array $p): array => [$p['incentive_month'], $p['order_reference'], $p['company_legal_name'], $p['amount_fc'], $p['currency_code'], $p['status'], $p['paid_on'], $p['paid_reference'] ?? '', $p['note'] ?? $p['hold_reason'] ?? ''], $payouts));
            return;
        }
        self::render('commission', ['payouts' => $payouts, 'totals' => AssociatePortalRepository::totals($id, $months), 'print' => ($_GET['print'] ?? '') === '1',
            'documents' => AssociateDocumentRepository::forAssociate($id)]);
    }

    public function uploadInvoice(array $params): void
    {
        $a = self::me();
        $payout = IncentiveRepository::findPayout((int) $params['id']);
        if ($payout === null || $payout['payee_kind'] !== 'associate' || (int) $payout['associate_id'] !== (int) $a['id']) {
            self::notFound('Payout not found.');
            return;
        }
        $no = trim((string) ($_POST['invoice_no'] ?? ''));
        if ($no === '' || mb_strlen($no) > 100) {
            Flash::set('error', 'Enter your invoice number.');
            header('Location: /associate/commission');
            return;
        }
        try {
            $fileId = FileUploadService::handleUpload('invoice_file', 'associate_document', 'associates/documents', null, null, null, null, (string) $a['name'], 'Associate commission invoice');
        } catch (\Throwable $e) {
            Flash::setErrorFromException($e);
            header('Location: /associate/commission');
            return;
        }
        AssociateDocumentRepository::add((int) $a['id'], 'invoice', $fileId, (int) $payout['id'], $no, true, null);
        AssociateEventRepository::add((int) $a['id'], 'invoice_uploaded', "Invoice {$no} uploaded for the commission on order " . $payout['order_id'] . '.', null, (int) $payout['order_id'], null, null, false);
        AuditLogRepository::log(null, 'ASSOCIATE_INVOICE_UPLOADED', 'incentive_payouts', (int) $payout['id'], null, null, $no);
        Flash::set('success', "Invoice {$no} received.");
        header('Location: /associate/commission');
    }

    /** The registration certificate (guarantee 5): a printable page, kept by the associate. */
    public function certificate(array $params): void
    {
        $a = self::me();
        $client = null;
        foreach (AssociateRepository::clients((int) $a['id']) as $c) {
            if ((int) $c['id'] === (int) $params['id']) {
                $client = $c;
            }
        }
        if ($client === null) {
            self::notFound('Client not found.');
            return;
        }
        $events = AssociateEventRepository::forAssociate((int) $a['id'], (int) $client['id']);
        View::render('associate_portal/certificate', ['a' => $a, 'c' => $client, 'events' => $events], null);
    }

    public function profile(array $params): void
    {
        $id = (int) self::me()['id'];
        $me = self::me();
        [$level] = \App\Services\AssociateCommission::levelForAssociate($me, null);
        $documents = AssociateDocumentRepository::forAssociate($id);
        self::render('profile', ['banks' => AssociateBankRepository::forAssociate($id), 'documents' => $documents, 'kyc' => \App\Services\AssociateKyc::checklist($me, $documents),
            'codes' => AssociateCodeService::codes($id),
            // D-263: the rate only (no level name, no ladder wording), and only with "Show commission %".
            'commissionNow' => \App\Services\AssociateAccess::allows($me, 'commission_pct')
                ? (($me['commission_mode'] ?? 'ladder') === 'fixed' ? 'Your commission: ' : 'Your commission on your next order: ') . \App\Services\AssociateCommission::rateLabel($level)
                : 'Your commission is as set out in your agreement.',
            'commissionSettings' => \App\Services\AssociateCommission::settings()]);
    }

    /** D-255 (5 Nov 2026): the P3 price list is replaced by the catalogue and price requests. */
    public function prices(array $params): void
    {
        header('Location: /associate/catalogue');
    }

    /**
     * Owner decision D-255 (5 Nov 2026): the catalogue — products the associate may
     * price, with no prices, supplier names or quarry locations; "Get price" on each.
     */
    public function catalogue(array $params): void
    {
        $me = self::me();
        self::render('catalogue', [
            'products' => \App\Services\PriceRequestService::catalogue($me),
            'left' => \App\Services\PriceRequestService::leftToday($me),
            'limit' => \App\Services\PriceRequestService::dailyLimit($me),
            'requests' => array_map(static function (array $r): array { unset($r['figures']); return $r; }, \App\Services\PriceRequestService::forAssociate((int) $me['id'], 20)), // never the full figures (cost) — D-255
        ]);
    }

    public function getPriceForm(array $params): void
    {
        $me = self::me();
        $product = \App\Repositories\ProductRepository::find((int) ($params['id'] ?? 0));
        if ($product === null || !\App\Services\PriceRequestService::mayPrice($me, $product)) {
            Flash::set('error', 'That product is not in your catalogue.');
            header('Location: /associate/catalogue');
            return;
        }
        self::render('get_price', [
            'product' => $product,
            'specs' => \App\Services\ProductSpecs::offered($product),
            'left' => \App\Services\PriceRequestService::leftToday($me),
        ]);
    }

    public function requestPrice(array $params): void
    {
        $me = self::me();
        $productId = (int) ($params['id'] ?? 0);
        if (self::refuseWhenSuspended($me, "/associate/catalogue/{$productId}")) {
            return;
        }
        $product = \App\Repositories\ProductRepository::find($productId);
        if ($product === null) {
            Flash::set('error', 'That product is not in your catalogue.');
            header('Location: /associate/catalogue');
            return;
        }
        $selection = [];
        foreach ((array) ($_POST['spec'] ?? []) as $specId => $optionId) {
            if (filter_var($specId, FILTER_VALIDATE_INT) !== false && filter_var($optionId, FILTER_VALIDATE_INT) !== false) {
                $selection[(int) $specId] = (int) $optionId;
            }
        }
        [$id, $error] = \App\Services\PriceRequestService::create($me, $product, $selection, !empty($_POST['other']), isset($_POST['note']) ? (string) $_POST['note'] : null);
        if ($error !== null) {
            Flash::set('error', $error);
            Flash::setOld($_POST);
            header("Location: /associate/catalogue/{$productId}");
            return;
        }
        header("Location: /associate/price-requests/{$id}");
    }

    public function priceRequest(array $params): void
    {
        $me = self::me();
        $r = \App\Services\PriceRequestService::find((int) ($params['id'] ?? 0));
        if ($r === null || (int) $r['associate_id'] !== (int) $me['id']) {
            http_response_code(404);
            Flash::set('error', 'That price request was not found.');
            header('Location: /associate/catalogue');
            return;
        }
        unset($r['figures']); // never the full figures (cost) — D-255
        self::render('price_request', [
            'r' => $r,
            'product' => \App\Repositories\ProductRepository::find((int) $r['product_id']),
            'status' => \App\Services\PriceRequestService::displayStatus($r),
            'breakdown' => $r['breakdown'] !== null ? (json_decode((string) $r['breakdown'], true) ?: []) : [],
            'left' => \App\Services\PriceRequestService::leftToday($me),
        ]);
    }

    public function saveProfile(array $params): void
    {
        $a = self::me();
        if (self::refuseWhenSuspended($a, '/associate/profile')) {
            return;
        }
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $pan = strtoupper(trim((string) ($_POST['pan'] ?? '')));
        $gstin = strtoupper(trim((string) ($_POST['gstin'] ?? '')));
        $error = match (true) {
            mb_strlen($phone) > 40 || mb_strlen($address) > 1000 => 'Phone or address is too long.',
            $pan !== '' && $a['based_in'] === 'india' && !preg_match('/^[A-Z]{5}\d{4}[A-Z]$/', $pan) => 'Enter the PAN as on the card (e.g. ABCDE1234F).',
            $gstin !== '' && !preg_match('/^\d{2}[A-Z0-9]{13}$/', $gstin) => 'Enter the 15-character GSTIN, or leave it empty.',
            default => null,
        };
        if ($error !== null) {
            Flash::set('error', $error);
        } else {
            AssociateRepository::setFields((int) $a['id'], ['phone' => $phone ?: null, 'address' => $address ?: null, 'pan' => $pan ?: null, 'gstin' => $gstin ?: null]);
            AuditLogRepository::log(null, 'ASSOCIATE_PROFILE_UPDATED', 'associates', (int) $a['id']);
            Flash::set('success', 'Profile saved.');
        }
        header('Location: /associate/profile');
    }

    public function uploadDocument(array $params): void
    {
        $a = self::me();
        if (self::refuseWhenSuspended($a, '/associate/profile')) {
            return;
        }
        $kind = (string) ($_POST['kind'] ?? '');
        if (!in_array($kind, AssociateDocumentRepository::ASSOCIATE_KINDS, true) || $kind === 'invoice') {
            Flash::set('error', 'Choose what the document is.');
            header('Location: /associate/profile');
            return;
        }
        // Owner decision D-251 (A4): the document's number / reference, checked per kind (Aadhaar: last 4 digits only).
        [$reference, $refError] = \App\Services\AssociateKyc::normaliseReference($kind, (string) ($_POST['reference'] ?? ''));
        if ($refError !== null) {
            Flash::set('error', $refError);
            header('Location: /associate/profile');
            return;
        }
        try {
            $fileId = FileUploadService::handleUpload('document_file', 'associate_document', 'associates/documents', null, null, null, null, (string) $a['name'], AssociateDocumentRepository::KINDS[$kind]);
        } catch (\Throwable $e) {
            Flash::setErrorFromException($e);
            header('Location: /associate/profile');
            return;
        }
        AssociateDocumentRepository::add((int) $a['id'], $kind, $fileId, null, $reference, true, null);
        AuditLogRepository::log(null, 'ASSOCIATE_DOCUMENT_UPLOADED', 'associates', (int) $a['id'], 'kind', null, $kind);
        \App\Services\AssociateKyc::notifyStaffOfUpload($a, $kind);
        Flash::set('success', AssociateDocumentRepository::KINDS[$kind] . ' received — NexaCrest will check it.');
        header('Location: /associate/profile');
    }

    /** Bank details: a request; the verified account keeps being used until Accounts verify the new one (AS-7). */
    public function requestBank(array $params): void
    {
        $a = self::me();
        if (self::refuseWhenSuspended($a, '/associate/profile')) {
            return;
        }
        $result = AssociateAdminController::validateBank($a, $_POST);
        if (is_string($result)) {
            Flash::set('error', $result);
            header('Location: /associate/profile');
            return;
        }
        try {
            $result['proof_file_id'] = FileUploadService::handleUpload('proof_file', 'associate_document', 'associates/bank', null, null, null, null, (string) $a['name'], 'Bank proof');
        } catch (\Throwable $e) {
            Flash::set('error', 'Attach a cancelled cheque or a bank letter (PDF or image).');
            header('Location: /associate/profile');
            return;
        }
        $id = AssociateBankRepository::request((int) $a['id'], $result, true, null);
        AuditLogRepository::log(null, 'ASSOCIATE_BANK_CHANGE_REQUESTED', 'associate_bank_accounts', $id, null, null, 'by associate');
        AssociateService::notify($a, 'bank_changed', "A change of your bank details (to {$result['bank_name']} ••••" . substr((string) $result['account_number'], -4) . ') was received on ' . date('j M Y') . '. It is used once NexaCrest has checked it. '
            . AssociateService::bankPaymentsSentence((int) $a['id']) . ' If you did not ask for this, contact NexaCrest at once.');
        // D-281: the relationship manager and Accounts & Finance's verifiers.
        \App\Repositories\NotificationRepository::notifyRouted(\App\Repositories\PermissionRepository::usersWithPermission('manage_associate_bank'), \App\Services\StaffRouting::forAssociate($a, true),
            'associate_bank_change', null, "Associate {$a['name']} asked to change their bank details — two people must verify it.");
        Flash::set('success', 'Bank change received. It is used once NexaCrest has verified it. ' . AssociateService::bankPaymentsSentence((int) $a['id']));
        header('Location: /associate/profile');
    }

    public function downloadDocument(array $params): void
    {
        $a = self::me();
        $doc = AssociateDocumentRepository::find((int) $params['id']);
        if ($doc === null || (int) $doc['associate_id'] !== (int) $a['id']) {
            http_response_code(404);
            echo 'Document not found.';
            return;
        }
        // D-261: TDS paperwork (Form 16A / 15CA / 15CB) only with "TDS certificates".
        if (in_array((string) $doc['kind'], \App\Services\AssociateAccess::TDS_KINDS, true) && !\App\Services\AssociateAccess::allows($a, 'tds_certificates')) {
            \App\Middleware\AssociateAuth::notAvailable($a);
            return;
        }
        AssociateAdminController::sendFile((string) $doc['server_path'], (string) $doc['original_filename'], (string) ($doc['mime_type'] ?? 'application/octet-stream'));
    }

    public function downloadAgreement(array $params): void
    {
        $a = self::me();
        $file = !empty($a['agreement_file_id']) ? \App\Repositories\FileStoreRepository::find((int) $a['agreement_file_id']) : null;
        if ($file === null) {
            http_response_code(404);
            echo 'No signed agreement is on file yet.';
            return;
        }
        AuditLogRepository::log(null, 'ASSOCIATE_AGREEMENT_DOWNLOADED_BY_ASSOCIATE', 'associates', (int) $a['id']);
        AssociateAdminController::sendFile((string) $file['server_path'], (string) $file['original_filename'], 'application/pdf');
    }

    public function messages(array $params): void
    {
        $a = self::me();
        $rm = !empty($a['relationship_manager_id']) ? \App\Repositories\UserRepository::findById((int) $a['relationship_manager_id']) : null;
        self::render('messages', ['messages' => AssociateEventRepository::messages((int) $a['id']), 'rm' => $rm]);
    }

    public function sendMessage(array $params): void
    {
        $a = self::me();
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($body === '' || mb_strlen($body) > 4000) {
            Flash::set('error', 'Write your message (up to 4,000 characters).');
        } else {
            AssociateEventRepository::addMessage((int) $a['id'], true, null, $body);
            // D-281: the relationship manager (without one: the associate team, else the MD).
            $to = \App\Services\StaffRouting::forAssociate($a);
            foreach ($to as $uid) {
                \App\Repositories\NotificationRepository::create($uid, null, 'associate_message', null, "Message from associate {$a['name']}.");
            }
            Flash::set('success', 'Message sent.');
        }
        header('Location: /associate/messages');
    }
}
