<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\LookupRepository;
use App\Repositories\PaymentPresetRepository;
use App\Services\AuthService;

/**
 * Full CRUD for payment_presets — see PaymentPresetRepository's
 * docblock. Gated on manage_payment_presets, same tier as
 * manage_logistics_partners (Admin/MD/ED and Super Admin only).
 */
final class PaymentPresetController
{
    private const TRIGGER_OPTIONS = [
        'A_BEFORE_SHIPMENT' => 'Before Shipment',
        'B_AGAINST_BL'      => 'Against Scanned BL Copy',
    ];

    public function index(array $params): void
    {
        View::render('payment_presets/index', [
            'presets' => PaymentPresetRepository::all(true),
        ], 'layout/base');
    }

    public function createForm(array $params): void
    {
        View::render('payment_presets/create', [
            'currencies' => LookupRepository::currencies(),
            'triggerOptions' => self::TRIGGER_OPTIONS,
        ], 'layout/base');
    }

    public function create(array $params): void
    {
        $data = $this->collectFormData();
        if ($data === null) {
            header('Location: /payment-presets/create');
            return;
        }

        $user = AuthService::currentUser();
        $id = PaymentPresetRepository::create($data, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'PAYMENT_PRESET_ADDED', 'payment_presets', $id, null, null, $data['preset_name']);
        Flash::set('success', "\"{$data['preset_name']}\" added — {$data['advance_pct']}% advance / {$data['balance_pct']}% balance.");
        header('Location: /payment-presets');
    }

    public function editForm(array $params): void
    {
        $id = (int) $params['id'];
        $preset = PaymentPresetRepository::find($id);
        if (!$preset) {
            http_response_code(404);
            echo 'Payment preset not found.';
            return;
        }
        View::render('payment_presets/edit', [
            'preset' => $preset,
            'currencies' => LookupRepository::currencies(),
            'triggerOptions' => self::TRIGGER_OPTIONS,
            'inUse' => PaymentPresetRepository::isInUse($id),
        ], 'layout/base');
    }

    public function update(array $params): void
    {
        $id = (int) $params['id'];
        $preset = PaymentPresetRepository::find($id);
        if (!$preset) {
            http_response_code(404);
            echo 'Payment preset not found.';
            return;
        }
        if ((int) $preset['is_protected'] === 1) {
            Flash::set('error', 'This preset is protected — unlock it via Field Protection before editing.');
            header("Location: /payment-presets/{$id}/edit");
            return;
        }

        $data = $this->collectFormData();
        if ($data === null) {
            header("Location: /payment-presets/{$id}/edit");
            return;
        }

        $user = AuthService::currentUser();
        PaymentPresetRepository::update($id, $data);
        AuditLogRepository::log((int) $user['id'], 'PAYMENT_PRESET_UPDATED', 'payment_presets', $id, 'preset_name', $preset['preset_name'], $data['preset_name']);
        Flash::set('success', "\"{$data['preset_name']}\" updated.");
        header('Location: /payment-presets');
    }

    public function toggleActive(array $params): void
    {
        $id = (int) $params['id'];
        $preset = PaymentPresetRepository::find($id);
        if (!$preset) {
            Flash::set('error', 'Payment preset not found.');
            header('Location: /payment-presets');
            return;
        }

        $user = AuthService::currentUser();
        try {
            PaymentPresetRepository::toggleActive($id);
        } catch (\RuntimeException $e) {
            Flash::set('error', $e->getMessage());
            header('Location: /payment-presets');
            return;
        }
        $nowActive = !((bool) $preset['is_active']);
        AuditLogRepository::log((int) $user['id'], $nowActive ? 'PAYMENT_PRESET_REACTIVATED' : 'PAYMENT_PRESET_DEACTIVATED', 'payment_presets', $id, 'is_active', (string) (int) $preset['is_active'], (string) (int) $nowActive);
        Flash::set('success', "\"{$preset['preset_name']}\" " . ($nowActive ? 'reactivated' : 'deactivated') . '.');
        header('Location: /payment-presets');
    }

    /** @return array<string,mixed>|null null if validation failed (flash already set) */
    private function collectFormData(): ?array
    {
        $presetName = trim((string) ($_POST['preset_name'] ?? ''));
        $advanceTriggerText = trim((string) ($_POST['advance_trigger_text'] ?? ''));
        $balanceTriggerOption = trim((string) ($_POST['balance_trigger_option'] ?? ''));
        $balanceTriggerWording = trim((string) ($_POST['balance_trigger_wording'] ?? ''));
        $advancePct = (float) ($_POST['advance_pct'] ?? 0);
        $balancePct = (float) ($_POST['balance_pct'] ?? 0);
        $balanceDays = (int) ($_POST['balance_days'] ?? 0);
        $currencyId = (int) ($_POST['currency_id'] ?? 0);

        if ($presetName === '') {
            Flash::set('error', 'Preset name is required.');
            return null;
        }
        if ($advanceTriggerText === '') {
            Flash::set('error', 'Advance trigger text is required.');
            return null;
        }
        if (!array_key_exists($balanceTriggerOption, self::TRIGGER_OPTIONS)) {
            Flash::set('error', 'Select a valid balance trigger option.');
            return null;
        }
        if ($advancePct <= 0 || $advancePct >= 100) {
            Flash::set('error', 'Advance % must be between 0 and 100.');
            return null;
        }
        if (abs(($advancePct + $balancePct) - 100.0) > 0.01) {
            Flash::set('error', "Advance % and Balance % must add up to 100 (got {$advancePct}% + {$balancePct}% = " . ($advancePct + $balancePct) . '%).');
            return null;
        }
        if ($balanceDays <= 0) {
            Flash::set('error', 'Balance days must be a positive number.');
            return null;
        }
        if ($currencyId <= 0) {
            Flash::set('error', 'Select a currency.');
            return null;
        }
        if ($balanceTriggerWording !== '' && !str_contains($balanceTriggerWording, '{days}')) {
            Flash::set('error', 'Balance trigger wording must include the {days} token, substituted with Balance Days at render time.');
            return null;
        }

        return [
            'preset_name'             => $presetName,
            'is_default'              => !empty($_POST['is_default']),
            'advance_pct'             => $advancePct,
            'advance_trigger_text'    => $advanceTriggerText,
            'balance_pct'             => $balancePct,
            'balance_trigger_option'  => $balanceTriggerOption,
            'balance_days'            => $balanceDays,
            'balance_trigger_wording' => $balanceTriggerWording ?: null,
            'currency_id'             => $currencyId,
            'requires_md_approval'    => !empty($_POST['requires_md_approval']),
        ];
    }
}
