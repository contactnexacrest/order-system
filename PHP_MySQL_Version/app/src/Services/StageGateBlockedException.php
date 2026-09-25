<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Thrown by DocumentGenerationService::generate() when an order is locked
 * or the target document type's stage hasn't unlocked yet — a distinct
 * type (rather than a plain RuntimeException) so the controller can show
 * its message to the user directly instead of the generic "check the
 * server error log" treatment every other generation failure gets. The
 * message itself is always safe to show: it only ever describes order/
 * stage state, never internal detail.
 */
final class StageGateBlockedException extends \RuntimeException
{
}
