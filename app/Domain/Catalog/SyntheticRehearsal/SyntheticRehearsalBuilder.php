<?php

namespace App\Domain\Catalog\SyntheticRehearsal;

use App\Application\Media\IngestMediaUpload;
use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Domain\Media\MediaFailure;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Models\MediaProcessingRun;
use App\Domain\Media\PrivateMediaFiles;
use App\Domain\Media\QueueMediaProcessing;
use App\Domain\Media\VerifiedMedia;
use App\Domain\Rights\CreateLicenseDraft;
use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\PublishLicense;
use App\Domain\Rights\ReviewLicense;
use App\Domain\Rights\SaveLicenseTemplate;
use App\Domain\Rights\SaveRightsDeclaration;
use App\Domain\Rights\VerifiedLicense;
use App\Domain\Rights\VerifyRightsDeclaration;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Sleep;

/**
 * Builds the labelled synthetic catalog only through the existing domain services, in the order an operator would use
 * the admin: license template → draft → review submission → independent approval → publication; track metadata →
 * rights declaration → verification → media intake → media processing → offer drafts → offer publication → reviewed
 * track publication. Each service keeps its own authority, MFA, evidence, media and readiness checks; nothing here
 * writes a row directly.
 *
 * Every step first looks for the row a previous run left, by the shared marker, so a re-run reports "already present"
 * or resumes an interrupted draft without duplicating tracks, licenses, offers or media revisions it can still use.
 * Rows that carry the marker but differ from the definition are refused, never edited. The builder never creates
 * quotes, orders, payments, grants or contracts.
 */
final class SyntheticRehearsalBuilder
{
    /** @return array<string, array{version: LicenseVersion, state: string}> */
    public function licenses(User $operator, User $reviewer): array
    {
        $result = [];
        foreach (SyntheticRehearsalCatalog::licenses() as $key => $definition) {
            $result[$key] = $this->license($definition, $operator, $reviewer);
        }

        return $result;
    }

    /**
     * @param  array<string, array{version: LicenseVersion, state: string}>  $licenses
     * @return array{track: Track, state: string, ready: bool, revisions: array<string, OfferRevision>}
     */
    public function track(array $definition, array $licenses, User $operator): array
    {
        $track = Track::query()->where('slug', $definition['slug'])->first();
        if ($track !== null && $track->status === 'published') {
            if (! $this->metadataMatches($track, $definition)) {
                // Staff edited a published synthetic track; its labels can no longer be vouched for, and it is not changed back.
                return ['track' => $track, 'state' => 'present, metadata differs from the definition; left unchanged', 'ready' => false,
                    'revisions' => $this->revisions($track, $licenses)];
            }
            if (! $this->offersMatch($track, $licenses)) {
                // Its active offers are no longer exactly the two TEST PRICE offers; they are reported, never changed back.
                return ['track' => $track, 'state' => 'present, offers differ from the definition; left unchanged', 'ready' => false,
                    'revisions' => $this->revisions($track, $licenses)];
            }
            $blockers = app(PublicationReadiness::class)->blockers($track);

            return ['track' => $track, 'state' => $blockers === [] ? 'already present' : 'present, not ready: '.implode(' ', $blockers),
                'ready' => $blockers === [], 'revisions' => $this->revisions($track, $licenses)];
        }
        if ($track !== null && $track->published_slug !== null) {
            // Staff withdrew it with the ordinary unpublish action; a re-run never republishes it.
            return ['track' => $track, 'state' => 'withdrawn by staff; left unpublished', 'ready' => false, 'revisions' => []];
        }
        $state = 'resumed and published';
        if ($track === null) {
            $track = app(SaveTrackMetadata::class)->handle(null, SyntheticRehearsalCatalog::metadata($definition), $operator);
            $state = 'created and published';
        } elseif (! $this->metadataMatches($track, $definition)) {
            $this->refuse($definition['slug'].' carries the synthetic marker but its metadata differs from the definition; it was left unchanged.');
        }
        $this->rights($track, $definition, $operator);
        $this->media($track, $definition, $operator);
        foreach (SyntheticRehearsalCatalog::licenses() as $key => $license) {
            $this->offer($track, $license, $licenses[$key]['version'], $operator);
        }
        $publisher = app(PublishTrack::class);
        $review = $publisher->reviewManifest($track, $operator);
        $track = $publisher->publishManifestReviewed($review, $operator)->fresh();
        $blockers = app(PublicationReadiness::class)->blockers($track);

        return ['track' => $track, 'state' => $blockers === [] ? $state : 'published, not ready: '.implode(' ', $blockers),
            'ready' => $blockers === [], 'revisions' => $this->revisions($track, $licenses)];
    }

    private function license(array $definition, User $operator, User $reviewer): array
    {
        $template = LicenseTemplate::query()->where('slug', $definition['slug'])->first();
        $state = 'already present';
        if ($template === null) {
            $template = app(SaveLicenseTemplate::class)->create(['name' => $definition['name'], 'slug' => $definition['slug'],
                'type' => $definition['type']], $operator);
            $state = 'created';
        } elseif ($template->name !== $definition['name'] || $template->type !== $definition['type']) {
            $this->refuse('License template '.$definition['slug'].' differs from its synthetic definition; it was left unchanged.');
        }
        $version = $template->versions()->orderByDesc('id')->first();
        if ($version === null) {
            $version = app(CreateLicenseDraft::class)->handle($template, ['authored_source' => $definition['source'],
                'structured_terms' => $this->terms($definition)], $operator);
            $state = 'created';
        } elseif (! $this->licenseMatches($version, $definition)) {
            $this->refuse('The latest version of license template '.$definition['slug'].' differs from its synthetic definition; it was left unchanged.');
        } elseif ($state === 'already present' && $version->status !== 'published') {
            $state = 'resumed';
        }
        $review = app(ReviewLicense::class);
        if ($version->status === 'draft') {
            $version = $review->submit($version, $operator);
        }
        if ($version->status === 'legal_review') {
            $version = $review->approve($version, $reviewer, ['approval_reference' => SyntheticRehearsalCatalog::APPROVAL_REFERENCE,
                'review_hash' => $version->submission_hash, 'summary_consistency_confirmed' => true]);
        }
        if ($version->status === 'approved') {
            $version = app(PublishLicense::class)->handle($version, $operator);
        }
        if ($version->status !== 'published' || ! app(VerifiedLicense::class)->available($version)) {
            $this->refuse('License template '.$definition['slug'].' has no available published synthetic version.');
        }

        return ['version' => $version->fresh(), 'state' => $state];
    }

    private function rights(Track $track, array $definition, User $operator): void
    {
        $latest = $track->rightsDeclarations()->latest('id')->first();
        $expected = SyntheticRehearsalCatalog::rights($definition, $track->id);
        if ($latest === null) {
            $latest = app(SaveRightsDeclaration::class)->create($expected, $operator);
        } elseif ($latest->provenance_reference !== $expected['provenance_reference'] || $latest->sample_disclosure !== $expected['sample_disclosure']) {
            // Both evidence fields must be exactly what the definition writes: the marker prefix alone would let staff edit the
            // sample disclosure or the rest of the provenance and have this run verify the edited claim as rehearsal evidence.
            $this->refuse($definition['slug'].' has a rights declaration that differs from the synthetic definition; it was left unchanged.');
        }
        if ($latest->status === 'verified') {
            return;
        }
        $verifier = app(VerifyRightsDeclaration::class);
        $verifier->verifyReviewed($verifier->review($latest, $operator), $operator);
    }

    /**
     * Brings in the preview and artwork a track needs. Anything this run did not just generate must trace to the generated
     * source: a ready derivative is kept, and an interrupted source is processed again, only when the source's SHA-256 is that
     * of the deterministic file the definition generates; otherwise staff replaced it and the run refuses, changing nothing.
     */
    private function media(Track $track, array $definition, User $operator): void
    {
        foreach (['master_wav' => 'preview_tagged', 'artwork' => 'artwork'] as $role => $output) {
            $current = $track->assets()->where('role', $output)->where('status', 'ready')->latest('id')->first();
            if ($current !== null && app(VerifiedMedia::class)->available($current)) {
                $parent = $current->parent_asset_id === null ? null : MediaAsset::query()->find($current->parent_asset_id);
                $this->requireGenerated($definition, $role, $parent?->sha256);

                continue;
            }
            // A source an interrupted run already took in is processed again (a failed run is re-queued), not re-uploaded.
            $name = $definition['slug'].($role === 'artwork' ? '-artwork.png' : '-master.wav');
            $source = MediaAsset::query()->where('track_id', $track->id)->whereNull('parent_asset_id')->where('role', $role)
                ->where('status', 'quarantined')->where('original_name', $name)->latest('id')->first();
            if ($source !== null) {
                $this->requireGenerated($definition, $role, $source->sha256);
            } else {
                $source = $this->ingest($track, $definition, $role, $name, $operator);
            }
            $this->process(app(QueueMediaProcessing::class)->handle($source, $operator), $definition['slug']);
        }
    }

    private function ingest(Track $track, array $definition, string $role, string $name, User $operator): MediaAsset
    {
        $files = app(PrivateMediaFiles::class);
        $workspace = $files->workspace();
        try {
            $path = $this->generate($definition, $role, $workspace);

            // The upload-origin flag adapts a file this process just generated, as resumable completion does.
            return app(IngestMediaUpload::class)->handle($track, new UploadedFile($path, $name, null, UPLOAD_ERR_OK, true), $role, $operator);
        } finally {
            $files->cleanup($workspace);
        }
    }

    private function generate(array $definition, string $role, string $workspace): string
    {
        $generator = app(SyntheticRehearsalMedia::class);

        return $role === 'artwork' ? $generator->artwork($definition, $workspace) : $generator->master($definition, $workspace);
    }

    /**
     * Refuses unless the given source hash is that of the file the definition generates now. The master is bit-exact; the
     * artwork is a GD PNG, so a changed GD or zlib build can also differ, which this refusal reports like a replaced file.
     */
    private function requireGenerated(array $definition, string $role, ?string $sourceHash): void
    {
        $files = app(PrivateMediaFiles::class);
        $workspace = $files->workspace();
        try {
            $generated = hash_file('sha256', $this->generate($definition, $role, $workspace));
        } finally {
            $files->cleanup($workspace);
        }
        if (! is_string($sourceHash) || ! is_string($generated) || ! hash_equals($generated, $sourceHash)) {
            $this->refuse($definition['slug'].' has '.$role.' media that differs from the synthetic definition; it was left unchanged.');
        }
    }

    /**
     * Runs the queued processing request in this process with the same service the media worker uses. Its claim token
     * makes the two exclusive: whichever claims first processes, and the other waits for that run's outcome.
     */
    private function process(MediaProcessingRun $run, string $slug): void
    {
        $run = $run->fresh();
        if ($run->status !== 'completed') {
            try {
                $run = app(MediaProcessor::class)->handle($run->id);
            } catch (MediaFailure $failure) {
                $this->refuse($slug.' media processing failed: '.$failure->failureCode.'. Correct the cause and re-run; finished steps are kept.');
            }
        }
        $deadline = now()->addSeconds((int) config('media.claim_lease_seconds'));
        while (in_array($run->status, ['queued', 'processing'], true) && now()->lt($deadline)) {
            Sleep::for(2)->seconds();
            $run = $run->fresh();
        }
        if ($run->status !== 'completed') {
            $this->refuse($slug.' media processing did not complete ('.($run->failure_code ?? $run->status).'). Re-run once the media worker is healthy.');
        }
    }

    private function offer(Track $track, array $definition, LicenseVersion $version, User $operator): OfferRevision
    {
        $offers = Offer::query()->where('track_id', $track->id)->where('license_version_id', $version->id)->orderBy('id')->get();
        $active = $offers->first(fn (Offer $offer): bool => $offer->is_active && $offer->current_revision_id !== null);
        if ($active !== null) {
            $revision = $active->currentRevision()->firstOrFail();
            if ($revision->price_minor !== $definition['price_minor'] || $revision->currency !== SyntheticRehearsalCatalog::CURRENCY) {
                $this->refuse($track->slug.' has an active synthetic offer with a different price; it was left unchanged.');
            }

            return $revision;
        }
        $preview = $track->assets()->where('role', 'preview_tagged')->where('status', 'ready')->latest('id')->firstOrFail();
        $deliverables = MediaAsset::query()->where('track_id', $track->id)->where('processing_run_id', $preview->processing_run_id)
            ->where('status', 'ready')->whereIn('role', $definition['roles'])->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $data = ['track_id' => $track->id, 'license_version_id' => $version->id, 'price_minor' => $definition['price_minor'],
            'currency' => SyntheticRehearsalCatalog::CURRENCY, 'deliverable_asset_ids' => $deliverables];
        $offer = app(SaveOfferDraft::class)->handle($offers->first(fn (Offer $offer): bool => ! $offer->is_active), $data, $operator);

        return app(PublishOffer::class)->handle($offer, $operator);
    }

    /** @return array<string, OfferRevision> */
    private function revisions(Track $track, array $licenses): array
    {
        $revisions = [];
        foreach ($licenses as $key => $license) {
            $offer = Offer::query()->where('track_id', $track->id)->where('license_version_id', $license['version']->id)
                ->where('is_active', true)->whereNotNull('current_revision_id')->orderBy('id')->first();
            if ($offer !== null) {
                $revisions[$key] = $offer->currentRevision()->firstOrFail();
            }
        }

        return $revisions;
    }

    /** Exactly one active offer per synthetic license, each at its definition's TEST PRICE, and no other active offer. */
    private function offersMatch(Track $track, array $licenses): bool
    {
        $revisions = $this->revisions($track, $licenses);
        foreach (SyntheticRehearsalCatalog::licenses() as $key => $definition) {
            $revision = $revisions[$key] ?? null;
            if ($revision === null || $revision->price_minor !== $definition['price_minor'] || $revision->currency !== SyntheticRehearsalCatalog::CURRENCY) {
                return false;
            }
        }

        return Offer::query()->where('track_id', $track->id)->where('is_active', true)->count() === count($licenses);
    }

    private function terms(array $definition): array
    {
        return ['schema_version' => 1, 'features' => $definition['features'], 'required_asset_roles' => $definition['roles']];
    }

    private function licenseMatches(LicenseVersion $version, array $definition): bool
    {
        return $version->authored_source === $definition['source'] && is_array($version->structured_terms)
            && hash_equals(CanonicalJson::hash($this->terms($definition)), CanonicalJson::hash($version->structured_terms))
            && $version->effective_from === null && $version->effective_until === null;
    }

    private function metadataMatches(Track $track, array $definition): bool
    {
        $expected = SyntheticRehearsalCatalog::metadata($definition);
        $actual = array_replace($track->only(array_keys($expected)), ['tags' => $track->tags ?? []]);

        return CanonicalJson::hash($actual) === CanonicalJson::hash($expected);
    }

    private function refuse(string $message): never
    {
        throw new SyntheticRehearsalRefusal($message);
    }
}
