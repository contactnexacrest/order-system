<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AssetRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\OrderAnnexureRepository;
use App\Repositories\OrderCrateRepository;
use App\Repositories\OrderFreightRepository;
use App\Repositories\OrderPackingRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderProductionRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderShippingRepository;
use App\Repositories\OrderSupplierPoRepository;

/**
 * Pulls every field a QT/PI/OC template needs from the DB and assembles a
 * single flat array to hand to Twig. This is the one place that reads
 * company_settings/payment_presets/order data for document rendering —
 * per ARCHITECTURE.md section 4: "a code reviewer can grep templates/ for
 * anything that looks like a literal business value and it should return
 * nothing outside of DB seed data." Templates only ever see variables from
 * here, never a DB call of their own.
 */
final class DocumentDataAssembler
{
    public static function assemble(int $orderId): array
    {
        $order = OrderRepository::find($orderId);
        if (!$order) {
            throw new \RuntimeException("Order {$orderId} not found");
        }
        $products = OrderProductRepository::forOrder($orderId);
        $payment = OrderPaymentStatusRepository::find($orderId);

        $company = self::companyBlock();
        $assets = self::assetsBlock();

        $fobValue = OrderProductRepository::totalFobValue($orderId);
        $advancePct = (float) $order['advance_pct'];
        $balancePct = (float) $order['balance_pct'];
        $advanceAmount = round($fobValue * $advancePct / 100, 2);
        $balanceAmount = round($fobValue - $advanceAmount, 2);

        $isFob = strtoupper($order['incoterm_code']) === 'FOB';
        $portOfDischarge = $order['port_of_discharge_name'] ?? $order['port_of_discharge_text'] ?? 'TBC';

        // Cross-document references: PI cites the QT ref, OC cites the PI
        // ref. Looked up from documents already generated for this order —
        // null (not an error) until that earlier-stage document exists.
        $qtDoc = DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'QT');
        $piDoc = DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'PI');
        $ciDoc = DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'CI');

        return [
            'company' => $company,
            'assets' => $assets,
            'order' => [
                'buyer_inquiry_ref'    => $order['buyer_inquiry_ref'],
                'incoterm_code'        => $order['incoterm_code'],
                // Incoterms® 2020: FOB names the port of LOADING; CFR/CIF name the
                // port of DISCHARGE — real bug this fixes (found via Task #17's
                // first-ever CIF sample order): this always named the loading
                // port, so a CIF document's own header read "CIF Chennai, India"
                // (the seller's own port) instead of the buyer's discharge port.
                'incoterm_label'       => $order['incoterm_code'] . ' ' . ($isFob ? ($order['port_of_loading_name'] ?? 'Chennai, India') : $portOfDischarge) . ' — Incoterms® 2020',
                'is_fob'               => $isFob,
                'port_of_loading'      => $order['port_of_loading_name'] ?? 'Chennai, India',
                'port_of_discharge'    => $portOfDischarge,
                'container_type'       => $order['container_type'] ?? 'TBC',
                'est_lead_time_text'   => $order['est_lead_time_text'] ?? 'TBC',
                'est_shipment_date_text' => $order['est_shipment_date_text'] ?? 'TBC',
                'production_status_text' => $order['production_status_text'] ?? 'Not yet commenced',
                'coo_type'             => $order['coo_type'] ?? 'TBC',
                'include_annexure_a'   => (bool) ($order['include_annexure_a'] ?? false),
                'currency_code'        => $order['currency_code'],
                'buyers_po_ref'        => $order['buyers_po_ref'] ?? 'NIL',
                'quotation_date'       => self::formatDate($order['quotation_date']),
                'quotation_valid_until' => self::formatDate($order['quotation_valid_until']),
                'pi_date'              => self::formatDate($order['pi_date']),
                'pi_valid_until'       => self::formatDate($order['pi_valid_until']),
                'quotation_ref'        => $qtDoc['document_reference'] ?? null,
                'pi_ref'               => $piDoc['document_reference'] ?? null,
                'ci_ref'               => $ciDoc['document_reference'] ?? null,
                'ci_date'              => $ciDoc ? self::formatDate($ciDoc['generated_at'] ?? null) : null,
                'special_requirements' => $order['special_requirements'],
                'estimated_total_cbm'      => $order['estimated_total_cbm'],
                'estimated_gross_weight_kg' => $order['estimated_gross_weight_kg'],
                'estimated_net_weight_kg'  => $order['estimated_net_weight_kg'],
                'estimated_package_count'  => $order['estimated_package_count'],
                'estimated_package_type'   => $order['estimated_package_type'],
                'indicative_freight_low'   => $order['indicative_freight_low'],
                'indicative_freight_high'  => $order['indicative_freight_high'],
                'indicative_insurance_amount' => $order['indicative_insurance_amount'],
            ],
            'buyer' => [
                'company_legal_name'     => $order['company_legal_name'],
                'billing_address'        => $order['billing_address'],
                'consignee_name'         => $order['consignee_name'],
                'consignee_address'      => $order['consignee_address'],
                'vat_eori_tax_no'        => $order['vat_eori_tax_no'],
                'contact_person'         => $order['contact_person'],
                'email'                  => $order['client_email'],
                'phone'                  => $order['client_phone'],
                'country_of_destination' => $order['country_of_destination'],
                'notify_party'           => $order['notify_party'],
            ],
            'products' => array_map(static function (array $p): array {
                return [
                    'description'    => $p['description'],
                    'dimensions'     => $p['dimensions'],
                    'finish'         => $p['finish'],
                    'hs_code'        => $p['hs_code'],
                    'quantity'       => $p['quantity_is_tbc'] ? 'TBC' : self::formatNumber($p['quantity']),
                    'unit'           => $p['unit'],
                    'unit_price'     => $p['unit_price'] !== null ? self::formatMoney($p['unit_price']) : 'TBC',
                    'amount'         => $p['fob_value'] !== null ? self::formatMoney($p['fob_value']) : 'TBC',
                ];
            }, $products),
            'financial' => [
                'fob_value'        => self::formatMoney($fobValue),
                'fob_value_raw'    => $fobValue,
                'advance_pct'      => rtrim(rtrim(number_format($advancePct, 2), '0'), '.'),
                'advance_amount'   => self::formatMoney($advanceAmount),
                'advance_trigger_text' => $order['advance_trigger_text'],
                'balance_pct'      => rtrim(rtrim(number_format($balancePct, 2), '0'), '.'),
                'balance_amount'   => self::formatMoney($balanceAmount),
                'balance_trigger_option' => $order['balance_trigger_option'],
                'balance_days'     => $order['balance_days'],
                'balance_terms_text' => self::balanceTriggerSentence($order['balance_trigger_option'] ?? null, isset($order['balance_days']) ? (int) $order['balance_days'] : null),
                'total_value'      => self::formatMoney($fobValue), // freight/insurance are indicative-only, not summed into the binding total for FOB
                'preset_name'      => $order['preset_name'],
            ],
            'payment_status' => $payment ? [
                'advance_amount'                  => $payment['advance_amount'] !== null ? self::formatMoney($payment['advance_amount']) : null,
                'advance_remittance_received_at'  => self::formatDate($payment['advance_remittance_received_at']),
                'advance_cleared_at'              => self::formatDate($payment['advance_cleared_at']),
                'freight_amount'                  => $payment['freight_amount'] !== null ? self::formatMoney($payment['freight_amount']) : null,
                'freight_remittance_received_at'  => self::formatDate($payment['freight_remittance_received_at']),
                'freight_cleared_at'              => self::formatDate($payment['freight_cleared_at']),
                'balance_amount'                  => $payment['balance_amount'] !== null ? self::formatMoney($payment['balance_amount']) : self::formatMoney($balanceAmount),
                'balance_remittance_received_at'  => self::formatDate($payment['balance_remittance_received_at']),
                'balance_cleared_at'              => self::formatDate($payment['balance_cleared_at']),
                'balance_due_date'                => self::formatDate($payment['balance_due_date']),
            ] : null,
            'supplier_po' => self::supplierPoBlock($orderId),
            'freight'     => self::freightBlock($orderId),
            'packing'     => self::packingBlock($orderId),
            'crates'      => self::cratesBlock($orderId),
            'shipping'    => self::shippingBlock($orderId),
            'production'  => self::productionBlock($orderId),
            'annexure_products' => self::annexureProductsBlock($orderId),
            'bl'          => self::blBlock($company['legal_name']),
        ];
    }

    /**
     * The BL Instruction Sheet's two hard business rules (BL type,
     * consignee wording) — previously hardcoded directly in the Twig
     * template, now governed, protected company_settings so a legitimate
     * one-off exception goes through the existing unlock-request +
     * mandatory-reason + audit-log workflow instead of a source-code edit
     * that leaves no trail at all.
     */
    private static function blBlock(string $companyLegalName): array
    {
        $consigneeTemplate = CompanySettingsRepository::get('bl_consignee_instruction') ?? 'TO ORDER OF {company}';
        return [
            'type_instruction' => CompanySettingsRepository::get('bl_type_instruction'),
            'consignee_instruction' => str_replace('{company}', strtoupper($companyLegalName), $consigneeTemplate),
        ];
    }

    /**
     * Annexure A's own product list, images embedded as base64 data URIs
     * for the same DOMPDF isRemoteEnabled=false reason assetsBlock() embeds
     * the logo/signature/seal — a generated PDF can never depend on a live
     * HTTP fetch of anything, including this app's own authenticated
     * routes.
     */
    private static function annexureProductsBlock(int $orderId): array
    {
        $products = OrderAnnexureRepository::forOrder($orderId);
        return array_map(static function (array $p): array {
            return [
                'name' => $p['name'],
                'description' => $p['description'],
                'dimensions' => $p['dimensions'],
                'finish' => $p['finish'],
                'components' => $p['components'],
                'technical_notes' => $p['technical_notes'],
                'images' => array_map(static function (array $img): array {
                    $dataUri = is_file($img['server_path'])
                        ? 'data:' . ($img['mime_type'] ?: 'image/jpeg') . ';base64,' . base64_encode((string) file_get_contents($img['server_path']))
                        : null;
                    return ['data_uri' => $dataUri];
                }, $p['images']),
            ];
        }, $products);
    }

    /**
     * Phase C blocks: Supplier PO, Freight, Packing/Crates, Shipping/BL,
     * and Production tracking. Each is simply null/empty until that stage
     * of the order has actually happened — templates render 'TBC'/'—' for
     * a null block via Twig's null-safe access, exactly like the Phase B
     * fields do for an order that hasn't reached that stage yet.
     */
    private static function supplierPoBlock(int $orderId): ?array
    {
        $row = OrderSupplierPoRepository::findLatestForOrder($orderId);
        if (!$row) {
            return null;
        }
        return [
            'supplier_po_reference' => $row['supplier_po_reference'],
            'supplier_legal_name'   => $row['supplier_legal_name'],
            'supplier_address'      => $row['supplier_address'],
            'supplier_gstin'        => $row['supplier_gstin'],
            'supplier_pan'          => $row['supplier_pan'],
            'supplier_contact_person' => $row['supplier_contact_person'],
            'supplier_phone'        => $row['supplier_phone'],
            'supplier_type'         => $row['supplier_type'],
            'material_stone_type'   => $row['material_stone_type'],
            'grade'                 => $row['grade'],
            'surface_finish'        => $row['surface_finish'],
            'dimensions'            => $row['dimensions'],
            'dimensional_tolerance' => $row['dimensional_tolerance'],
            'quantity'              => $row['quantity'] !== null ? self::formatNumber($row['quantity']) : 'TBC',
            'unit'                  => $row['unit'],
            'colour_reference'      => $row['colour_reference'] ?: 'NIL',
            'special_requirements'  => $row['special_requirements'] ?: 'NIL',
            'unit_price_inr'        => self::formatMoney($row['unit_price_inr']),
            'basic_value_inr'       => self::formatMoney($row['basic_value_inr']),
            'gst_rate_pct'          => $row['gst_rate_pct'],
            'gst_amount_inr'        => self::formatMoney($row['gst_amount_inr']),
            'total_payable_inr'     => self::formatMoney($row['total_payable_inr']),
            'advance_pct'           => $row['advance_pct'],
            'advance_amount_inr'    => self::formatMoney($row['advance_amount_inr']),
            'balance_amount_inr'    => self::formatMoney($row['balance_amount_inr']),
            'delivery_location'     => $row['delivery_location'],
            'required_delivery_date' => self::formatDate($row['required_delivery_date']),
            'packing_requirement'   => $row['packing_requirement'] ?: 'NIL',
            'status'                => $row['status'],
        ];
    }

    private static function freightBlock(int $orderId): ?array
    {
        $row = OrderFreightRepository::find($orderId);
        if (!$row) {
            return null;
        }
        $freightRaw = $row['confirmed_freight_rate'] !== null ? (float) $row['confirmed_freight_rate'] : null;
        $insuranceRaw = $row['insurance_amount'] !== null ? (float) $row['insurance_amount'] : 0.0;
        return [
            'confirmed_freight_rate'    => $freightRaw !== null ? self::formatMoney($freightRaw) : null,
            'insurance_amount'          => $row['insurance_amount'] !== null ? self::formatMoney($row['insurance_amount']) : 'NIL',
            // FDN's "TOTAL AMOUNT DUE" needs one number to wire, not a
            // formula string — computed here (not in Twig) because
            // confirmed_freight_rate/insurance_amount above are already
            // comma-formatted display strings by the time a template sees
            // them.
            'total_freight_and_insurance' => $freightRaw !== null ? self::formatMoney($freightRaw + $insuranceRaw) : null,
            'freight_forwarder_name'    => $row['freight_forwarder_name'],
            'freight_forwarder_contact' => $row['freight_forwarder_contact'],
            'gst_treatment'             => $row['gst_treatment'],
            'freight_cleared_at'        => self::formatDate($row['freight_cleared_at']),
        ];
    }

    private static function packingBlock(int $orderId): ?array
    {
        $row = OrderPackingRepository::find($orderId);
        if (!$row) {
            return null;
        }
        return [
            'actual_quantity_packed'  => $row['actual_quantity_packed'] !== null ? self::formatNumber($row['actual_quantity_packed']) : 'TBC',
            'crate_count'             => $row['crate_count'],
            'total_net_weight_kg'     => $row['total_net_weight_kg'],
            'total_gross_weight_kg'   => $row['total_gross_weight_kg'],
            'total_cbm'               => $row['total_cbm'],
            'packing_date'            => self::formatDate($row['packing_date']),
            'shortfall_pct'           => $row['shortfall_pct'],
        ];
    }

    /** @return array<int, array<string,mixed>> */
    private static function cratesBlock(int $orderId): array
    {
        // Real bug this fixes: order_crates' DECIMAL columns were passed
        // straight through, unlike every other quantity/weight field in
        // this file (see formatNumber() below) — every Packing List ever
        // generated showed "10.00" pcs / "1600.00" kg / "9.2500" m³ in the
        // crate breakdown instead of the trimmed "10" / "1600" / "9.25" the
        // rest of the same document uses. Trims trailing zeros the same
        // way, but returns null (not formatNumber()'s 'TBC') for a genuinely
        // empty cell — the template's own `?: '—'` fallback already handles
        // that, and 'TBC' would be a behavior change for a document type
        // whose crate rows come from a form where these are all required.
        $trim = static fn(?string $v): ?string => $v === null || $v === ''
            ? null
            : rtrim(rtrim(number_format((float) $v, 3), '0'), '.');

        return array_map(static function (array $c) use ($trim): array {
            return [
                'crate_no'            => $c['crate_no'],
                'marks_numbers'       => $c['marks_numbers'],
                'product_description' => $c['product_description'],
                'dimensions_lwh_cm'   => $c['dimensions_lwh_cm'],
                'pcs'                 => $trim($c['pcs']),
                'net_weight_kg'       => $trim($c['net_weight_kg']),
                'gross_weight_kg'     => $trim($c['gross_weight_kg']),
                'cbm'                 => $trim($c['cbm']),
                'hs_code'             => $c['hs_code'],
            ];
        }, OrderCrateRepository::forOrder($orderId));
    }

    private static function shippingBlock(int $orderId): ?array
    {
        $row = OrderShippingRepository::find($orderId);
        if (!$row) {
            return null;
        }
        return [
            'shipping_line'     => $row['shipping_line'],
            'vessel_name'       => $row['vessel_name'],
            'voyage_number'     => $row['voyage_number'],
            'etd'               => self::formatDate($row['etd']),
            'eta'               => self::formatDate($row['eta']),
            'container_type'    => $row['container_type'],
            'container_no'      => $row['container_no'],
            'seal_no'           => $row['seal_no'],
            'bl_number'         => $row['bl_number'],
            'bl_date'           => self::formatDate($row['bl_date']),
            'bl_originals_received_count' => $row['bl_originals_received_count'],
        ];
    }

    private static function productionBlock(int $orderId): ?array
    {
        $row = OrderProductionRepository::find($orderId);
        if (!$row) {
            return null;
        }
        return [
            'production_start_date'    => self::formatDate($row['production_start_date']),
            'expected_completion_date' => self::formatDate($row['expected_completion_date']),
        ];
    }

    public static function companyBlock(): array
    {
        $keys = [
            'legal_name', 'registered_office', 'corporate_office', 'gstin', 'iec_pan',
            'md_name', 'md_title', 'phone', 'email',
            'bank_name', 'bank_branch', 'bank_account_no', 'swift_bic', 'ifsc', 'bank_address',
            'lut_number', 'lut_valid_fy',
            'rcmc_number', 'rcmc_valid_until',
            'rbi_purpose_code_advance', 'rbi_purpose_code_balance', 'rbi_purpose_code_freight',
            'quantity_shortfall_tolerance_pct', 'non_usd_price_buffer_pct',
        ];
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = CompanySettingsRepository::get($key);
        }
        return $out;
    }

    /**
     * Rebuilds the company/bank/LUT block from a `documents` row's own
     * company_snapshot_json instead of re-reading company_settings live.
     * Used anywhere an already-generated document is re-rendered (the
     * DRAFT->FINAL watermark swap in finalizeApproval() being the main
     * case) — same reasoning as signatoryFromSnapshot() above: a bank
     * account switch or LUT renewal made during the review window must
     * never change what a buyer-facing FINAL PDF shows versus the DRAFT a
     * reviewer actually approved. Falls back to a live companyBlock() read
     * for rows generated before this column existed (company_snapshot_json
     * NULL), same fallback convention as the signatory snapshot.
     *
     * @param array<string,mixed> $document a row from the documents table
     */
    public static function companyFromSnapshot(array $document): array
    {
        if (empty($document['company_snapshot_json'])) {
            return self::companyBlock();
        }
        $decoded = json_decode((string) $document['company_snapshot_json'], true);
        return is_array($decoded) ? $decoded : self::companyBlock();
    }

    /**
     * DOMPDF renders with isRemoteEnabled=false (no fetching URLs, including
     * the app's own authenticated /company-assets/preview route) — so
     * images must be embedded directly as base64 data URIs, read straight
     * off disk via AssetRepository's stored server_path.
     */
    public static function assetsBlock(): array
    {
        $out = [];
        foreach (['logo', 'signature', 'seal', 'watermark'] as $type) {
            $asset = AssetRepository::findActiveByType($type);
            $out[$type . '_data_uri'] = ($asset && is_file($asset['server_path']))
                ? 'data:' . ($asset['mime_type'] ?: 'image/png') . ';base64,' . base64_encode((string) file_get_contents($asset['server_path']))
                : null;
        }
        return $out;
    }

    /**
     * Three-layer signatory resolution (highest precedence first):
     *   1. $overrideUserId — a one-off choice made at generation time.
     *   2. document_type_signatories — a per-document-type default (e.g.
     *      Payment Terms Amendment always uses the Director designation seal).
     *   3. company_default_signatory — the global fallback.
     * The chosen signatory's name/designation/signature/seal are returned
     * ready to render AND ready to snapshot onto the documents row, so a
     * later change to any default never alters how a past document reads.
     */
    public static function signatoryBlock(int $documentTypeId, ?int $overrideUserId = null): array
    {
        $pdo = \App\Config\Database::connection();

        $userId = $overrideUserId;
        $useDesignationSeal = false;

        if ($userId === null) {
            $stmt = $pdo->prepare(
                'SELECT user_id, use_designation_seal FROM document_type_signatories WHERE document_type_id = :dt'
            );
            $stmt->execute(['dt' => $documentTypeId]);
            $row = $stmt->fetch();
            if ($row) {
                $userId = (int) $row['user_id'];
                $useDesignationSeal = (bool) $row['use_designation_seal'];
            }
        }

        if ($userId === null) {
            $row = $pdo->query('SELECT user_id FROM company_default_signatory WHERE id = 1')->fetch();
            $userId = $row ? (int) $row['user_id'] : null;
        }

        if ($userId === null) {
            // No signatory configured at all — fall back to the legacy
            // company_settings md_name/md_title + the single global
            // `assets` signature/seal rows, so a fresh install with no
            // signatory set up yet still renders a usable document.
            $company = self::companyBlock();
            $assets = self::assetsBlock();
            return [
                'user_id' => null,
                'name' => $company['md_name'],
                'designation' => $company['md_title'],
                'signature_data_uri' => $assets['signature_data_uri'],
                'seal_data_uri' => $assets['seal_data_uri'],
                'signature_asset_id' => null,
                'seal_asset_id' => null,
                'used_designation_seal' => false,
            ];
        }

        $stmt = $pdo->prepare(
            'SELECT u.id, u.name, d.title AS designation
             FROM users u LEFT JOIN designations d ON d.id = u.designation_id
             WHERE u.id = :id AND u.is_signatory_eligible = 1'
        );
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        if (!$user) {
            throw new \RuntimeException("Resolved signatory user {$userId} is not signatory-eligible or does not exist");
        }

        $signatureAsset = self::userSignatureAsset($userId, 'signature');
        $signatureDataUri = self::assetDataUri($signatureAsset);

        $sealAsset = null;
        $sealDataUri = null;
        if ($useDesignationSeal) {
            $sealAsset = self::userSignatureAsset($userId, 'designation_seal');
            $sealDataUri = self::assetDataUri($sealAsset);
        } else {
            $companySeal = AssetRepository::findActiveByType('seal');
            $sealAsset = $companySeal;
            $sealDataUri = self::assetDataUri($companySeal, true);
        }

        return [
            'user_id' => (int) $user['id'],
            'name' => $user['name'],
            'designation' => $user['designation'] ?? '',
            'signature_data_uri' => $signatureDataUri,
            'seal_data_uri' => $sealDataUri,
            'signature_asset_id' => $signatureAsset['id'] ?? null,
            'seal_asset_id' => $sealAsset['id'] ?? null,
            'used_designation_seal' => $useDesignationSeal,
        ];
    }

    /**
     * Rebuilds a render-ready signatory block from a `documents` row's own
     * snapshot columns, instead of re-resolving current defaults. Used
     * anywhere an already-generated document is re-rendered (the
     * DRAFT->FINAL watermark swap in finalizeApproval() being the main
     * case) — a document must keep showing the same signatory it was
     * originally generated with, even if the global/document-type default
     * has since changed, and even if that document predates this feature
     * entirely (signatory_user_id NULL — falls back to the legacy
     * md_name/md_title + single global assets rows, same as a fresh
     * install with no signatory configured).
     *
     * @param array<string,mixed> $document a row from the documents table
     */
    public static function signatoryFromSnapshot(array $document): array
    {
        if ($document['signatory_user_id'] === null) {
            $company = self::companyBlock();
            $assets = self::assetsBlock();
            return [
                'user_id' => null,
                'name' => $document['signatory_name_snapshot'] ?? $company['md_name'],
                'designation' => $document['signatory_designation_snapshot'] ?? $company['md_title'],
                'signature_data_uri' => $assets['signature_data_uri'],
                'seal_data_uri' => $assets['seal_data_uri'],
            ];
        }

        $pdo = \App\Config\Database::connection();
        $signatureDataUri = null;
        if (!empty($document['signature_asset_id_snapshot'])) {
            $stmt = $pdo->prepare('SELECT * FROM user_signature_assets WHERE id = :id');
            $stmt->execute(['id' => $document['signature_asset_id_snapshot']]);
            $signatureDataUri = self::assetDataUri($stmt->fetch() ?: null);
        }

        $sealDataUri = null;
        if (!empty($document['seal_asset_id_snapshot'])) {
            $sealTable = !empty($document['used_designation_seal']) ? 'user_signature_assets' : 'assets';
            $stmt = $pdo->prepare("SELECT * FROM {$sealTable} WHERE id = :id");
            $stmt->execute(['id' => $document['seal_asset_id_snapshot']]);
            $sealDataUri = self::assetDataUri($stmt->fetch() ?: null);
        }

        return [
            'user_id' => (int) $document['signatory_user_id'],
            'name' => $document['signatory_name_snapshot'],
            'designation' => $document['signatory_designation_snapshot'],
            'signature_data_uri' => $signatureDataUri,
            'seal_data_uri' => $sealDataUri,
        ];
    }

    /** @return array<string,mixed>|null */
    private static function userSignatureAsset(int $userId, string $kind): ?array
    {
        $stmt = \App\Config\Database::connection()->prepare(
            'SELECT * FROM user_signature_assets
             WHERE user_id = :uid AND asset_kind = :kind AND is_active = 1
             ORDER BY is_default_for_kind DESC, id DESC LIMIT 1'
        );
        $stmt->execute(['uid' => $userId, 'kind' => $kind]);
        return $stmt->fetch() ?: null;
    }

    /** @param array<string,mixed>|null $asset */
    private static function assetDataUri(?array $asset, bool $isCompanyAsset = false): ?string
    {
        if (!$asset || !is_file($asset['server_path'])) {
            return null;
        }
        return 'data:' . ($asset['mime_type'] ?: 'image/png') . ';base64,' . base64_encode((string) file_get_contents($asset['server_path']));
    }

    public static function formatMoney(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return 'TBC';
        }
        return number_format((float) $value, 2);
    }

    /**
     * The one human-readable sentence for a balance_trigger_option +
     * balance_days pair — kept as a single source of truth so the QT/PI/OC
     * Twig templates' inline if/else and any place OUTSIDE that render
     * pipeline (e.g. AmendmentService's frozen snapshot text) say exactly
     * the same thing, rather than one place rendering the sentence and
     * another leaking the raw ENUM code ('A_BEFORE_SHIPMENT') into a
     * buyer- or legally-facing document.
     */
    public static function balanceTriggerSentence(?string $option, ?int $days): string
    {
        $days = $days ?? 0;
        if ($option === 'A_BEFORE_SHIPMENT') {
            return "Payable before shipment — within {$days} working days of receiving shipment readiness confirmation from NexaCrest.";
        }
        return "Payable against scanned copy of Bill of Lading, within {$days} days of BL date.";
    }

    private static function formatNumber(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return 'TBC';
        }
        $float = (float) $value;
        return rtrim(rtrim(number_format($float, 3), '0'), '.');
    }

    public static function formatDate(?string $value): ?string
    {
        if (!$value) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->format('d F Y');
        } catch (\Exception $e) {
            return $value;
        }
    }
}
