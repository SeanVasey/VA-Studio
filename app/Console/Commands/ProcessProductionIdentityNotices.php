<?php

namespace App\Console\Commands;

use App\Domain\Customers\ProductionIdentity\IdentityDatabase;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\WorkIdentityNotice;
use Illuminate\Console\Command;

final class ProcessProductionIdentityNotices extends Command
{
    protected $signature = 'customers:process-identity-notices {--limit=50}';

    protected $description = 'Bounded recovery of retained identity notices; ambiguous submissions remain terminal.';

    public function handle(WorkIdentityNotice $worker): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 100) {
            return self::INVALID;
        }
        $policy = new IdentityPolicy;
        if (! $policy->enabled()) {
            return self::SUCCESS;
        }
        $policy->outsideTransactions();
        $database = new IdentityDatabase;
        $statement = $database->primary->prepare('SELECT n.id FROM '.$database->rows->table('production_identity_notices').' n JOIN '.$database->rows->table('production_identity_challenges').' c ON c.id=n.challenge_id WHERE '
            .'(EXISTS (SELECT 1 FROM '.$database->rows->table('production_identity_attempts').' p WHERE p.notice_id=n.id AND NOT EXISTS (SELECT 1 FROM '.$database->rows->table('production_identity_outcomes').' r WHERE r.attempt_id=p.id)) '
            .'OR (c.created_at<=? AND c.expires_at>? AND NOT EXISTS (SELECT 1 FROM '.$database->rows->table('production_identity_verifications').' v WHERE v.challenge_id=c.id))) '
            .'AND NOT EXISTS (SELECT 1 FROM '.$database->rows->table('production_identity_attempts').' a JOIN '.$database->rows->table('production_identity_outcomes').' o ON o.attempt_id=a.id WHERE a.notice_id=n.id AND (o.status IN (\'accepted\',\'unknown\',\'blocked\') OR a.number>=3)) ORDER BY n.id LIMIT '.$limit);
        $now = now()->utc()->format('Y-m-d H:i:s');
        $statement->execute([$now, $now]);
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            try {
                $worker->process((int) $row['id']);
            } catch (\Throwable) { /* fixed outcome; no private diagnostics */
            }
        }
        $this->info('Identity notice recovery pass completed.');

        return self::SUCCESS;
    }
}
