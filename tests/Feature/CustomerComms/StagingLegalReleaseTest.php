<?php

namespace Tests\Feature\CustomerComms;

use App\Console\Commands\BuildStagingLegalRelease;
use App\Domain\SiteBuilder\LegalPublicationGuard;
use App\Domain\SiteBuilder\LegalPublicationRefused;
use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Domain\SiteBuilder\StagingLegalRelease;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\LegalPageFixtures;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

/**
 * BL-39 (DD-33, PCL-07): the staging_placeholder class of the legal guard, and the staging legal release builder. The drafts in
 * docs/legal are read, never written; the builder resolves their brackets only with DD-33 values and never publishes.
 */
class StagingLegalReleaseTest extends TestCase
{
    use RefreshDatabase;

    private const DRAFTS = ['docs/legal/PRIVACY.md', 'docs/legal/TERMS.md', 'docs/legal/LICENSE-TIERS.md', 'docs/legal/README.md'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
    }

    private function environment(string $configured, ?string $running = null): void
    {
        config(['app.env' => $configured]);
        $this->app->instance('env', $running ?? $configured);
    }

    /** @return array<string, string> sha256 of each draft, as it stands on disk. */
    private function draftHashes(): array
    {
        return array_combine(self::DRAFTS, array_map(fn (string $path): string => hash_file('sha256', base_path($path)), self::DRAFTS));
    }

    /** Every string in a page, one per line, so that array syntax can never look like a bracket in the text. */
    private function text(array $page): string
    {
        $strings = [];
        array_walk_recursive($page, function (mixed $value) use (&$strings): void {
            $strings[] = (string) $value;
        });

        return implode("\n", $strings);
    }

    private function stagingContent(): array
    {
        $content = LegalPageFixtures::content('STAGING TEST', ['privacy']);
        $content['legal']['privacy']['sections'][0]['paragraphs'][0] = 'STAGING PLACEHOLDER — not in effect. Effective date: set at production';

        return $content;
    }

    /** @return list<string> */
    private function classes(array $content): array
    {
        return array_column(LegalPublicationGuard::findings($content), 'class');
    }

    public function test_the_guard_admits_staging_placeholders_only_in_local_testing_and_staging(): void
    {
        $content = $this->stagingContent();
        foreach (['local', 'testing', 'staging'] as $environment) {
            $this->environment($environment);
            $this->assertTrue(LegalPublicationGuard::stagingPlaceholderAdmitted(), $environment);
            $this->assertSame([], LegalPublicationGuard::findings($content), $environment);
            LegalPublicationGuard::assertPublishable($content);
        }
    }

    public function test_production_and_ambiguous_environments_refuse_them_with_the_existing_reason_code(): void
    {
        $content = $this->stagingContent();
        $cases = [['production', 'production'], ['preview', 'preview'], ['staging-eu', 'staging-eu'], ['Staging', 'Staging'], ['', ''],
            ['staging', 'production'], ['production', 'staging'], ['testing', 'production'], ['local', 'staging-eu']];
        foreach ($cases as [$configured, $running]) {
            $this->environment($configured, $running);
            $label = $configured.'/'.$running;
            $this->assertFalse(LegalPublicationGuard::stagingPlaceholderAdmitted(), $label);
            $this->assertSame([LegalPublicationGuard::STAGING_PLACEHOLDER, LegalPublicationGuard::STAGING_PLACEHOLDER], $this->classes($content), $label);
            try {
                LegalPublicationGuard::assertPublishable($content);
                $this->fail('A staging placeholder was publishable in '.$label);
            } catch (LegalPublicationRefused $refusal) {
                $message = $refusal->errors()['legal'][0];
                $this->assertStringContainsString('('.LegalPublicationGuard::REASON.')', $message, $label);
                $this->assertStringContainsString('(staging_placeholder)', $message, $label);
            }
        }
    }

    public function test_admission_does_not_hide_any_other_marker(): void
    {
        $this->environment('staging');
        $content = $this->stagingContent();
        $content['legal']['privacy']['sections'][1]['paragraphs'][0] = 'Postal address: [registered postal address — inserted at publication]';
        $content['legal']['privacy']['sections'][1]['heading'] = '15. Disclaimers [For legal review]';
        $this->assertSame([LegalPublicationGuard::LEGAL_REVIEW, LegalPublicationGuard::PUBLICATION_VALUE], array_map(
            fn (array $finding): string => $finding['class'], LegalPublicationGuard::findings($content)));
        $this->expectException(LegalPublicationRefused::class);
        LegalPublicationGuard::assertPublishable($content);
    }

    public function test_the_builder_page_has_no_bracketed_span_and_only_decided_values(): void
    {
        $before = $this->draftHashes();
        $pages = [];
        foreach (StagingLegalRelease::SOURCES as $document => $path) {
            $source = (string) file_get_contents(base_path($path));
            $pages[$document] = StagingLegalRelease::page($document, $source, '2026-11-10');
            $text = $this->text($pages[$document]);
            $this->assertDoesNotMatchRegularExpression('/[\[\]]/', $text, $document.' keeps a square bracket');
            // None of the drafts' own bracketed spans survives, in any form.
            preg_match_all('/\[[^\]\n]+\](?!\()/u', substr($source, 0, (int) strpos($source, '## Reviewer appendix')), $spans);
            $this->assertNotEmpty($spans[0], $document);
            foreach ($spans[0] as $span) {
                $this->assertStringNotContainsString($span, $text, $span);
                $this->assertStringNotContainsString(trim($span, '[]'), $text, $span);
            }
            foreach (['Reviewer appendix', 'reviewer appendix', 'Sean to ', 'inserted at publication', 'set on publication', 'For legal review', 'DMCA designated agent', 'Change log', 'Draft version label'] as $absent) {
                $this->assertStringNotContainsString($absent, $text, $document.' / '.$absent);
            }
            $this->assertSame(StagingLegalRelease::BANNER, $pages[$document]['sections'][0]['heading']);
            $this->assertStringContainsString('Effective date: '.StagingLegalRelease::UNSET, $text);
            $this->assertStringContainsString('Last updated: '.StagingLegalRelease::UNSET, $text);
            $this->assertStringContainsString('Postal address: '.StagingLegalRelease::UNSET, $text);
            // Plain text: no markup characters survive.
            $this->assertDoesNotMatchRegularExpression('/\*\*|`|[<>]/', $text, $document);
        }
        $privacy = $this->text($pages['privacy']);
        foreach (['Laravel Forge', 'DigitalOcean', 'our own server\'s private disk', 'the United States'] as $decided) {
            $this->assertStringContainsString($decided, $privacy);
        }
        $this->assertStringContainsString('Backup storage: set at production', $privacy);
        $this->assertStringContainsString('Email delivery provider: set at production', $privacy);
        $terms = $this->text($pages['terms']);
        $this->assertStringNotContainsString('Cloudflare R2', $privacy.$terms);
        $this->assertStringContainsString('courts located in Polk County, Iowa', $terms);
        $this->assertStringContainsString('Privacy Policy', $terms);
        $this->assertStringContainsString('15. Disclaimers', $terms);
        // A street address, ZIP code or legal name is never written (the drafts hold none; DD-33).
        foreach ([$privacy, $terms] as $text) {
            $this->assertDoesNotMatchRegularExpression('/\b\d{1,5}\s+[A-Z][a-z]+\s+(Street|St|Avenue|Ave|Road|Rd|Drive|Dr|Boulevard|Blvd|Lane|Ln)\b/', $text);
            $this->assertDoesNotMatchRegularExpression('/\b(?:IA|Iowa)\s+\d{5}\b/', $text);
        }
        $this->assertSame($before, $this->draftHashes(), 'docs/legal changed');
    }

    public function test_the_built_content_is_a_valid_schema_that_only_the_staging_placeholders_mark(): void
    {
        $content = SiteContentSchema::forEditing(SiteContentSchema::defaults());
        $content['images'] = SiteContentSchema::NO_IMAGES;
        $content['schema_version'] = SiteContentSchema::LEGAL_VERSION;
        foreach (StagingLegalRelease::SOURCES as $document => $path) {
            $legal[$document] = StagingLegalRelease::page($document, (string) file_get_contents(base_path($path)), '2026-11-10');
        }
        $content['legal'] = $legal + ['licenses' => null];
        SiteContentSchema::validate($content);
        foreach (['local', 'testing', 'staging'] as $environment) {
            $this->environment($environment);
            $this->assertSame([], LegalPublicationGuard::findings($content), $environment);
        }
        $this->environment('production');
        $classes = array_unique($this->classes($content));
        $this->assertSame([LegalPublicationGuard::STAGING_PLACEHOLDER], array_values($classes));
        $this->expectException(LegalPublicationRefused::class);
        LegalPublicationGuard::assertPublishable($content);
    }

    public function test_the_command_saves_a_draft_through_the_site_path_and_never_publishes(): void
    {
        $before = $this->draftHashes();
        $actor = LicenseFixtures::admin();
        $publication = SitePublication::findOrFail(1)->only(['revision', 'active_release_id']);

        $this->artisan('vasey:staging-legal-release', ['--actor' => $actor->email])
            ->expectsQuestion(BuildStagingLegalRelease::PASSWORD_PROMPT, 'password')
            ->expectsOutputToContain('saved. Nothing was published.')
            ->assertExitCode(0);

        $release = SiteRelease::query()->latest('id')->firstOrFail();
        $this->assertStringStartsWith('STAGING legal draft (privacy ', $release->label);
        $this->assertSame(SiteContentSchema::LEGAL_VERSION, $release->schema_version);
        $this->assertSame($actor->id, $release->created_by);
        $legal = $release->content['legal'];
        $this->assertNull($legal['licenses']);
        $text = $this->text($legal);
        $this->assertDoesNotMatchRegularExpression('/[\[\]]/', $text);
        $this->assertStringContainsString(StagingLegalRelease::BANNER, $text);
        $this->assertSame(StagingLegalRelease::BANNER, $legal['privacy']['sections'][0]['heading']);
        $this->assertSame(StagingLegalRelease::BANNER, $legal['terms']['sections'][0]['heading']);
        $this->assertSame([], LegalPublicationGuard::findings($release->content));
        // Staff still publish it; the command did not.
        $this->assertSame($publication, SitePublication::findOrFail(1)->only(['revision', 'active_release_id']));
        $this->assertDatabaseCount('site_publication_revisions', 0);
        $this->get('/legal/privacy')->assertNotFound();

        // Running it again finds the same draft instead of saving a second.
        $this->artisan('vasey:staging-legal-release', ['--actor' => $actor->email])
            ->expectsQuestion(BuildStagingLegalRelease::PASSWORD_PROMPT, 'password')
            ->expectsOutputToContain('already exists as release #'.$release->id)
            ->assertExitCode(0);
        $this->assertSame(1, SiteRelease::count());
        $this->assertSame($before, $this->draftHashes(), 'docs/legal changed');
    }

    public function test_check_writes_nothing_and_needs_no_actor(): void
    {
        $this->assertSame(0, Artisan::call('vasey:staging-legal-release', ['--check' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('Checked only. Nothing was written.', $output);
        $this->assertStringContainsString('page     privacy', $output);
        $this->assertSame(0, SiteRelease::count());
    }

    public function test_production_and_ambiguous_environments_refuse_the_command(): void
    {
        $actor = LicenseFixtures::admin();
        foreach ([['production', 'production'], ['production', 'staging'], ['staging', 'production'], ['staging-eu', 'staging-eu'], ['Staging', 'Staging']] as [$configured, $running]) {
            $this->environment($configured, $running);
            foreach ([['--check' => true], ['--actor' => $actor->email]] as $options) {
                $exit = Artisan::call('vasey:staging-legal-release', $options);
                $this->assertSame(1, $exit, $configured.'/'.$running);
                $this->assertStringContainsString('Refused: Staging legal pages are built only when APP_ENV is local, testing or staging', Artisan::output());
            }
            $this->assertSame(0, SiteRelease::count());
        }
    }

    public function test_it_refuses_a_missing_actor_a_wrong_password_and_a_non_staff_account(): void
    {
        $this->artisan('vasey:staging-legal-release', ['--actor' => 'nobody@example.test'])->expectsOutputToContain('Give --actor=')->assertExitCode(1);
        $actor = LicenseFixtures::admin();
        $this->artisan('vasey:staging-legal-release', ['--actor' => $actor->email])
            ->expectsQuestion(BuildStagingLegalRelease::PASSWORD_PROMPT, 'wrong')
            ->expectsOutputToContain('password was not confirmed')->assertExitCode(1);
        $customer = User::factory()->create();
        $this->artisan('vasey:staging-legal-release', ['--actor' => $customer->email])
            ->expectsQuestion(BuildStagingLegalRelease::PASSWORD_PROMPT, 'password')
            ->expectsOutputToContain('may not administer the catalog')->assertExitCode(1);
        $this->assertSame(0, SiteRelease::count());
    }

    public function test_a_bracket_the_builder_cannot_resolve_stops_the_build(): void
    {
        $source = (string) file_get_contents(base_path('docs/legal/TERMS.md'));
        $source = str_replace('support@vasey.audio · [registered postal address — inserted at publication]', 'support@vasey.audio · [Sean to supply the street address]', $source);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A square bracket is left after substitution');
        StagingLegalRelease::page('terms', $source, '2026-11-10');
    }
}
