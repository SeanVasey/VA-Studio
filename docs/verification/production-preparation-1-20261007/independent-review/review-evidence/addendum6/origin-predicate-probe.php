<?php
// Addendum 6 probe: MachinePolicyV1::origin (policy, now also the preflight) vs ProductionCommerceReadiness::httpsOrigin (old preflight).
// Run from the review worktree root: php review-evidence/addendum6/origin-predicate-probe.php
require getcwd().'/vendor/autoload.php';

use App\Domain\Commerce\ProductionPolicy\MachinePolicyV1;
use App\Domain\Commerce\Readiness\ProductionCommerceReadiness;

$label = 'a'.str_repeat('b', 61).'c';           // 63-char DNS label
$host247 = substr(implode('.', array_fill(0, 4, $label)), 0, 247); // https:// (8) + 247 = 255
$host248 = $host247.'d';
$cases = [
    'https://review.invalid', 'https://shop.vasey.audio', 'https://review.invalid:443', 'https://review.invalid:8443',
    'https://review.invalid:1', 'https://review.invalid:65535', 'https://review.invalid:65536', 'https://review.invalid:0',
    'https://review.invalid:', 'HTTPS://review.invalid', 'https://REVIEW.INVALID', 'http://review.invalid',
    'https://review.invalid/', 'https://review.invalid/return', 'https://review.invalid?', 'https://review.invalid#',
    'https://user:pass@review.invalid', 'https://localhost', 'https://LOCALHOST', 'https://localhost:443',
    'https://localhost.', 'https://127.0.0.1', 'https://127.0.0.2', 'https://10.0.0.1', 'https://0.0.0.0',
    'https://2130706433', 'https://[::1]', 'https://[::1]:443', 'https://[2001:db8::1]', 'https://-',
    'https://review.invalid.', 'https://bücher.example', 'https://xn--bcher-kva.example', 'https://a..b',
    'https://review_invalid', 'https://'.$host247, 'https://'.$host248, 'https:review.invalid', 'https:///review.invalid',
    ' https://review.invalid', 'https://review.invalid\\', null, 443,
];
$rows = [];
$diff = 0;
foreach ($cases as $c) {
    $old = ProductionCommerceReadiness::httpsOrigin($c);
    $new = MachinePolicyV1::origin($c);
    $shown = is_string($c) ? (strlen($c) > 60 ? 'https://<'.(strlen($c) - 8).'-char host> (len '.strlen($c).')' : $c) : var_export($c, true);
    $delta = $old === $new ? '' : ($old ? 'httpsOrigin only' : 'policy only');
    $diff += $delta !== '' ? 1 : 0;
    $rows[] = sprintf("| `%s` | %s | %s | %s |", $shown, $old ? 'accept' : 'refuse', $new ? 'accept' : 'refuse', $delta);
}
echo "| Input | httpsOrigin (old preflight) | MachinePolicyV1::origin (policy = new preflight) | Differs |\n| --- | --- | --- | --- |\n";
echo implode("\n", $rows), "\n\ncases=", count($cases), " differing=", $diff, " php=", PHP_VERSION, "\n";
