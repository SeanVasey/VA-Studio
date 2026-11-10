<?php

namespace App\Console\Commands;

use App\Domain\SiteBuilder\LegalPublicationGuard;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Domain\SiteBuilder\StagingLegalRelease;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Builds a private DRAFT site release carrying the privacy and terms pages for the STAGING rehearsal (DD-33, ledger PCL-07)
 * from docs/legal/PRIVACY.md and TERMS.md, through the same SiteContent::create path as the staff editor. It never publishes:
 * previewing and publishing stay the staff action. Local, testing and staging only; production refuses the command, and the
 * legal publication guard would refuse the result there anyway.
 */
final class BuildStagingLegalRelease extends Command
{
    public const PASSWORD_PROMPT = 'Staff password (hidden)';

    protected $signature = 'vasey:staging-legal-release
        {--actor= : Email of the staff account that saves the draft}
        {--check : Build and validate the content, print the plan and write nothing}';

    protected $description = 'Build a private draft site release with the STAGING privacy and terms pages (local, testing or staging only; never publishes).';

    public function handle(SiteContent $site): int
    {
        try {
            return $this->build($site);
        } catch (Throwable $error) {
            // No message, path or page text leaves the console; the log keeps only the exception class.
            Log::warning('Staging legal release could not be built.', ['exception_class' => $error::class]);
            $this->error('Stopped: '.class_basename($error).'. Nothing was published.');

            return self::FAILURE;
        }
    }

    private function build(SiteContent $site): int
    {
        if (! StagingLegalRelease::admitted()) {
            return $this->refuse('Staging legal pages are built only when APP_ENV is local, testing or staging and matches the running environment. Production publishes only approved final text.');
        }
        $check = (bool) $this->option('check');
        $actor = null;
        if (! $check) {
            $actor = User::query()->where('email', (string) $this->option('actor'))->first();
            if ($actor === null) {
                return $this->refuse('Give --actor=<email of an existing staff account>.');
            }
            if (! $this->input->isInteractive()) {
                return $this->refuse('Saving the draft requires an interactive session (not --no-interaction) to confirm the staff password at a hidden prompt.');
            }
            if (! $this->confirmed($actor)) {
                return $this->refuse('Staff authority was refused: the password was not confirmed or the account may not administer the catalog.');
            }
        }

        $now = now('UTC');
        $pages = [];
        $hashes = [];
        foreach (StagingLegalRelease::SOURCES as $document => $path) {
            $markdown = @file_get_contents(base_path($path));
            if (! is_string($markdown) || $markdown === '') {
                return $this->refuse('Cannot read '.$path.'.');
            }
            $hashes[$document] = substr(hash('sha256', $markdown), 0, 8);
            $pages[$document] = StagingLegalRelease::page($document, $markdown, $now->format('Y-m-d'));
        }
        $label = 'STAGING legal draft (privacy '.$hashes['privacy'].', terms '.$hashes['terms'].')';
        $content = $this->content($site, $pages);
        SiteContentSchema::validate($content);
        // Only the staging stand-ins may remain; any other marker means the builder resolved something wrongly.
        $others = LegalPublicationGuard::findings($content);
        if ($others !== []) {
            return $this->refuse('The built pages still hold '.count($others).' unresolved marker(s) other than the staging placeholders ('.json_encode(LegalPublicationGuard::summary($others)).').');
        }
        $this->line('label    '.$label);
        foreach ($pages as $document => $page) {
            $paragraphs = array_sum(array_map(fn (array $section): int => count($section['paragraphs']), $page['sections']));
            $this->line(sprintf('page     %-8s %d sections, %d paragraphs, source sha256 %s', $document, count($page['sections']), $paragraphs, $hashes[$document]));
        }
        if ($check) {
            $this->line('Checked only. Nothing was written.');

            return self::SUCCESS;
        }
        $existing = SiteRelease::query()->where('label', $label)->first();
        if ($existing !== null) {
            $this->line('A draft with this label already exists as release #'.$existing->id.'. Nothing was written.');

            return self::SUCCESS;
        }
        try {
            $release = $site->create($content, $label, $actor);
        } catch (ValidationException $exception) {
            return $this->refuse('a domain guard refused: '.implode(' ', $exception->validator->errors()->all()));
        } catch (AuthorizationException) {
            return $this->refuse('staff authorization refused.');
        }
        $this->line('DRAFT release #'.$release->id.' saved. Nothing was published.');
        $this->line('Preview it under Admin → Publishing → Site content, then publish it there when the staging pages are wanted.');

        return self::SUCCESS;
    }

    /**
     * The active release's content with the two legal pages replaced, so nothing else on the site changes when staff publish the
     * draft. A hand-typed license comparison is dropped (it is refused at publication); a generated one keeps its frame.
     *
     * @param  array<string, array<string, mixed>>  $pages
     * @return array<string, mixed>
     */
    private function content(SiteContent $site, array $pages): array
    {
        $content = SiteContentSchema::forEditing($site->current());
        $legal = is_array($content['legal'] ?? null) ? $content['legal'] : SiteContentSchema::NO_LEGAL;
        $licenses = is_array($legal['licenses'] ?? null) && ! array_key_exists('sections', $legal['licenses'])
            ? array_intersect_key($legal['licenses'], ['title' => 1, 'description' => 1]) : null;
        $legal = ['privacy' => $pages['privacy'], 'terms' => $pages['terms'], 'licenses' => $licenses];
        foreach (['blog', 'videos'] as $section) {
            foreach ($content[$section]['entries'] ?? [] as $index => $entry) {
                $content[$section]['entries'][$index]['related_track_ids'] = $entry['related_track_ids'] ?? [];
            }
        }
        $content['images'] = $content['images'] ?? SiteContentSchema::NO_IMAGES;
        $content['legal'] = $legal;
        $content['schema_version'] = $licenses !== null ? SiteContentSchema::GENERATED_LICENSES_VERSION : SiteContentSchema::LEGAL_VERSION;

        return $content;
    }

    /** As `vasey:staging-synthetic-catalog` confirms an account: hidden input, current password hash and current authority. */
    private function confirmed(User $account): bool
    {
        $password = $this->secret(self::PASSWORD_PROMPT, false);
        $current = User::query()->find($account->getKey());
        $valid = $current !== null && is_string($password) && $password !== '' && Hash::check($password, $current->getAuthPassword());
        unset($password);

        return $valid && Gate::forUser($current)->allows('administer-catalog');
    }

    private function refuse(string $message): int
    {
        $this->error('Refused: '.$message.' Nothing was written.');

        return self::FAILURE;
    }
}
