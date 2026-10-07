<?php

namespace App\Domain\Services\Projects;

use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerAccessPolicy;
use App\Domain\Customers\CustomerPrincipal;
use App\Domain\Services\Models\ServiceDraftVersion;
use App\Domain\Services\Projects\Models\ServiceProject;
use App\Domain\Services\ServiceDraftManifest;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use PDO;

/** Synthetic scope preparation only. Each command fences authority before an immutable append. */
final class ServiceProjects
{
    public function customerIndex(CustomerPrincipal $principal, User $actor): array
    {
        return $this->transaction(function () use ($principal, $actor): array {
            $authority = $this->customer($principal, $actor);
            $services = [];
            $drafts = $this->rows('service_drafts', [], 'id', 50);
            foreach ($drafts as $draft) {
                $version = $this->rows('service_draft_versions', ['draft_id' => (int) $draft['id'], 'number' => (int) $draft['version']])[0] ?? null;
                if (! $version) {
                    throw new ServiceProjectException(503);
                }
                $manifest = $this->service($version);
                $services[] = ['versionId' => (int) $version['id'], 'version' => (int) $version['number'],
                    'hash' => $version['manifest_sha256'], 'title' => $manifest['title'],
                    'description' => $manifest['description'], 'questions' => $manifest['brief_questions']];
            }
            $projects = [];
            foreach ($this->rows('service_projects', ['customer_account_id' => $principal->accountId], 'id', 25, true) as $row) {
                $snapshot = $this->project($row['public_id'], $principal->accountId)['projection'];
                $projects[] = array_intersect_key($snapshot, array_flip(['id', 'version', 'status', 'title', 'submittedAt']));
            }
            $this->finalAuthority($authority);

            return ['schema' => 1, 'testOnly' => true, 'services' => $services, 'projects' => $projects];
        });
    }

    public function submitBrief(array $body, CustomerPrincipal $principal, User $actor): array
    {
        $body = ServiceProjectInput::brief($body);

        return $this->transaction(function () use ($body, $principal, $actor): array {
            $authority = $this->customer($principal, $actor);
            $existing = $this->rows('service_projects', ['customer_account_id' => $principal->accountId, 'creation_key' => $body['requestKey']])[0] ?? null;
            if ($existing) {
                $saved = $this->decode($existing['brief'], $existing['brief_hash']);
                if (CanonicalJson::hash($saved['request']) !== CanonicalJson::hash($body)) {
                    throw new ServiceProjectException;
                }
                $result = $this->project($existing['public_id'], $principal->accountId);
                $this->finalAuthority($authority);

                return ['project' => $result['projection'], 'replayed' => true];
            }
            $version = $this->rows('service_draft_versions', ['id' => $body['serviceVersionId']])[0] ?? null;
            if (! $version) {
                throw new ServiceProjectException(404);
            }
            $draft = $this->rows('service_drafts', ['id' => (int) $version['draft_id']])[0] ?? null;
            $manifest = $this->service($version);
            if (! $draft || (int) $draft['version'] !== (int) $version['number'] || $body['serviceHash'] !== $version['manifest_sha256']
                || count($body['answers']) !== count($manifest['brief_questions'])) {
                throw new ServiceProjectException;
            }
            $brief = ['schema' => 'service-project-brief-v1', 'request' => $body];
            $id = DB::table('service_projects')->insertGetId(['public_id' => (string) Str::uuid(), 'customer_account_id' => $principal->accountId,
                'service_version_id' => (int) $version['id'], 'service_manifest' => $this->encrypt($manifest), 'service_hash' => $version['manifest_sha256'],
                'brief' => $this->encrypt($brief), 'brief_hash' => CanonicalJson::hash($brief), 'creation_key' => $body['requestKey'],
                'created_by' => $actor->id, 'created_at' => now()]);
            $project = $this->rows('service_projects', ['id' => $id])[0];
            $expected = $this->rawEvidence($project);
            $this->audit($project, 'brief_submitted', 0, $project['brief_hash'], $actor->id);
            $this->finalEvidence($project, $expected);
            $this->finalAuthority($authority);

            return ['project' => $this->projection($project, []), 'replayed' => false];
        });
    }

    public function customerShow(string $id, CustomerPrincipal $principal, User $actor): array
    {
        return $this->transaction(function () use ($id, $principal, $actor): array {
            $authority = $this->customer($principal, $actor);
            $result = $this->project($id, $principal->accountId)['projection'];
            $this->finalAuthority($authority);

            return $result;
        });
    }

    public function staffShow(string $id, User $actor): array
    {
        return $this->transaction(function () use ($id, $actor): array {
            $authority = $this->operator($actor);
            $result = $this->project($id)['projection'];
            $this->finalAuthority($authority);

            return $result;
        });
    }

    public function staffActor(?User $actor): User
    {
        app(ServiceProjectPolicy::class)->requireEnabled();
        if (! $actor || ! Gate::forUser($actor)->allows('administer-catalog') || ! AdminMultiFactor::satisfiedBy($actor)) {
            throw new AuthorizationException;
        }

        return $actor;
    }

    public function customerCommand(string $id, array $body, CustomerPrincipal $principal, User $actor): array
    {
        return $this->command($id, ServiceProjectInput::command($body, false), $actor, $principal);
    }

    public function staffCommand(string $id, array $body, User $actor): array
    {
        return $this->command($id, ServiceProjectInput::command($body, true), $actor);
    }

    /** Internal retained-row validation; the attachment adapter separately owns current authority and locks. */
    public function attachmentGraphState(array $project, array $events): array
    {
        if (count($events) > 1000) {
            throw new ServiceProjectException(503);
        }
        $projection = $this->projection($project, $events);

        return ['version' => $projection['version'], 'status' => $projection['status']];
    }

    private function command(string $id, array $body, User $actor, ?CustomerPrincipal $principal = null): array
    {
        return $this->transaction(function () use ($id, $body, $actor, $principal): array {
            $authority = $principal ? $this->customer($principal, $actor) : $this->operator($actor);
            $kind = $principal ? 'buyer' : 'staff';
            $context = $this->project($id, $principal?->accountId);
            $project = $context['row'];
            $events = $context['events'];
            $requestHash = CanonicalJson::hash(['actor' => $actor->id, 'kind' => $kind, 'command' => $body]);
            foreach ($events as $event) {
                if ($event['request_key'] === $body['requestKey']) {
                    if ($event['request_hash'] !== $requestHash) {
                        throw new ServiceProjectException;
                    }
                    $this->finalAuthority($authority);

                    return ['project' => $context['projection'], 'replayed' => true];
                }
            }
            if ($body['expectedVersion'] !== count($events) || count($events) >= 1000) {
                throw new ServiceProjectException;
            }
            $eventId = (string) Str::uuid();
            $state = $this->state($events);
            $quote = $body['action'] === 'author_quote' ? ['schema' => 'service-project-quote-v1', 'projectId' => $id,
                'briefHash' => $project['brief_hash'], 'terms' => $body['quote']] : null;
            $quoteHash = $quote ? CanonicalJson::hash($quote) : null;
            $next = $this->advance($state, $body, $kind, $eventId, $quoteHash, $context['quotes']);
            $payload = ['schema' => 'service-project-event-v1', 'command' => $body, 'state' => $next, 'quote' => $quote];
            $number = count($events) + 1;
            DB::table('service_project_events')->insert(['public_id' => $eventId, 'project_id' => (int) $project['id'],
                'number' => $number, 'operation' => $body['action'], 'actor_kind' => $kind, 'actor_id' => $actor->id,
                'request_key' => $body['requestKey'], 'request_hash' => $requestHash, 'payload' => $this->encrypt($payload),
                'payload_hash' => CanonicalJson::hash($payload), 'created_at' => now()]);
            $expected = $this->rawEvidence($project);
            $this->audit($project, $body['action'], $number, CanonicalJson::hash($payload), $actor->id);
            $this->finalEvidence($project, $expected);
            $finalEvents = $expected['events'];
            $result = $this->projection($project, $finalEvents);
            $this->finalAuthority($authority);

            return ['project' => $result, 'replayed' => false];
        });
    }

    /** The only transition table. No action sets a paid, delivered or completed state. */
    private function advance(array $state, array $body, string $kind, string $eventId, ?string $quoteHash, array $quotes): array
    {
        $action = $body['action'];
        if ($action === 'author_quote') {
            if ($kind !== 'staff' || ! in_array($state['status'], ['submitted', 'quoted', 'declined'], true)) {
                throw new ServiceProjectException;
            }

            return ['status' => 'quoted', 'quoteId' => $eventId, 'quoteHash' => $quoteHash, 'milestones' => [], 'revisionsUsed' => 0];
        }
        if (in_array($action, ['accept_quote', 'decline_quote'], true)) {
            if ($kind !== 'buyer' || $state['status'] !== 'quoted' || $body['quoteId'] !== $state['quoteId'] || $body['quoteHash'] !== $state['quoteHash']) {
                throw new ServiceProjectException;
            }
            $state['status'] = $action === 'accept_quote' ? 'accepted' : 'declined';
            if ($action === 'accept_quote') {
                $state['milestones'] = array_fill_keys(array_column($quotes[$state['quoteId']]['terms']['milestones'], 'id'), 'pending');
            }

            return $state;
        }
        if ($action === 'withdraw') {
            if ($kind !== 'buyer' || ! in_array($state['status'], ['submitted', 'quoted', 'declined'], true)) {
                throw new ServiceProjectException;
            }
            $state['status'] = 'withdrawn';

            return $state;
        }
        if ($action === 'request_cancellation') {
            if ($kind !== 'buyer' || ! in_array($state['status'], ['accepted', 'in_progress', 'awaiting_customer_review', 'scope_reviewed'], true)) {
                throw new ServiceProjectException;
            }
            $state['status'] = 'cancellation_requested';

            return $state;
        }
        if ($action === 'cancel') {
            if ($kind !== 'staff' || in_array($state['status'], ['cancelled', 'withdrawn'], true)) {
                throw new ServiceProjectException;
            }
            $state['status'] = 'cancelled';

            return $state;
        }
        if (! in_array($state['status'], ['accepted', 'in_progress', 'awaiting_customer_review'], true) || ! array_key_exists($body['milestoneId'], $state['milestones'])) {
            throw new ServiceProjectException;
        }
        $milestone = $state['milestones'][$body['milestoneId']];
        $target = match ($action) {
            'begin_milestone' => $kind === 'staff' && $milestone === 'pending' ? 'active' : null,
            'ready_milestone' => $kind === 'staff' && $milestone === 'active' ? 'ready_for_review' : null,
            'approve_milestone' => $kind === 'buyer' && $milestone === 'ready_for_review' ? 'approved' : null,
            'request_revision' => $kind === 'buyer' && $milestone === 'ready_for_review' && $state['revisionsUsed'] < $quotes[$state['quoteId']]['terms']['revisionAllowance'] ? 'active' : null,
            default => null,
        };
        if ($target === null) {
            throw new ServiceProjectException;
        }
        $state['milestones'][$body['milestoneId']] = $target;
        if ($action === 'request_revision') {
            $state['revisionsUsed']++;
        }
        $state['status'] = count(array_unique(array_values($state['milestones']))) === 1 && $target === 'approved' ? 'scope_reviewed'
            : (in_array('ready_for_review', $state['milestones'], true) ? 'awaiting_customer_review' : 'in_progress');

        return $state;
    }

    private function project(string $id, ?int $account = null): array
    {
        try {
            ServiceProjectInput::key($id);
        } catch (ValidationException) {
            throw new ServiceProjectException(404);
        }
        $row = $this->rows('service_projects', ['public_id' => $id])[0] ?? null;
        if (! $row || ($account !== null && (int) $row['customer_account_id'] !== $account)) {
            throw new ServiceProjectException(404);
        }
        $events = $this->rows('service_project_events', ['project_id' => (int) $row['id']], 'number');
        $projection = $this->projection($row, $events);
        $quotes = [];
        foreach ($events as $event) {
            $payload = $this->decode($event['payload'], $event['payload_hash']);
            if ($payload['quote']) {
                $quotes[$event['public_id']] = $payload['quote'];
            }
        }

        return ['row' => $row, 'events' => $events, 'quotes' => $quotes, 'projection' => $projection];
    }

    private function projection(array $row, array $events): array
    {
        $manifest = $this->decode($row['service_manifest'], $row['service_hash']);
        (new ServiceDraftManifest)->verified($manifest);
        $brief = $this->decode($row['brief'], $row['brief_hash']);
        if (($brief['schema'] ?? null) !== 'service-project-brief-v1' || $brief['request']['serviceHash'] !== $row['service_hash']
            || count($brief['request']['answers']) !== count($manifest['brief_questions'])
            || $brief['request']['serviceVersionId'] !== (int) $row['service_version_id'] || $brief['request']['requestKey'] !== $row['creation_key']) {
            throw new ServiceProjectException(503);
        }
        ServiceProjectInput::brief($brief['request']);
        $state = $this->initial();
        $quotes = [];
        $history = [];
        foreach ($events as $index => $event) {
            $payload = $this->decode($event['payload'], $event['payload_hash']);
            if (($payload['schema'] ?? null) !== 'service-project-event-v1' || (int) $event['number'] !== $index + 1
                || $payload['command']['action'] !== $event['operation'] || $payload['command']['expectedVersion'] !== $index
                || $payload['command']['requestKey'] !== $event['request_key']
                || CanonicalJson::hash(['actor' => (int) $event['actor_id'], 'kind' => $event['actor_kind'], 'command' => $payload['command']]) !== $event['request_hash']) {
                throw new ServiceProjectException(503);
            }
            ServiceProjectInput::command($payload['command'], $event['actor_kind'] === 'staff');
            $quoteHash = null;
            if ($event['operation'] === 'author_quote') {
                $quote = ['schema' => 'service-project-quote-v1', 'projectId' => $row['public_id'], 'briefHash' => $row['brief_hash'], 'terms' => $payload['command']['quote']];
                if (CanonicalJson::hash($payload['quote']) !== CanonicalJson::hash($quote)) {
                    throw new ServiceProjectException(503);
                }
                $quotes[$event['public_id']] = $quote;
                $quoteHash = CanonicalJson::hash($quote);
            } elseif ($payload['quote'] !== null) {
                throw new ServiceProjectException(503);
            }
            $state = $this->advance($state, $payload['command'], $event['actor_kind'], $event['public_id'], $quoteHash, $quotes);
            if (CanonicalJson::hash($state) !== CanonicalJson::hash($payload['state'])) {
                throw new ServiceProjectException(503);
            }
            $history[] = ['id' => $event['public_id'], 'version' => $index + 1, 'action' => $event['operation'], 'actor' => $event['actor_kind'],
                'at' => $event['created_at'], 'reason' => $payload['command']['reason'] ?? null];
        }
        $quoteHistory = [];
        foreach ($quotes as $id => $quote) {
            $quoteHistory[] = ['id' => $id, 'hash' => CanonicalJson::hash($quote), ...$quote['terms']];
        }
        $accepted = ! in_array($state['status'], ['submitted', 'quoted', 'declined', 'withdrawn'], true) && $state['milestones'] !== [];

        return ['id' => $row['public_id'], 'version' => count($events), 'testOnly' => true, 'title' => $manifest['title'],
            'submittedAt' => $row['created_at'], 'summary' => $brief['request']['summary'],
            'answers' => array_map(fn ($question, $answer): array => ['question' => $question, 'answer' => $answer], $manifest['brief_questions'], $brief['request']['answers']),
            ...$state, 'quotes' => array_slice($quoteHistory, -10), 'quotesRetained' => count($quoteHistory), 'history' => array_slice($history, -100), 'eventsRetained' => count($history), 'scopeFrozen' => $accepted, 'paymentState' => 'not_collected', 'deliveryAuthorized' => false];
    }

    private function state(array $events): array
    {
        return $events === [] ? $this->initial() : $this->decode(end($events)['payload'], end($events)['payload_hash'])['state'];
    }

    private function initial(): array
    {
        return ['status' => 'submitted', 'quoteId' => null, 'quoteHash' => null, 'milestones' => [], 'revisionsUsed' => 0];
    }

    private function service(array $version): array
    {
        $model = new ServiceDraftVersion;
        $model->setRawAttributes($version, true);
        $manifest = $model->manifest;
        if (! is_array($manifest) || CanonicalJson::hash($manifest) !== $version['manifest_sha256']) {
            throw new ServiceProjectException(503);
        }

        return (new ServiceDraftManifest)->verified($manifest);
    }

    private function customer(CustomerPrincipal $principal, User $actor): array
    {
        app(CustomerAccess::class)->lock($principal, $principal->ownerKey, $actor);
        $authority = ['user' => $this->rows('users', ['id' => $principal->userId])[0] ?? null,
            'account' => $this->rows('customer_accounts', ['id' => $principal->accountId])[0] ?? null, 'principal' => $principal];
        $this->finalAuthority($authority);

        return $authority;
    }

    private function operator(User $actor): array
    {
        $current = $actor->exists ? User::whereKey($actor->id)->lockForUpdate()->first() : null;
        if (! $current || ! Gate::forUser($current)->allows('administer-catalog', [true]) || ! AdminMultiFactor::satisfiedBy($current, lockForUpdate: true)) {
            throw new AuthorizationException;
        }
        $authority = ['user' => $this->rows('users', ['id' => $current->id])[0] ?? null, 'account' => null, 'principal' => null];
        $this->finalAuthority($authority);

        return $authority;
    }

    /** Primary PDO locking reads emit no Eloquent or QueryExecuted observers after this fence. */
    private function finalAuthority(array $authority): void
    {
        app(ServiceProjectPolicy::class)->requireEnabled();
        $expected = $authority['user'];
        $user = $expected ? ($this->rows('users', ['id' => (int) $expected['id']])[0] ?? null) : null;
        if (! $user || $user !== $expected || $user['email_verified_at'] === null) {
            throw new AuthorizationException;
        }
        $principal = $authority['principal'];
        if ($principal) {
            app(CustomerAccessPolicy::class)->requireEnabled();
            $account = $this->rows('customer_accounts', ['id' => $principal->accountId])[0] ?? null;
            $hydrated = new User;
            $hydrated->setRawAttributes($user, true);
            if ($user['is_admin'] || ! $account || $account !== $authority['account'] || ! $account['active']
                || (int) $account['user_id'] !== $principal->userId || (int) $account['access_version'] !== $principal->accessVersion
                || ! hash_equals($account['owner_key'], $principal->ownerKey) || ! hash_equals(app(CustomerAccess::class)->stamp($hydrated), $principal->credentialStamp)) {
                throw new AuthorizationException;
            }
        } else {
            $panel = Filament::getPanel('admin');
            $hydrated = new User;
            $hydrated->setRawAttributes($user, true);
            if (! $user['is_admin'] || ! $panel || ($panel->isMultiFactorAuthenticationRequired()
                && ! collect($panel->getMultiFactorAuthenticationProviders())->contains(fn ($provider): bool => $provider->isEnabled($hydrated)))) {
                throw new AuthorizationException;
            }
        }
    }

    private function rawEvidence(array $row): array
    {
        return ['project' => $this->rows('service_projects', ['id' => (int) $row['id']])[0] ?? null,
            'events' => $this->rows('service_project_events', ['project_id' => (int) $row['id']], 'number')];
    }

    private function finalEvidence(array $row, array $expected): void
    {
        if ($this->rawEvidence($row) !== $expected) {
            throw new ServiceProjectException(503);
        }
    }

    private function rows(string $table, array $where, string $order = 'id', ?int $limit = null, bool $descending = false): array
    {
        $db = DB::connection();
        $grammar = $db->getQueryGrammar();
        $sql = 'SELECT * FROM '.$grammar->wrapTable($table)
            .($where === [] ? '' : ' WHERE '.implode(' AND ', array_map(fn (string $column): string => $grammar->wrap($column).' = ?', array_keys($where))))
            .' ORDER BY '.$grammar->wrap($order).($descending ? ' DESC' : '').($limit === null ? '' : ' LIMIT '.$limit).($db->getDriverName() === 'mysql' ? ' FOR UPDATE' : '');
        $statement = $db->getPdo()->prepare($sql);
        foreach (array_values($where) as $index => $value) {
            $statement->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function encrypt(array $body): string
    {
        return Crypt::encryptString(CanonicalJson::encode($body));
    }

    private function decode(string $encrypted, string $hash): array
    {
        $value = json_decode(Crypt::decryptString($encrypted), true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($value) || CanonicalJson::hash($value) !== $hash) {
            throw new ServiceProjectException(503);
        }

        return $value;
    }

    private function audit(array $row, string $operation, int $version, string $hash, int $actor): void
    {
        $project = new ServiceProject;
        $project->setRawAttributes($row, true);
        $project->exists = true;
        AuditEvent::recordAttributed('service_project.'.$operation, $project,
            ['version' => $version, 'evidence_sha256' => $hash, 'test_only' => true], $actor);
    }

    private function transaction(callable $callback): mixed
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Service projects require their own transaction.');
        }
        app(ServiceProjectPolicy::class)->requireEnabled();

        return DB::transaction($callback, 3);
    }
}
