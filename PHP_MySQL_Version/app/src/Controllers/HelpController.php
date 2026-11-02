<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\View;

/**
 * Owner request (2 Nov 2026): "I need the complete payment screenshots for
 * both advance / balance etc for supplier and logistic partner — my team
 * needs to see this". A static, illustrated guide to the supplier and
 * logistics partner bill + payment forms; any logged-in staff member may
 * read it (same visibility as the SOP). Linked from Steps 7e, 10a and the
 * Logistics Partner Bills card. Node twin: helpController.js.
 */
final class HelpController
{
    public function supplierLogisticsPayments(array $params): void
    {
        View::render('help/supplier_logistics_payments', [], 'layout/base');
    }
}
