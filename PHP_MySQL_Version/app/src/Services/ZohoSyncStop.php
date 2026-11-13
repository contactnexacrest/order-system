<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Owner decision 2 Nov 2026 (D-239, "Zoho limits yes"): a reason to stop a
 * Zoho Books sync run at once — the daily allowance of API calls for this app
 * is used up, or Zoho answered "too many requests". Every per-record catch in
 * the sync services passes it on (rethrow()), so the run stops instead of
 * spending more calls on the remaining records; they stay waiting for the
 * next sync.
 */
class ZohoSyncStop extends \RuntimeException
{
    /** @var array<string,int>|null QA ZOH-007: the counts of the section the run stopped in, up to the stop */
    public ?array $partial = null;

    /**
     * In a per-record catch: let a stop reason end the whole run. QA ZOH-007:
     * $soFar is what the section had already done, so the run's result still
     * counts it (the last catch the stop passes, the section's own, wins).
     *
     * @param array<string,int>|null $soFar
     */
    public static function rethrow(\Throwable $e, ?array $soFar = null): void
    {
        if ($e instanceof self) {
            if ($soFar !== null) {
                $e->partial = $soFar;
            }
            throw $e;
        }
    }

    /**
     * QA ZOH-007: the section's counts after a stop — the zeros it started with,
     * overlaid with what it had done before the stop.
     *
     * @param array<string,int> $zero
     * @return array<string,int>
     */
    public function partialFor(array $zero): array
    {
        return $this->partial === null ? $zero : array_replace($zero, array_intersect_key($this->partial, $zero));
    }
}
