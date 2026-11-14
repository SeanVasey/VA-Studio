<?php

namespace Tests\Feature;

use App\Domain\Catalog\ChangeTrackVisibility;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\TrackPublicationSchedules;
use App\Domain\Commerce\ProductionCheckout\CurrentSelection;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxCheckout;
use App\Domain\Customers\ProductionCustomerAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutFixtures as F;
use Tests\Support\ProductionTaxCheckoutFixtures;
use Tests\Support\RecordingTaxCheckoutTransport;
use Tests\TestCase;

/**
 * An unlisted track is purchasable under the same guards as a published one on every chain (BL-41, SQ-15), so the production
 * selection reads `TrackVisibility::isLive()`. Draft, scheduled and private tracks stay refused.
 */
class TrackVisibilityProductionSelectionTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionTaxCheckoutFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('v', 32))]);
        $this->identitySetup();
    }

    public static function livePreparableStates(): array
    {
        return ['published' => ['published'], 'unlisted' => ['unlisted']];
    }

    public static function refusedStates(): array
    {
        return ['private' => ['private'], 'draft' => ['draft'], 'scheduled' => ['scheduled']];
    }

    /** Moves the fixture's published track to `$status`, using the real services (scheduled goes through a schedule row). */
    private function move(array $fixture, string $status): void
    {
        $track = Track::findOrFail($fixture['track']->id);
        $actor = $fixture['actor'];
        match ($status) {
            'published' => null,
            'unlisted' => app(ChangeTrackVisibility::class)->handle($track, $actor, 'unlisted', $track->metadata_version, $track->publication_version),
            'private' => app(ChangeTrackVisibility::class)->handle($track, $actor, 'private', $track->metadata_version, $track->publication_version),
            'draft' => app(PublishTrack::class)->unpublish($track, $actor),
            'scheduled' => $this->schedule($track, $actor),
        };
    }

    private function schedule(Track $track, $actor): void
    {
        $draft = app(PublishTrack::class)->unpublish($track, $actor);
        $at = CarbonImmutable::now('UTC')->startOfMinute()->addMinutes(10)->setTimezone('America/Chicago')->format('Y-m-d\TH:i');
        app(TrackPublicationSchedules::class)->schedule($draft, $actor, $at, 'published', $draft->metadata_version, $draft->publication_version);
    }

    private function load(array $fixture): array
    {
        return DB::transaction(fn () => CurrentSelection::load(new CurrentRows(DB::getPdo(), DB::getDriverName()), $fixture['items'], CarbonImmutable::now('UTC')));
    }

    #[DataProvider('livePreparableStates')]
    public function test_a_live_track_is_preparable_on_the_production_checkout(string $status): void
    {
        $fixture = F::catalog();
        $this->move($fixture, $status);
        $selection = $this->load($fixture);
        $this->assertCount(1, $selection['selection']['lines']);
    }

    #[DataProvider('refusedStates')]
    public function test_a_hidden_track_is_refused_by_the_production_checkout(string $status): void
    {
        $fixture = F::catalog();
        $this->move($fixture, $status);
        $this->expectException(ValidationException::class);
        $this->load($fixture);
    }

    #[DataProvider('livePreparableStates')]
    public function test_a_live_track_is_preparable_on_the_production_tax_checkout(string $status): void
    {
        $catalog = $this->taxCatalog();
        $this->move($catalog, $status);
        $buyer = $this->enrollThroughLocalSmtp();
        $checkout = new ProductionTaxCheckout(new ProductionCustomerAccess, new RecordingTaxCheckoutTransport);
        $preview = $checkout->preview($buyer['principal'], $buyer['user'], $catalog['candidate']->id, $catalog['items']);
        $this->assertNotEmpty($preview['previewHash']);
    }

    #[DataProvider('refusedStates')]
    public function test_a_hidden_track_is_refused_by_the_production_tax_checkout(string $status): void
    {
        $catalog = $this->taxCatalog();
        $this->move($catalog, $status);
        $buyer = $this->enrollThroughLocalSmtp();
        $checkout = new ProductionTaxCheckout(new ProductionCustomerAccess, new RecordingTaxCheckoutTransport);
        $this->expectException(ValidationException::class);
        $checkout->preview($buyer['principal'], $buyer['user'], $catalog['candidate']->id, $catalog['items']);
    }
}
