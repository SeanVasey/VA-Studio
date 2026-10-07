<?php

namespace Tests\Support;

use App\Domain\Services\Models\ServiceDraftVersion;
use App\Domain\Services\Projects\ServiceProjects;
use App\Domain\Services\ServiceDrafts;
use Illuminate\Support\Str;

final class ServiceProjectFixtures
{
    public static function setup(): array
    {
        config(['services-projects.test_enabled' => true]);
        CustomerFixtures::configure();
        $operator = LicenseFixtures::admin();
        $operator->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $command = app(ServiceDrafts::class);
        $draft = $command->applyReviewed($command->review(null, PrivateProductDraftFixtures::payload('service'), $operator), $operator);
        $service = ServiceDraftVersion::where('draft_id', $draft->id)->sole();
        $customer = CustomerFixtures::account();
        $brief = ['requestKey' => (string) Str::uuid(), 'serviceVersionId' => $service->id, 'serviceHash' => $service->manifest_sha256,
            'summary' => 'Synthetic buyer brief <script> retained as text.', 'answers' => ['Synthetic mix review', 'No files uploaded']];
        $journey = app(ServiceProjects::class);
        $project = $journey->submitBrief($brief, $customer['principal'], $customer['user'])['project'];

        return compact('operator', 'draft', 'service', 'customer', 'brief', 'journey', 'project');
    }

    public static function quote(array $changes = []): array
    {
        return array_replace(['title' => 'Synthetic authored mix scope', 'scope' => 'Review the supplied synthetic arrangement. No delivered files.',
            'currency' => 'USD', 'totalMinor' => 17500, 'depositMinor' => 3500, 'revisionAllowance' => 1,
            'cancellation' => 'Synthetic test text; cancellation requires review. No automatic refund.',
            'milestones' => [['id' => 'mix-review', 'label' => 'Mix review', 'scope' => 'Review the proposed mix scope.']]], $changes);
    }

    public static function command(array $project, string $action, array $extra = []): array
    {
        return ['requestKey' => (string) Str::uuid(), 'expectedVersion' => $project['version'], 'action' => $action, ...$extra];
    }

    public static function author(array $fixture, ?array $quote = null): array
    {
        return $fixture['journey']->staffCommand($fixture['project']['id'], self::command($fixture['project'], 'author_quote', ['quote' => $quote ?? self::quote()]), $fixture['operator'])['project'];
    }

    public static function accept(array $fixture): array
    {
        $project = self::author($fixture);

        return $fixture['journey']->customerCommand($project['id'], self::command($project, 'accept_quote', ['quoteId' => $project['quoteId'], 'quoteHash' => $project['quoteHash']]), $fixture['customer']['principal'], $fixture['customer']['user'])['project'];
    }
}
