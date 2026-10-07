<?php

namespace Tests\Feature;

use App\Domain\Services\Projects\Attachments\ServiceProjectAttachmentAuthority;
use App\Domain\SupportAttachments\AttachmentActor;
use App\Domain\SupportAttachments\AttachmentException;
use App\Domain\SupportAttachments\AttachmentRows;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ServiceProjectFixtures;
use Tests\TestCase;

/** Native source dictionary canary; retained rows are restored before disposable fixture cleanup. */
class ServiceProjectCommittedSchemaIndependentTest extends TestCase
{
    use FinalizationDatabaseMigrations {
        runDatabaseMigrations as private runDisposableMigrations;
    }

    public function runDatabaseMigrations()
    {
        if (DB::getDriverName() !== 'mysql' || getenv('ATTACHMENT_NATIVE_ISOLATED') !== '1' || DB::getDatabaseName() !== 'vaseyaudio_support_closure') {
            $this->markTestSkipped('Actual dedicated synthetic native reviewer database required.');
        }
        $this->runDisposableMigrations();
    }

    public function test_permanent_view_cannot_replace_the_captured_source_event_table_after_commit(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('S', 32))]);
        $fixture = ServiceProjectFixtures::setup();
        $authority = new ServiceProjectAttachmentAuthority;
        $rows = new AttachmentRows;
        $receipt = DB::transaction(function () use ($fixture, $authority, $rows) {
            $proof = $authority->lock($fixture['project']['id'], null, 'download', AttachmentActor::customer($fixture['customer']['user'], $fixture['customer']['principal']), $rows);
            $receipt = $authority->committedReadReceipt($proof, $rows);
            $authority->proveCurrent($proof, $rows);

            return $receipt;
        });
        $pdo = DB::connection()->getPdo();
        $pdo->exec('RENAME TABLE service_project_events TO review_retained_service_project_events');
        try {
            $pdo->exec('CREATE VIEW service_project_events AS SELECT * FROM review_retained_service_project_events');
            $this->assertSame('VIEW', $pdo->query("SELECT TABLE_TYPE FROM information_schema.TABLES WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() AND BINARY TABLE_NAME = BINARY 'service_project_events'")->fetchColumn());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse($pdo->inTransaction());
            try {
                $receipt->proveClosed();
                $this->fail('The original complete base table cannot be replaced by an unowned durable view.');
            } catch (AttachmentException) {
                $this->assertTrue(true);
            }
        } finally {
            $pdo->exec('DROP VIEW IF EXISTS service_project_events');
            $pdo->exec('RENAME TABLE review_retained_service_project_events TO service_project_events');
        }
    }
}
