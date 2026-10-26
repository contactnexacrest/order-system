<?php

declare(strict_types=1);

/**
 * One-off (re-runnable) tidy-up: moves every order's existing files into
 * the step-named folders the order page uses (App\Helpers\OrderStorage —
 * 01-quotation/, 04-buyer-po/, 10-packing-and-bl/, ...) and updates
 * file_store.server_path to match. New files already go to the right
 * folder; this brings older ones in line. See OrderFileReorganizer for the
 * rules.
 *
 * Usage:
 *   php /path/to/app/cron/reorganize_order_files.php           # dry run — lists every planned move, changes nothing
 *   php /path/to/app/cron/reorganize_order_files.php --apply   # performs the moves
 *
 * Take a backup of the storage folder and the database first. Safe to run
 * again: files already in place are skipped.
 */

require __DIR__ . '/../bootstrap.php';

use App\Services\OrderFileReorganizer;

$apply = in_array('--apply', $argv, true);
$plan = OrderFileReorganizer::plan();

$moves = array_values(array_filter($plan, static fn (array $p): bool => $p['to'] !== null));
$left = array_values(array_filter($plan, static fn (array $p): bool => $p['to'] === null && $p['note'] !== 'already in place'));

foreach ($moves as $p) {
    echo sprintf("move  #%d  %s\n      -> %s   (%s)\n", $p['file_id'], $p['from'], $p['to'], $p['note']);
}
foreach ($left as $p) {
    echo sprintf("leave #%d  %s   (%s)\n", $p['file_id'], $p['from'], $p['note']);
}
echo sprintf("\n%d order file(s): %d to move, %d already in place, %d left as they are.\n",
    count($plan), count($moves), count($plan) - count($moves) - count($left), count($left));

if (!$apply) {
    echo "Dry run — nothing changed. Run again with --apply to move the files.\n";
    exit(0);
}

$result = OrderFileReorganizer::apply($plan);
foreach ($result['errors'] as $error) {
    echo "! {$error}\n";
}
echo sprintf("Done: %d moved, %d missing on disk, %d failed.\n", $result['moved'], $result['missing'], $result['failed']);
exit($result['failed'] > 0 ? 1 : 0);
