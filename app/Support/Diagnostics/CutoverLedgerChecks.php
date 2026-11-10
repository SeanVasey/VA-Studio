<?php

namespace App\Support\Diagnostics;

use App\Domain\Customers\DataRights\DataRightsException;
use App\Domain\Customers\DataRights\DataRightsPolicy;
use App\Domain\Media\MediaFailure;
use App\Domain\Media\PrivateMediaFiles;
use App\Domain\SiteBuilder\LegalPublicationGuard;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Support\Environment\TestEnvironment;
use Composer\InstalledVersions;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The machine-checkable part of the production cleanup ledger (docs/production-cleanup-ledger.md), one row per ledger id.
 *
 * It only reads: configuration already loaded, plain selects, file names, and the bytes of the configured seller tag and
 * signature (to hash them and look for a staging marker). It opens no transaction, takes no lock, writes no row, file,
 * cache entry or log line, and contacts no provider. A row reports ids, states and counts; it never carries a
 * configured value, a path, a key, a name, an address or any text read from a record.
 *
 * `production` is the profile for the production host at cutover: a staging placeholder is a FAIL. `staging` is the
 * profile for local, testing and the staging host: placeholders are the expected state and pass, and only what must
 * never be true off production fails (a live Stripe credential or mode, admitted real mail, an open production lane).
 * Default-off switches whose activation is a separate authorized step are INFO in both profiles: the row states what
 * the switch reads and judges nothing. A PASS here closes no ledger entry and authorizes no activation.
 */
final class CutoverLedgerChecks
{
    public const PRODUCTION = 'production';

    /** The profile is named after the staging environment and spelled by TestEnvironment, the one place that names it. */
    public const STAGING = TestEnvironment::STAGING;

    public const PROFILES = [self::PRODUCTION, self::STAGING];

    public const PASS = 'PASS';

    public const FAIL = 'FAIL';

    public const INFO = 'INFO';

    public const NOT_CHECKABLE = 'NOT_CHECKABLE';

    /**
     * Every ledger id, in ledger order. `check` is the method that inspects it, or null when nothing about the entry can
     * be read from configuration, the database or the filesystem. `manual` is the part of the ledger's "Verified at
     * cutover" column that stays with a person, shortened; the ledger is the authority. A ledger id missing here, or an
     * id here that the ledger no longer has, fails tests/Unit/CutoverCheckLedgerCoverageTest.php.
     *
     * @var array<string, array{subject: string, check: ?string, manual: ?string}>
     */
    public const LEDGER = [
        'PCL-01' => ['subject' => 'Seller tag', 'check' => 'sellerTag',
            'manual' => 'Sean listens to one tagged preview of a real master before the first publication; a master processed on staging under the synthetic tag is reprocessed, not copied.'],
        'PCL-02' => ['subject' => 'Synthetic prices', 'check' => 'syntheticPrices',
            'manual' => 'Sean signs the price list read back from the published offer revisions.'],
        'PCL-03' => ['subject' => 'Synthetic catalog', 'check' => 'syntheticCatalog',
            'manual' => 'The production database was built from migrations and the reviewed import, never from a staging dump.'],
        'PCL-04' => ['subject' => 'Publishing details and exclusive disclosures', 'check' => null,
            'manual' => 'Sean reviews the disclosure of each exclusive offer he publishes; none of these details appears in Git.'],
        'PCL-05' => ['subject' => 'Free-license text', 'check' => 'freeLicenseText',
            'manual' => 'The approved terms hash recorded for production matches the text Sean approved.'],
        'PCL-06' => ['subject' => 'Signature image', 'check' => 'signature',
            'manual' => 'The pinned hash differs from the staging specimen\'s; one rendered golden contract on the production host shows Sean\'s signature and passes the profile check.'],
        'PCL-07' => ['subject' => 'Legal page placeholders', 'check' => 'legalPages',
            'manual' => 'The release\'s approval reference names Sean\'s approval and counsel\'s review (TERMS sections 15 to 17, the no-samples warranty).'],
        'PCL-08' => ['subject' => 'Tax configuration', 'check' => 'taxConfiguration',
            'manual' => 'Stripe\'s tax settings show each registration Sean names; one test-mode rehearsal order shows tax for a registered jurisdiction and none for an unregistered one.'],
        'PCL-09' => ['subject' => 'Logo and icons', 'check' => 'logoSource',
            'manual' => 'The SVG is Sean\'s official mark; every app icon is a full-bleed opaque tile; a physical install on iOS and Android shows the mark.'],
        'PCL-10' => ['subject' => 'Mail', 'check' => 'mail',
            'manual' => 'validate-runtime.php passes with real mail admitted; SPF, DKIM and DMARC resolve for the sender; one enrollment code and one order-ready message reach a mailbox Sean controls and pass DMARC.'],
        'PCL-11' => ['subject' => 'Staff accounts', 'check' => 'staffAccounts',
            'manual' => 'vasey:doctor passes `operator` on production; Sean names the second reviewer; role changes are in the audit log.'],
        'PCL-12' => ['subject' => 'Stripe mode and keys', 'check' => 'stripe',
            'manual' => 'validate-runtime.php passes with live Stripe admitted; the bundle and repository secret scans pass; the first live charge is Sean\'s own, reconciled and refunded by him.'],
        'PCL-13' => ['subject' => 'Test-commerce flags and tables', 'check' => 'testCommerce',
            'manual' => 'The production environment file itself sets none of the flags `true` (this row reads effective configuration).'],
        'PCL-14' => ['subject' => 'Staging basic auth', 'check' => 'basicAuth',
            'manual' => 'An unauthenticated GET / answers 200 on production and 401 on staging; production robots.txt is the public one; the staging credentials were never reused for a production account.'],
        'PCL-15' => ['subject' => 'Off-host backup destination', 'check' => null,
            'manual' => 'A production nightly run ends "shipped" at a destination and key separate from staging; one set fetched back passes restore-check; the restore drill is recorded and shows on the dashboard.'],
        'PCL-16' => ['subject' => 'Per-host policy values', 'check' => 'policyValues',
            'manual' => 'The machine policy validates and its hash is the one Sean approved; the retention, refund, dispute, exclusive and identity-mode values match the decision register line for line.'],
        'PCL-17' => ['subject' => 'Production host facts', 'check' => null,
            'manual' => 'PRIVACY section 5 and section 9 name the real provider and region of the production server.'],
        'PCL-18' => ['subject' => 'Production checkout switches', 'check' => 'productionCheckout',
            'manual' => 'The S2 rehearsal evidence exists for the exact SHA; validate-runtime.php passes for the stage; each flip has its own record and Sean\'s authorization.'],
        'PCL-19' => ['subject' => 'Tax checkout switches', 'check' => 'taxCheckout',
            'manual' => 'As PCL-18, plus the tax evidence of PCL-08.'],
        'PCL-20' => ['subject' => 'Contract v2 gates', 'check' => 'contractV2Gates',
            'manual' => 'The activation record shows zero open name-only provider sessions; a golden contract of each of the six templates renders on the production host; the first paid order issues a v2 contract.'],
        'PCL-21' => ['subject' => 'Pre-launch capability holds', 'check' => 'preLaunchHolds',
            'manual' => 'validate-runtime.php passes with exactly the intended holds lifted; the sitemap lists only published tracks; a free grant issues under the approved text.'],
        'PCL-22' => ['subject' => 'Production free grants', 'check' => 'productionFreeGrants',
            'manual' => 'As PCL-21: flipped with the free-grant hold, by a reviewed change and Sean\'s authorization.'],
        'PCL-23' => ['subject' => 'Memberships (stay off at v1)', 'check' => 'memberships',
            'manual' => 'The /production-memberships/… routes answer 404; BeatStars retirement is blocked while the membership inventory shows an active member.'],
        'PCL-24' => ['subject' => 'Legacy redirects', 'check' => 'legacyRedirects',
            'manual' => 'Every reviewed legacy URL answers its mapped target on the production host; no unresolved row remains unreviewed; Sean authorizes with the DNS change.'],
        'PCL-25' => ['subject' => 'Customer-facing default-off features', 'check' => 'customerFeatures',
            'manual' => 'A real account enrolls, recovers, receives an order-ready message and files a data request on the production host.'],
        'PCL-26' => ['subject' => 'Sweeps and purges', 'check' => 'sweeps',
            'manual' => 'The dashboard shows the sweep\'s last outcome OK if scheduled; no command with a delete or purge mode has run on production without a recorded authorization.'],
        'PCL-27' => ['subject' => 'Operational alerting', 'check' => null,
            'manual' => 'Stopping the scheduler on staging raises an alert within the threshold; the backup and restore-drill signals are green on production before launch.'],
        'PCL-28' => ['subject' => 'BeatStars Studio audit and exports', 'check' => null,
            'manual' => 'The audit checklist has no unperformed item; each deferred family shows no active obligation, or a recorded way it is honored before retirement.'],
        'PCL-29' => ['subject' => 'Dependabot alerts from archived lockfiles', 'check' => null,
            'manual' => 'The alert list shows only alerts from the shipped lockfiles; composer audit and npm audit --audit-level=high pass on the candidate SHA.'],
        'PCL-30' => ['subject' => 'Held tc-lib updates', 'check' => 'contractPackagePins',
            'manual' => 'The held tc-lib update pull requests stay unmerged until after activation.'],
    ];

    /**
     * PCL-13: environment key => config key of every test-only switch. Production must read each as boolean false. The
     * `_TEST_` capabilities repeat scripts/ops/capability-boundary.php; a test compares the two so they cannot drift.
     */
    public const TEST_COMMERCE_FLAGS = [
        'STRIPE_TEST_CHECKOUT_ENABLED' => 'payments.stripe.checkout_enabled',
        'STRIPE_TEST_PAYMENT_PROCESSING_ENABLED' => 'payments.stripe.processing_enabled',
        'STRIPE_TEST_FINALIZATION_ENABLED' => 'payments.stripe.finalization_enabled',
        'VASEY_TEST_CONTRACT_ISSUANCE_ENABLED' => 'contracts.test_issuance_enabled',
        'VASEY_TEST_SERVICE_PROJECTS_ENABLED' => 'services-projects.test_enabled',
        'VASEY_TEST_SERVICE_INVOICING_ENABLED' => 'services-invoicing.test_enabled',
        'VASEY_TEST_FREE_GRANTS_ENABLED' => 'free-grants.test_enabled',
        'VASEY_TEST_CUSTOMER_CONSENT_ENABLED' => 'customer-preferences.test_grants_enabled',
        'VASEY_TEST_UNPAID_RELEASE_ENABLED' => 'unpaid-release.enabled',
        'VASEY_TEST_REFUND_RESOLUTION_ENABLED' => 'refund-resolution.enabled',
        'VASEY_TEST_REFUND_DISPUTE_ENABLED' => 'refund-dispute.enabled',
        'VASEY_TEST_TRANSACTIONAL_NOTIFICATIONS_ENABLED' => 'transactional-notifications.test_enabled',
        'VASEY_TEST_MEMBERSHIPS_ENABLED' => 'memberships.test_mode_enabled',
        'CONTACT_TEST_ORDER_INQUIRIES_ENABLED' => 'inquiries.test_order_inquiries_enabled',
        'VASEY_SUPPORT_ATTACHMENTS_FIXTURE_ENABLED' => 'support-attachments.fixture_enabled',
    ];

    /** PCL-18: label => config key. `mounted` is a literal in config/production_checkout.php, not an environment switch. */
    public const PRODUCTION_CHECKOUT_SWITCHES = [
        'mounted (literal)' => 'production_checkout.mounted',
        'PRODUCTION_CHECKOUT_HTTP_ENABLED' => 'production_checkout.http_enabled',
        'PRODUCTION_CHECKOUT_ENABLED' => 'production_checkout.fresh_checkout_enabled',
        'PRODUCTION_CHECKOUT_RECONCILIATION_ENABLED' => 'production_checkout.reconciliation_enabled',
        'PRODUCTION_CHECKOUT_OPERATOR_RECONCILIATION_ENABLED' => 'production_checkout.operator_reconciliation_enabled',
        'PRODUCTION_CHECKOUT_PROVIDER_IO_ENABLED' => 'production_checkout.provider_io_enabled',
        'PRODUCTION_CHECKOUT_EXEMPTION_AUTHORING_ENABLED' => 'production_checkout.exemption_authoring_enabled',
        'PRODUCTION_CHECKOUT_COMMITTED_READ_RECEIPTS_ENABLED' => 'production_checkout.committed_read_receipts_enabled',
    ];

    /** PCL-19: literals in config/production-tax-checkout.php. */
    public const TAX_CHECKOUT_SWITCHES = [
        'enabled' => 'production-tax-checkout.enabled',
        'http_mounted' => 'production-tax-checkout.http_mounted',
        'committed_read_receipts_enabled' => 'production-tax-checkout.committed_read_receipts_enabled',
    ];

    /** PCL-08: the merchant facts the tax chain is bound to. Reported as a count of bound facts, never as values. */
    public const TAX_MERCHANT_FACTS = ['production-tax-checkout.provider', 'production-tax-checkout.funds_mode',
        'production-tax-checkout.account_id', 'production-tax-checkout.return_origin'];

    /** PCL-20: flipped together at D-30 activation. */
    public const CONTRACT_V2_GATES = [
        'contracts.vasey_license_v2_issuance' => 'contracts.vasey_license_v2_issuance',
        'production_checkout.contract_identity_v2' => 'production_checkout.contract_identity_v2',
        'production-tax-checkout.contract_identity_v2' => 'production-tax-checkout.contract_identity_v2',
    ];

    /** PCL-21: the four flags `PRE_LAUNCH_HOLDS` of ops/production/validate-runtime.php holds false. */
    public const PRE_LAUNCH_HOLDS = [
        'VASEY_OPERATIVE_FREE_GRANTS_ENABLED' => 'free-grants.operative_enabled',
        'CONTACT_INQUIRY_OPERATOR_NOTIFICATIONS_ENABLED' => 'inquiries.operator_notifications_enabled',
        'DISCOVERY_SITEMAP_ENABLED' => 'discovery-sitemap.enabled',
        'VASEY_LISTENING_V2_PROMOTION_ENABLED' => 'customer-listening.v2_promotion_enabled',
    ];

    /** PCL-22: literals in config/production-free-grants.php. */
    public const PRODUCTION_FREE_GRANT_SWITCHES = [
        'enabled' => 'production-free-grants.enabled',
        'http_mounted' => 'production-free-grants.http_mounted',
    ];

    /** PCL-23: every one of these reads false at v1 (DD-50). */
    public const MEMBERSHIP_SWITCHES = [
        'production-memberships.enabled' => 'production-memberships.enabled',
        'production-memberships.authoring_enabled' => 'production-memberships.authoring_enabled',
        'production-memberships.http_mounted' => 'production-memberships.http_mounted',
        'member-grants.enabled' => 'member-grants.enabled',
        'member-grants.authoring_enabled' => 'member-grants.authoring_enabled',
    ];

    /** PCL-23: every one of these reads null at v1. */
    public const MEMBERSHIP_APPROVED_HASHES = ['production-memberships.approved_policy_hash', 'member-grants.approved_definition_hash',
        'member-grants.approved_profile_hash', 'member-grants.approved_original_terms_hash'];

    /** PCL-25: wanted at v1 and enabled per the activation packet, each after its prerequisite. */
    public const CUSTOMER_FEATURE_SWITCHES = [
        'VASEY_CUSTOMER_DATA_RIGHTS_ENABLED' => 'customer-data-rights.enabled',
        'VASEY_TRANSACTIONAL_MAIL_ENABLED' => 'transactional-mail.enabled',
        'CONTACT_INQUIRIES_ENABLED' => 'inquiries.enabled',
        'production customer identity (literal)' => 'production-customer-identity.enabled',
        'REPORTING_CUSTOMER_SEGMENTS_ENABLED' => 'reporting.customer_segments.enabled',
        'REPORTING_CUSTOMER_EXPORT_ENABLED' => 'reporting.customer_segments.export_enabled',
    ];

    /** PCL-25: stays off at v1 (DD-44). */
    public const MARKETING_SEGMENT_SWITCH = 'reporting.customer_segments.marketing_segment_enabled';

    /** PCL-30: the contract profiles production issues under; their `packages` pins must be the installed packages. */
    public const PINNED_CONTRACT_PROFILES = ['vasey-license-v1', 'vasey-license-v2'];

    /** Mail transports that send nothing. */
    private const SILENT_MAILERS = ['log', 'array'];

    private const KNOWN_MAILERS = ['log', 'array', 'smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend', 'failover', 'roundrobin'];

    /** File names read while looking for staging stand-ins on the private disk; past this the scan reports itself incomplete. */
    private const PRIVATE_SCAN_LIMIT = 200000;

    public function __construct(
        private readonly string $stagingBasicAuthFile = CutoverMarkers::STAGING_BASIC_AUTH_FILE,
        private readonly ?string $projectRoot = null,
    ) {}

    /**
     * @return list<array{id: string, subject: string, status: string, findings: list<array{status: string, detail: string}>, manual: ?string}>
     */
    public function run(string $profile): array
    {
        if (! in_array($profile, self::PROFILES, true)) {
            throw new \InvalidArgumentException('Unknown cutover profile.');
        }
        $rows = [];
        foreach (self::LEDGER as $id => $entry) {
            $findings = [];
            if ($entry['check'] !== null) {
                try {
                    $findings = $this->{$entry['check']}($profile === self::PRODUCTION);
                } catch (Throwable $error) {
                    // The class name only: a message can carry a connection string, a path or a row value.
                    $findings = [[self::FAIL, 'the check could not run ('.class_basename($error).')']];
                }
            }
            $rows[] = ['id' => $id, 'subject' => $entry['subject'], 'status' => self::overall($findings),
                'findings' => array_map(fn (array $finding): array => ['status' => $finding[0], 'detail' => $finding[1]], $findings),
                'manual' => $entry['manual']];
        }

        return $rows;
    }

    /** A row fails if any of its findings fails; otherwise it passes if anything was asserted; otherwise it only informs. */
    private static function overall(array $findings): string
    {
        $statuses = array_column($findings, 0);
        foreach ([self::FAIL, self::PASS, self::INFO] as $status) {
            if (in_array($status, $statuses, true)) {
                return $status;
            }
        }

        return self::NOT_CHECKABLE;
    }

    /** @return list<array{0: string, 1: string}> */
    private function sellerTag(bool $production): array
    {
        $path = config('media.tag_path');
        $sha256 = config('media.tag_sha256');
        $configured = is_string($path) && $path !== '';
        $file = null;
        if ($configured) {
            try {
                $file = app(PrivateMediaFiles::class)->resolve($path);
            } catch (MediaFailure) {
                $file = null;
            }
        }
        $marked = $configured && (CutoverMarkers::pathCarriesWord($path, CutoverMarkers::STAGING_SELLER_TAG_PATH_WORDS)
            || ($file !== null && CutoverMarkers::fileCarriesMarker($file, CutoverMarkers::STAGING_SELLER_TAG_MARKER)));
        [$strays, $complete] = $this->stagingFilesOnPrivateDisk();
        $strayText = $strays.' file'.($strays === 1 ? '' : 's').' named '.CutoverMarkers::STAGING_FILE_STEM.'-* in private storage';
        if (! $production) {
            return [[self::PASS, (! $configured ? 'no seller tag is configured' : ($marked ? 'the staging synthetic tag is configured' : 'a tag with no staging marker is configured')).'; '.$strayText]];
        }
        if (! $configured) {
            $findings = [[self::FAIL, 'no seller tag is configured']];
        } elseif ($marked) {
            $findings = [[self::FAIL, 'the configured seller tag is a staging or synthetic file (by its path or its content marker)']];
        } elseif ($file === null) {
            $findings = [[self::FAIL, 'the configured seller tag file is not in private storage']];
        } elseif (! is_string($sha256) || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1 || ! hash_equals($sha256, (string) hash_file('sha256', $file))) {
            $findings = [[self::FAIL, 'the configured seller tag file does not match its pinned SHA-256']];
        } else {
            $findings = [[self::PASS, 'the configured seller tag carries no staging marker and matches its pinned SHA-256']];
        }
        $findings[] = ! $complete ? [self::FAIL, 'the private storage file-name scan was incomplete; look for '.CutoverMarkers::STAGING_FILE_STEM.'-* files by hand']
            : [$strays === 0 ? self::PASS : self::FAIL, $strayText];

        return $findings;
    }

    private function syntheticPrices(bool $production): array
    {
        $licenses = $this->syntheticLicenses(DB::table('license_templates'))->count();
        $joined = fn (string $table): int => DB::table($table)
            ->join('license_versions', 'license_versions.id', '=', $table.'.license_version_id')
            ->join('license_templates', 'license_templates.id', '=', 'license_versions.license_template_id')
            ->join('tracks', 'tracks.id', '=', $table.'.track_id')
            ->where(fn (Builder $query) => $this->syntheticTracks($this->syntheticLicenses($query), 'or'))->count();
        $offers = $joined('offers');
        $revisions = $joined('offer_revisions');
        $text = "synthetic or TEST PRICE markers: {$licenses} license template(s), {$offers} offer(s), {$revisions} offer revision(s)";

        return [[$production && $licenses + $offers + $revisions > 0 ? self::FAIL : self::PASS, $text]];
    }

    private function syntheticCatalog(bool $production): array
    {
        $flag = config('staging-synthetic-catalog.enabled');
        $tracks = $this->syntheticTracks(DB::table('tracks'))->count();
        $declarations = DB::table('rights_declarations')->where('provenance_reference', 'like', CutoverMarkers::SYNTHETIC_REFERENCE_PREFIX.'%')->count();

        return [
            [$production && $flag !== false ? self::FAIL : self::PASS, 'VASEY_STAGING_SYNTHETIC_CATALOG_ENABLED reads '.self::state($flag)],
            [$production && $tracks + $declarations > 0 ? self::FAIL : self::PASS, "{$tracks} synthetic rehearsal track(s), {$declarations} synthetic rights declaration(s)"],
        ];
    }

    private function freeLicenseText(bool $production): array
    {
        $total = $marked = $unreadable = 0;
        $tables = ['free_definitions' => 'payload'];
        if (Schema::hasTable('production_free_definitions')) {
            $tables['production_free_definitions'] = 'payload_ciphertext';
        }
        foreach ($tables as $table => $column) {
            foreach (DB::table($table)->orderBy('id')->pluck($column) as $ciphertext) {
                $total++;
                try {
                    $plain = Crypt::decryptString((string) $ciphertext);
                } catch (Throwable) {
                    $unreadable++;

                    continue;
                }
                // The canonical JSON escapes nothing these markers contain, so the stored text is searched as it stands.
                if (CutoverMarkers::textCarriesStagingMarker($plain)) {
                    $marked++;
                }
            }
        }
        $text = "{$total} free definition(s): {$marked} with a staging or synthetic marker, {$unreadable} unreadable";

        return [[$production && $marked + $unreadable > 0 ? self::FAIL : self::PASS, $text]];
    }

    private function signature(bool $production): array
    {
        $relative = config('contracts.vasey_license_v1.signature_path');
        $sha256 = config('contracts.vasey_license_v1.signature_sha256');
        $configured = is_string($relative) && $relative !== '';
        $file = null;
        if ($configured && preg_match('#\A(?:[A-Za-z0-9_-][A-Za-z0-9._-]*/)*[A-Za-z0-9_-][A-Za-z0-9._-]*\z#', $relative) === 1) {
            $candidate = rtrim(Storage::disk('local')->path(''), '/').'/'.$relative;
            $file = is_file($candidate) && ! is_link($candidate) ? $candidate : null;
        }
        $marked = $configured && (CutoverMarkers::pathCarriesWord($relative, CutoverMarkers::SPECIMEN_SIGNATURE_PATH_WORDS)
            || ($file !== null && CutoverMarkers::fileCarriesMarker($file, CutoverMarkers::SPECIMEN_SIGNATURE_MARKER)));
        if (! $production) {
            return [[self::PASS, ! $configured ? 'no signature image is configured' : ($marked ? 'the staging specimen is configured' : 'an image with no specimen marker is configured')]];
        }
        if (! $configured) {
            return [[self::FAIL, 'no signature image is configured']];
        }
        if ($marked) {
            return [[self::FAIL, 'the configured signature is a staging specimen (by its path or its content marker)']];
        }
        if ($file === null) {
            return [[self::FAIL, 'the configured signature file is not in private storage']];
        }
        if (! is_string($sha256) || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1 || ! hash_equals($sha256, (string) hash_file('sha256', $file))) {
            return [[self::FAIL, 'the configured signature file does not match its pinned SHA-256']];
        }

        return [[self::PASS, 'the configured signature carries no specimen marker by path or content and matches its pinned SHA-256 (the image itself is not read as a picture)']];
    }

    private function legalPages(bool $production): array
    {
        $releaseId = DB::table('site_publications')->where('id', 1)->value('active_release_id');
        $content = $releaseId === null ? null : json_decode((string) DB::table('site_releases')->where('id', $releaseId)->value('content'), true);
        $findings = [];
        if (! is_array($content)) {
            $findings[] = [$production ? self::FAIL : self::PASS, 'no readable site release is published, so no legal page is public'];
        } else {
            $legal = is_array($content['legal'] ?? null) ? $content['legal'] : [];
            $missing = array_values(array_filter(['privacy', 'terms'], fn (string $document): bool => ! is_array($legal[$document] ?? null)));
            if ($missing !== []) {
                $findings[] = [$production ? self::FAIL : self::PASS, 'the published release has no '.implode(' or ', $missing).' page'];
            }
            $guard = LegalPublicationGuard::summary(LegalPublicationGuard::findings($content));
            $staging = [];
            foreach (SiteContentSchema::LEGAL_DOCUMENTS as $document) {
                $count = is_array($legal[$document] ?? null) ? $this->stagingTextCount(Arr::except($legal[$document], ['approval_reference'])) : 0;
                if ($count > 0) {
                    $staging[] = $document.'='.$count;
                }
            }
            $text = 'published legal pages: '.array_sum($guard).' unresolved marker(s)'
                .($guard === [] ? '' : ' ('.implode(', ', array_map(fn (string $key, int $count): string => $key.'='.$count, array_keys($guard), $guard)).')')
                .', '.count($staging).' page(s) with STAGING or "set at production" text'.($staging === [] ? '' : ' ('.implode(', ', $staging).')')
                .(is_array($legal['licenses'] ?? null) ? '' : '; no /legal/licenses page in the release');
            $findings[] = [$production && ($guard !== [] || $staging !== []) ? self::FAIL : self::PASS, $text];
        }
        $notice = config('inquiries.privacy_notice');
        if (is_string($notice) && $notice !== '') {
            $marked = CutoverMarkers::textCarriesStagingMarker($notice) || preg_match('/[\[\]]/', $notice) === 1;
            $findings[] = [$production && $marked ? self::FAIL : self::PASS,
                'the contact-form privacy notice '.($marked ? 'carries a staging marker or a square bracket' : 'carries no staging marker')];
        } else {
            $findings[] = [self::INFO, 'no contact-form privacy notice is configured'];
        }

        return $findings;
    }

    private function taxConfiguration(bool $production): array
    {
        $bound = count(array_filter(self::TAX_MERCHANT_FACTS, fn (string $key): bool => config($key) !== null && config($key) !== ''));

        return [[self::INFO, $bound.' of '.count(self::TAX_MERCHANT_FACTS).' tax merchant facts are bound (provider, funds mode, account, return origin)']];
    }

    private function logoSource(bool $production): array
    {
        $directory = $this->root().'/assets';
        $count = 0;
        if (is_dir($directory)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $count += strtolower($file->getExtension()) === 'svg' ? 1 : 0;
            }
        }

        return [[$production && $count === 0 ? self::FAIL : self::PASS, $count.' SVG source file(s) under assets/ (not verified as the official mark)']];
    }

    private function mail(bool $production): array
    {
        $mailer = config('mail.default');
        $kind = in_array($mailer, self::KNOWN_MAILERS, true) ? $mailer : 'other';
        $silent = ! is_string($mailer) || $mailer === '' || in_array($mailer, self::SILENT_MAILERS, true);
        $admitted = config('production-runtime.real_mail_admitted');
        $switches = [self::INFO, 'real mail admission reads '.self::state($admitted).'; identity SMTP literal reads '
            .self::state(config('production-identity-smtp.enabled')).'; VASEY_TRANSACTIONAL_MAIL_ENABLED reads '.self::state(config('transactional-mail.enabled'))];
        if ($production) {
            return [[$silent ? self::FAIL : self::PASS, 'mail transport: '.$kind.($silent ? ' (nothing is sent)' : '')], $switches];
        }

        return [[$admitted === false ? self::PASS : self::FAIL, 'mail transport: '.$kind.'; real mail is '.($admitted === false ? 'not admitted' : 'admitted off production')], $switches];
    }

    private function staffAccounts(bool $production): array
    {
        $synthetic = DB::table('users')->where('name', 'like', CutoverMarkers::SYNTHETIC_STAFF_NAME_PREFIX.'%')->count();
        $admins = DB::table('users')->where('is_admin', true)->count();
        $unenrolled = DB::table('users')->where('is_admin', true)->whereNull('app_authentication_secret')->count();
        $findings = [[$production && $synthetic > 0 ? self::FAIL : self::PASS, "{$synthetic} account(s) whose name starts with \"".trim(CutoverMarkers::SYNTHETIC_STAFF_NAME_PREFIX).' "']];
        $findings[] = [$production && ($admins === 0 || $unenrolled > 0) ? self::FAIL : self::PASS, "{$admins} administrator(s), {$unenrolled} without an enrolled authenticator"];

        return $findings;
    }

    private function stripe(bool $production): array
    {
        $live = $test = [];
        foreach (Arr::dot(config()->all()) as $key => $value) {
            $mode = self::stripeKeyMode($value);
            if ($mode === 'live') {
                $live[] = (string) $key;
            } elseif ($mode === 'test') {
                $test[] = (string) $key;
            }
        }
        $mode = config('payments.stripe.mode');
        $modeText = in_array($mode, ['test', 'live'], true) ? $mode : 'other';
        $admitted = config('production-runtime.live_stripe_admitted');
        $info = [self::INFO, 'STRIPE_MODE reads '.$modeText.'; live Stripe admission reads '.self::state($admitted).'; production checkout key slot: '
            .(self::stripeKeyMode(config('production_checkout.secret_key')) ?? 'no recognised key')];
        if (! $production) {
            $clean = $live === [] && $mode === 'test' && $admitted === false;

            return [[$clean ? self::PASS : self::FAIL, count($live).' configured value(s) with a live Stripe key prefix; mode '.$modeText
                .'; live admission '.self::state($admitted)], $info];
        }
        $findings = [[$test === [] ? self::PASS : self::FAIL, count($test).' configured value(s) with a Stripe test key prefix (key prefixes only: no key, or STRIPE_MODE=test with no key, passes until the S3 live-key step)']];
        $misplaced = array_values(array_diff($live, ['production_checkout.secret_key']));
        if ($misplaced !== []) {
            $findings[] = [self::FAIL, count($misplaced).' live-prefixed value(s) outside the one admitted slot (PRODUCTION_CHECKOUT_STRIPE_SECRET_KEY)'];
        }
        if ($live !== [] && $admitted !== true) {
            $findings[] = [self::FAIL, 'a live-prefixed key is configured while live Stripe is not admitted'];
        }
        $findings[] = $info;

        return $findings;
    }

    private function testCommerce(bool $production): array
    {
        $on = self::notFalse(self::TEST_COMMERCE_FLAGS);
        $tables = [];
        foreach (Schema::getTables() as $table) {
            $name = (string) ($table['name'] ?? '');
            if (str_starts_with($name, 'test_') && DB::table($name)->exists()) {
                $tables[] = $name;
            }
        }
        sort($tables);

        return [
            [$production && $on !== [] ? self::FAIL : self::PASS, count($on).' test-commerce flag(s) not false'.($on === [] ? '' : ': '.implode(', ', $on))],
            [$production && $tables !== [] ? self::FAIL : self::PASS, count($tables).' test_* table(s) hold rows'.($tables === [] ? '' : ': '.implode(', ', $tables))],
        ];
    }

    private function basicAuth(bool $production): array
    {
        $present = file_exists($this->stagingBasicAuthFile) || is_link($this->stagingBasicAuthFile);
        $text = 'the staging basic-auth file is '.($present ? 'present' : 'absent').' on this host';
        if ($production) {
            return [[$present ? self::FAIL : self::PASS, $text]];
        }

        // Off the staging host (local, testing) there is no web server to protect, so absence is only reported.
        return [[$present ? self::PASS : (TestEnvironment::isStaging(config('app.env')) ? self::FAIL : self::INFO), $text]];
    }

    private function policyValues(bool $production): array
    {
        try {
            app(DataRightsPolicy::class)->retention();
            $set = true;
        } catch (DataRightsException) {
            $set = false;
        }

        return [[$production && ! $set ? self::FAIL : self::PASS, 'the retention schedule (reference and seven periods) is '.($set ? 'configured and valid' : 'not fully configured')]];
    }

    private function productionCheckout(bool $production): array
    {
        return [$this->productionLane('production checkout', self::PRODUCTION_CHECKOUT_SWITCHES, $production)];
    }

    private function taxCheckout(bool $production): array
    {
        return [$this->productionLane('tax checkout', self::TAX_CHECKOUT_SWITCHES, $production)];
    }

    private function contractV2Gates(bool $production): array
    {
        $on = self::notFalse(self::CONTRACT_V2_GATES);
        if ($on !== [] && count($on) !== count(self::CONTRACT_V2_GATES)) {
            return [[self::FAIL, 'the three contract v2 gates disagree (they are flipped together); not false: '.implode(', ', $on)]];
        }

        return [[self::INFO, $on === [] ? 'all three contract v2 gates read false' : 'all three contract v2 gates are on']];
    }

    private function preLaunchHolds(bool $production): array
    {
        $lifted = self::notFalse(self::PRE_LAUNCH_HOLDS);

        return [[self::INFO, $lifted === [] ? 'all four pre-launch holds read false' : count($lifted).' of four pre-launch holds not false: '.implode(', ', $lifted)]];
    }

    private function productionFreeGrants(bool $production): array
    {
        $bound = count(array_filter(['production-free-grants.provenance', 'production-free-grants.storage_root'], fn (string $key): bool => config($key) !== null));

        return [$this->productionLane('production free grants', self::PRODUCTION_FREE_GRANT_SWITCHES, $production),
            [self::INFO, $bound.' of 2 production free-grant bindings set (provenance, storage root)']];
    }

    private function memberships(bool $production): array
    {
        $on = self::notFalse(self::MEMBERSHIP_SWITCHES);
        $hashes = array_values(array_filter(self::MEMBERSHIP_APPROVED_HASHES, fn (string $key): bool => config($key) !== null));
        if ($on === [] && $hashes === []) {
            return [[self::PASS, 'every membership switch reads false and every approved hash reads null']];
        }

        return [[self::FAIL, 'memberships stay off at v1; not false: '.($on === [] ? 'none' : implode(', ', $on)).'; approved hash set: '.($hashes === [] ? 'none' : implode(', ', $hashes))]];
    }

    private function legacyRedirects(bool $production): array
    {
        $text = 'legacy redirects enabled reads '.self::state(config('legacy-redirects.enabled'));
        $path = config('legacy-redirects.map_path');
        $map = is_string($path) && is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (is_array($map) && is_array($map['entries'] ?? null)) {
            $counts = array_count_values(array_map(fn (mixed $entry): string => is_array($entry) && is_string($entry['disposition'] ?? null)
                && preg_match('/\A[a-z_-]{1,32}\z/', $entry['disposition']) === 1 ? $entry['disposition'] : 'other', $map['entries']));
            ksort($counts);
            $text .= '; map rows: '.implode(', ', array_map(fn (string $disposition, int $count): string => $disposition.'='.$count, array_keys($counts), $counts));
        } else {
            $text .= '; the redirect map could not be read';
        }

        return [[self::INFO, $text]];
    }

    private function customerFeatures(bool $production): array
    {
        $on = self::notFalse(self::CUSTOMER_FEATURE_SWITCHES);
        $marketing = config(self::MARKETING_SEGMENT_SWITCH);

        return [
            [self::INFO, count($on).' of '.count(self::CUSTOMER_FEATURE_SWITCHES).' customer-facing switches not false'.($on === [] ? '' : ': '.implode(', ', $on))],
            [$marketing === false ? self::PASS : ($production ? self::FAIL : self::INFO), 'REPORTING_MARKETING_SEGMENT_ENABLED (stays off at v1) reads '.self::state($marketing)],
        ];
    }

    private function sweeps(bool $production): array
    {
        return [[self::INFO, 'the upload-session sweep schedule literal reads '.self::state(config('media.upload_session_sweep.scheduled'))]];
    }

    private function contractPackagePins(bool $production): array
    {
        $checked = 0;
        $changed = [];
        foreach (self::PINNED_CONTRACT_PROFILES as $profile) {
            $assets = json_decode((string) @file_get_contents($this->root().'/resources/contracts/'.$profile.'/profile-assets.json'), true);
            if (! is_array($assets) || ! is_array($assets['packages'] ?? null) || $assets['packages'] === []) {
                $changed[] = $profile.' (pins unreadable)';

                continue;
            }
            foreach ($assets['packages'] as $name => $identity) {
                $checked++;
                if (! is_string($name) || ! is_array($identity) || ! InstalledVersions::isInstalled($name)
                    || ltrim((string) InstalledVersions::getPrettyVersion($name), 'v') !== ($identity['version'] ?? null)
                    || InstalledVersions::getReference($name) !== ($identity['reference'] ?? null)) {
                    $changed[] = $profile.': '.(is_string($name) && preg_match('#\A[a-z0-9._/-]{1,100}\z#', $name) === 1 ? $name : 'a package');
                }
            }
        }

        return [[$changed === [] ? self::PASS : self::FAIL, $checked.' package pin(s) of '.implode(' and ', self::PINNED_CONTRACT_PROFILES)
            .' compared with the installed packages'.($changed === [] ? ': all match' : '; differing: '.implode(', ', $changed))]];
    }

    /** A production lane must not be open off production (the capability boundary); on production its state is only reported. */
    private function productionLane(string $label, array $switches, bool $production): array
    {
        $on = self::notFalse($switches);
        $text = $on === [] ? "every {$label} switch reads false" : "{$label} switches not false: ".implode(', ', $on);

        return [$production ? self::INFO : ($on === [] ? self::PASS : self::FAIL), $text];
    }

    /**
     * Labels of the switches that are not exactly boolean false. Strict on purpose, as the capability boundary is: a
     * blank or "yes" value is not off, and a renamed config key reads null and is reported instead of passing.
     *
     * @param  array<string, string>  $switches  label => config key
     * @return list<string>
     */
    private static function notFalse(array $switches): array
    {
        return array_keys(array_filter($switches, fn (string $key): bool => config($key) !== false));
    }

    /** A switch value as a word. Anything that is not a boolean or null is "another value": the value itself is not printed. */
    private static function state(mixed $value): string
    {
        return match (true) {
            $value === false => 'false',
            $value === true => 'true',
            $value === null => 'null',
            default => 'another value',
        };
    }

    /** Mode from the key prefix only. The key is never kept, hashed, compared with anything else or reported. */
    private static function stripeKeyMode(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/\A(?:sk|rk|pk)_(live|test)_/', $value, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    private function syntheticLicenses(Builder $query, string $boolean = 'and'): Builder
    {
        return $query->where(fn (Builder $nested) => $nested
            ->where('license_templates.name', 'like', '%'.CutoverMarkers::SYNTHETIC_TITLE.'%')
            ->orWhere('license_templates.name', 'like', '%'.CutoverMarkers::TEST_PRICE.'%')
            ->orWhere('license_templates.slug', 'like', CutoverMarkers::SYNTHETIC_SLUG_PREFIX.'%'), null, null, $boolean);
    }

    private function syntheticTracks(Builder $query, string $boolean = 'and'): Builder
    {
        return $query->where(fn (Builder $nested) => $nested
            ->where('tracks.slug', 'like', CutoverMarkers::SYNTHETIC_SLUG_PREFIX.'%')
            ->orWhere('tracks.title', 'like', '%'.CutoverMarkers::SYNTHETIC_TITLE.'%')
            ->orWhere('tracks.artist', '=', CutoverMarkers::SYNTHETIC_ARTIST), null, null, $boolean);
    }

    /** Strings anywhere in one legal page that carry the STAGING marker or "set at production". */
    private function stagingTextCount(array $page): int
    {
        $count = 0;
        array_walk_recursive($page, function (mixed $value) use (&$count): void {
            if (is_string($value) && (str_contains($value, CutoverMarkers::STAGING_TEXT) || stripos($value, CutoverMarkers::SET_AT_PRODUCTION) !== false)) {
                $count++;
            }
        });

        return $count;
    }

    /**
     * File names only; no file is opened. Links are not followed.
     *
     * @return array{0: int, 1: bool} matching files, and whether the whole disk was read
     */
    private function stagingFilesOnPrivateDisk(): array
    {
        $root = rtrim(Storage::disk('local')->path(''), '/');
        if (! is_dir($root)) {
            return [0, true];
        }
        $matches = $seen = 0;
        try {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (++$seen > self::PRIVATE_SCAN_LIMIT) {
                    return [$matches, false];
                }
                $matches += stripos($file->getFilename(), CutoverMarkers::STAGING_FILE_STEM) !== false ? 1 : 0;
            }
        } catch (\UnexpectedValueException) {
            return [$matches, false];
        }

        return [$matches, true];
    }

    private function root(): string
    {
        return rtrim($this->projectRoot ?? base_path(), '/');
    }
}
