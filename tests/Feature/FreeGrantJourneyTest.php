<?php

namespace Tests\Feature;

use App\Domain\Contracts\ContractFiles;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Grants\Free\FreeGrantDefinitions;
use App\Domain\Grants\Free\FreeGrantDocuments;
use App\Domain\Grants\Free\FreeGrantException;
use App\Domain\Grants\Free\FreeGrantRecords;
use App\Domain\Grants\Free\FreeGrantRendererProcess;
use App\Domain\Grants\Free\FreeGrants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FreeGrantFixtures;
use Tests\TestCase;

final class FreeGrantJourneyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private function refused(callable $call, int $status): void
    {
        try {
            $call();
            $this->fail('Current free origin authority must refuse.');
        } catch (FreeGrantException $error) {
            $this->assertSame($status, $error->status);
        }
    }

    public function test_exact_explicit_free_assent_replays_after_closure_without_paid_records_or_changed_original_scope(): void
    {
        $this->fakePrivateMediaStorage();
        $f = FreeGrantFixtures::source();
        $definition = FreeGrantFixtures::publish($f);
        $accepted = FreeGrantFixtures::accept($f, $definition);
        $grants = new FreeGrants;
        $this->assertSame('none', $accepted['origin']['collection']);
        $this->assertSame('pending', $accepted['origin']['documentStatus']);
        $this->assertSame([], $accepted['origin']['files']);
        (new FreeGrantDefinitions)->availability($definition['id'], ['requestKey' => (string) Str::uuid(), 'expectedVersion' => 1,
            'open' => false, 'reason' => 'Synthetic new-admission closure'], $f['author']);
        $this->assertSame($accepted['origin'], $grants->accept($definition['id'], $accepted['input'], $f['customer']['principal'], $f['customer']['user']));
        $changed = [...$accepted['input'], 'declaredName' => 'Different original declaration'];
        $this->refused(fn () => $grants->accept($definition['id'], $changed, $f['customer']['principal'], $f['customer']['user']), 409);
        $other = CustomerFixtures::account();
        $this->refused(fn () => $grants->readOrigin($accepted['origin']['id'], $other['principal'], $other['user']), 404);
        $this->assertDatabaseCount('free_origins', 1);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_failed_renderer_retains_same_assent_then_real_offline_pdf_retry_publishes_one_exact_original(): void
    {
        $this->fakePrivateMediaStorage();
        $f = FreeGrantFixtures::source();
        $definition = FreeGrantFixtures::publish($f);
        $accepted = FreeGrantFixtures::accept($f, $definition)['origin'];
        $before = DB::table('free_origins')->first();
        app()->instance(FreeGrantRendererProcess::class, new FreeGrantRendererProcess(fn () => throw new \RuntimeException('Synthetic bounded transport interruption')));
        try {
            (new FreeGrantDocuments)->issue($accepted['id'], $accepted['originHash'], $f['customer']['principal'], $f['customer']['user']);
            $this->fail('Interrupted render cannot publish an original.');
        } catch (ContractIssuanceException) {
            $this->assertDatabaseCount('free_originals', 0);
            $this->assertSame('failed', DB::table('free_document_work')->value('state'));
        }
        $this->assertEquals($before, DB::table('free_origins')->first());
        app()->forgetInstance(FreeGrantRendererProcess::class);
        $issued = (new FreeGrantDocuments)->issue($accepted['id'], $accepted['originHash'], $f['customer']['principal'], $f['customer']['user']);
        $this->assertSame('complete', $issued['documentStatus']);
        $this->assertSame(2, $issued['renderAttempts']);
        $this->assertCount(2, $issued['files']);
        $this->assertDatabaseCount('free_originals', 1);
        $record = (array) DB::table('free_originals')->sole();
        $manifest = FreeGrantRecords::decode($record);
        $bytes = (new ContractFiles)->verify($manifest['artifact']);
        $this->assertSame($manifest['artifact']['pdf_hash'], hash('sha256', $bytes));
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertSame($issued, (new FreeGrantDocuments)->issue($accepted['id'], $accepted['originHash'], $f['customer']['principal'], $f['customer']['user']));
        $this->assertSame($record, (array) DB::table('free_originals')->sole());
        $this->assertEquals($before, DB::table('free_origins')->first());
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('license_grants', 0);
    }
}
