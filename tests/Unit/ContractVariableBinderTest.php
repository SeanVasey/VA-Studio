<?php

namespace Tests\Unit;

use App\Domain\Commerce\ContractIdentity\ContractGlyphCoverage;
use App\Domain\Contracts\Production\ContractBindingException;
use App\Domain\Contracts\Production\ContractLicenseConstants;
use App\Domain\Contracts\Production\ContractNumberWords;
use App\Domain\Contracts\Production\ContractVariableBinder;
use App\Domain\Grants\Paid\PaidGrantRenderInput;
use App\Domain\Grants\PaidTax\PaidTaxRenderInput;
use App\Domain\Rights\LicenseDisclosure;
use App\Domain\Rights\TieredLicenseTerms;
use App\Support\CanonicalJson;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EconomicLicenseFixtures;
use Tests\Support\TieredLicenseFixtures;
use Tests\TestCase;

/**
 * SYNTHETIC, NONBINDING fixtures only: invented buyers, tracks, prices, limits and constants. None of them is a
 * production value, a seller default or contract wording.
 */
class ContractVariableBinderTest extends TestCase
{
    private const INDIVIDUAL_STATEMENT = ['statement_version' => 'individual-legal-name-confirmation-v1', 'statement_hash' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'];

    private const BUSINESS_STATEMENT = ['statement_version' => 'business-legal-name-confirmation-v1', 'statement_hash' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'];

    private static ?ContractGlyphCoverage $coverage = null;

    private static function coverage(): ContractGlyphCoverage
    {
        $fonts = dirname(__DIR__, 2).'/resources/contracts/test-v2/fonts/generated/';

        return self::$coverage ??= ContractGlyphCoverage::fromTcpdfMetrics([$fonts.'dejavusans.json', $fonts.'dejavusansb.json']);
    }

    private static function constants(): ContractLicenseConstants
    {
        return new ContractLicenseConstants('Synthetic Producer Alias', 'Synthetic State, Synthetic Country');
    }

    /** Synthetic schema 5 terms with no prohibited use, so every usage placeholder has a value. */
    private static function terms(array $overrides = []): array
    {
        $terms = TieredLicenseFixtures::terms();
        $terms['required_asset_roles'] = ['stems_zip', 'download_mp3', 'master_wav'];
        $terms['usage']['radio_stations'] = ['mode' => 'limited', 'limit' => 2];
        $terms['usage']['for_profit_performances'] = ['mode' => 'limited', 'limit' => 21];
        $terms['usage']['music_videos'] = ['mode' => 'limited', 'limit' => 1];
        foreach ($overrides as $path => $value) {
            Arr::set($terms, $path, $value);
        }

        return $terms;
    }

    private static function individual(): array
    {
        return ['schema' => 'buyer-contract-identity-v2', 'licensee_type' => 'individual', 'legal_name' => 'Zoë Example Buyer',
            'representative_name' => null, 'legal_name_confirmation' => ['confirmed' => true, ...self::INDIVIDUAL_STATEMENT],
            'postal_address' => ['country' => 'US', 'lines' => ['1 Example Street', 'Suite 2'], 'locality' => 'Springfield', 'region' => 'IA', 'postalCode' => '50000-1234'],
            'artist_name' => null, 'identity_meaning' => 'buyer_declared_contract_identity'];
    }

    private static function business(): array
    {
        return ['schema' => 'buyer-contract-identity-v2', 'licensee_type' => 'business', 'legal_name' => 'Example Records Ltd',
            'representative_name' => 'Ada Example', 'legal_name_confirmation' => ['confirmed' => true, ...self::BUSINESS_STATEMENT],
            'postal_address' => ['country' => 'GB', 'lines' => ['2 Sample Road'], 'locality' => 'London', 'postalCode' => 'SW1A 1AA'],
            'artist_name' => null, 'identity_meaning' => 'buyer_declared_contract_identity'];
    }

    private static function exclusiveBuyer(): array
    {
        return array_replace(self::individual(), ['legal_name' => 'Émile Example', 'artist_name' => 'MC Example',
            'postal_address' => ['country' => 'CA', 'lines' => ['3 Test Avenue'], 'locality' => 'Toronto', 'region' => 'ON']]);
    }

    private static function license(array $terms, string $type = 'non-exclusive', string $name = 'Synthetic Premium Lease'): array
    {
        return ['id' => 41, 'template_id' => 7, 'name' => $name, 'version' => 3, 'type' => $type,
            'authored_source' => ($terms['schema_version'] ?? null) === 4 ? EconomicLicenseFixtures::source() : TieredLicenseFixtures::source(), 'source_hash' => str_repeat('1', 64), 'model_hash' => str_repeat('2', 64),
            'structured_terms' => $terms, 'features' => ['Synthetic feature'], 'required_asset_roles' => $terms['required_asset_roles'],
            'renderer_version' => 'vasey-license-review-html-v5', 'render_fixture_hash' => str_repeat('3', 64), 'submission_hash' => str_repeat('4', 64),
            'review_evidence_id' => 9, 'review_evidence_hash' => str_repeat('5', 64), 'approval_reference' => 'SYNTHETIC', 'approved_by' => 1,
            'approved_at' => '2026-11-01T00:00:00.000000Z', 'effective_from' => null, 'effective_until' => null];
    }

    /** The real disclosure; terms that no validator accepts (a relabelled schema) get a hand-built one instead. */
    private static function disclosure(array $license): array
    {
        try {
            return app(LicenseDisclosure::class)->fromSnapshot($license);
        } catch (ValidationException) {
            return ['licenseVersionId' => (string) $license['id'], 'name' => $license['name'], 'version' => $license['version'], 'type' => $license['type'],
                'features' => $license['features'], 'deliverableRoles' => $license['required_asset_roles'], 'termsText' => 'SYNTHETIC'];
        }
    }

    /**
     * The frozen V2 tax render input exactly as `PaidTaxRenderInput::fromOrigin` builds it, with the real adapter's
     * `pre_tax` and `tax` keys. `$amount` is the frozen line subtotal: the fee for a tax-exclusive line, the gross for an
     * inclusive one.
     */
    private static function taxInput(array $declaration, array $license, int $amount = 150050, string $acceptedAt = '2026-11-07T23:59:59Z',
        int $tax = 0, string $behavior = 'exclusive'): array
    {
        $assent = ['accepted' => true, 'accepted_at' => $acceptedAt, 'statement_version' => 'synthetic'];
        $total = $behavior === 'exclusive' ? $amount + $tax : $amount;
        $source = ['schema_version' => 2, 'producer' => 'production_tax_checkout_v2', 'provenance' => 'synthetic_rehearsal', 'order_id' => 'order-1', 'line_id' => 'line-1',
            'buyer' => ['provenance' => 'synthetic_rehearsal'], 'buyer_declarations' => $declaration,
            'product' => ['id' => 5, 'slug' => 'synthetic-track', 'title' => 'Synthetic Track Title', 'artist' => 'Synthetic Artist'],
            'assent' => $assent, 'license_version_id' => $license['id'], 'license' => $license,
            'pre_tax' => ['currency' => 'USD', 'line_amount_minor' => $amount, 'order_subtotal_minor' => $amount],
            'tax' => ['authority' => 'provider_calculated_buyer_reviewed', 'calculator' => 'stripe_checkout_automatic_tax',
                'automatic_tax' => ['enabled' => true, 'status' => 'complete'], 'tax_behavior' => $behavior, 'currency' => 'USD',
                'line_subtotal_minor' => $amount, 'line_tax_minor' => $tax, 'line_total_minor' => $total,
                'order_subtotal_minor' => $amount, 'order_tax_minor' => $tax, 'order_total_minor' => $total]];

        return self::reseal(PaidTaxRenderInput::fromOrigin(['origin_id' => '00000000-0000-4000-8000-000000000001', 'source' => self::sealSource($source),
            'disclosure' => self::disclosure($license), 'assets' => ['files' => [], 'raw_hash' => str_repeat('6', 64)],
            'delivery_policy' => ['schema_version' => 'synthetic']]));
    }

    /** The producers' self-hashes: each hashed value beside its hash, then the whole line's `source_hash`. */
    private static function sealSource(array $source): array
    {
        unset($source['source_hash']);
        foreach (['buyer' => 'buyer_binding_hash', 'product' => 'product_hash', 'assent' => 'assent_hash', 'license' => 'license_hash'] as $value => $hash) {
            $source[$hash] = CanonicalJson::hash($source[$value]);
        }
        $source['source_hash'] = CanonicalJson::hash($source);

        return $source;
    }

    /** Re-derive every hash and commitment after a test edits the frozen source, so the edit itself is what is tested. */
    private static function reseal(array $input): array
    {
        $source = self::sealSource($input['source']);
        $input['source'] = $source;
        $input['assent'] = $source['assent'];
        $input['source_commitments'] = ['source_hash' => $source['source_hash'], 'asset_graph_hash' => $input['source_commitments']['asset_graph_hash'],
            'license_source_hash' => $source['license']['source_hash'], 'license_submission_hash' => $source['license']['submission_hash'],
            'license_review_hash' => $source['license']['review_evidence_hash'], 'assent_hash' => $source['assent_hash']];

        return $input;
    }

    /** The frozen V1 render input exactly as `PaidGrantRenderInput::fromOrigin` builds it. */
    private static function v1Input(array $declaration, array $license, int $amount = 7500): array
    {
        $assent = ['accepted' => true, 'accepted_at' => '2026-01-31T00:00:00Z', 'statement_version' => 'synthetic'];
        $source = ['schema_version' => 1, 'producer' => 'production_checkout_v1', 'provenance' => 'synthetic_rehearsal',
            'buyer' => ['provenance' => 'synthetic_rehearsal'], 'buyer_declarations' => $declaration,
            'product' => ['id' => 6, 'slug' => 'other-track', 'title' => 'Other Synthetic Title', 'artist' => 'Synthetic Artist'],
            'assent' => $assent, 'license_version_id' => $license['id'], 'license' => $license,
            'amounts' => ['currency' => 'USD', 'subtotal_minor' => $amount, 'tax_minor' => 0, 'total_minor' => $amount],
            'line_amount_minor' => $amount, 'line_tax_minor' => 0];

        return self::reseal(PaidGrantRenderInput::fromOrigin(['origin_id' => '00000000-0000-4000-8000-000000000002', 'source' => self::sealSource($source),
            'disclosure' => self::disclosure($license), 'assets' => ['files' => [], 'raw_hash' => str_repeat('7', 64)],
            'delivery_policy' => ['schema_version' => 'synthetic']]));
    }

    private static function bind(array $input, ?array $terms = null): array
    {
        return ContractVariableBinder::bind($input, $terms ?? $input['source']['license']['structured_terms'], self::constants(), self::coverage());
    }

    private function refuses(string $reason, ?string $placeholder, callable $call): void
    {
        try {
            $call();
            $this->fail('Expected refusal '.$reason);
        } catch (ContractBindingException $exception) {
            $this->assertSame([$reason, $placeholder], [$exception->reason, $exception->placeholder]);
            $this->assertContains($exception->reason, ContractBindingException::REASONS);
        }
    }

    public function test_golden_individual_non_exclusive_line(): void
    {
        $values = self::bind(self::taxInput(self::individual(), self::license(self::terms())));

        $this->assertSame([
            'CONTRACT_DATE' => 'November 7, 2026',
            'CUSTOMER_FULLNAME' => 'Zoë Example Buyer',
            'CUSTOMER_ADDRESS' => '1 Example Street, Suite 2, Springfield, IA, 50000-1234, United States',
            'PRODUCT_TITLE' => 'Synthetic Track Title',
            'PRODUCT_PRICE' => '$1,500.50',
            'PRODUCT_PRICE_WORD' => 'one thousand five hundred dollars and fifty cents',
            'LICENSE_NAME' => 'Synthetic Premium Lease',
            'PRODUCER_ALIAS' => 'Synthetic Producer Alias',
            'STATE_PROVINCE_COUNTRY' => 'Synthetic State, Synthetic Country',
            'FILE_TYPE' => 'MP3, WAV master and Stems ZIP',
            'PUBLISHING_RIGHTS' => '12.34%',
            'DISTRIBUTE_COPIES' => '1,500',
            'NUMBER_OF_RADIO_STATIONS' => '2',
            'MONETIZED_MUSIC_VIDEOS' => '1',
            'MONETIZED_MUSIC_VIDEOS_WORD' => 'one',
            'AUDIO_STREAMS' => '250,000',
            'NON_MONETIZED_VIDEO_STREAMS_ALLOWED' => 'unlimited',
            'MONETIZED_VIDEO_STREAMS_ALLOWED' => '50,000',
            'FREE_DOWNLOADS' => '3,000',
            'PERFORMANCES_FOR_PROFIT' => '21',
            'PERFORMANCES_NOT_FOR_PROFIT' => 'unlimited',
        ], $values);
        // The map is the closed set in its fixed order; the Exclusive-only alias is absent, not blank.
        $this->assertSame(array_values(array_diff(ContractVariableBinder::PLACEHOLDERS, ['CUSTOMER_ALIAS'])), array_keys($values));
    }

    public function test_golden_business_line_names_the_business_and_never_the_representative(): void
    {
        $terms = self::terms(['required_asset_roles' => ['download_mp3'], 'publishing_income.licensor_bps' => 5000,
            'usage.music_videos' => ['mode' => 'limited', 'limit' => 2147483647], 'usage.copies_downloads' => ['mode' => 'unlimited']]);
        $values = self::bind(self::v1Input(self::business(), self::license($terms, 'non-exclusive', 'Synthetic Basic Lease')));

        $this->assertSame([
            'CONTRACT_DATE' => 'January 31, 2026',
            'CUSTOMER_FULLNAME' => 'Example Records Ltd',
            'CUSTOMER_ADDRESS' => '2 Sample Road, London, SW1A 1AA, United Kingdom',
            'PRODUCT_TITLE' => 'Other Synthetic Title',
            'PRODUCT_PRICE' => '$75.00',
            'PRODUCT_PRICE_WORD' => 'seventy-five dollars',
            'LICENSE_NAME' => 'Synthetic Basic Lease',
            'PRODUCER_ALIAS' => 'Synthetic Producer Alias',
            'STATE_PROVINCE_COUNTRY' => 'Synthetic State, Synthetic Country',
            'FILE_TYPE' => 'MP3',
            'PUBLISHING_RIGHTS' => '50.00%',
            'DISTRIBUTE_COPIES' => 'unlimited',
            'NUMBER_OF_RADIO_STATIONS' => '2',
            'MONETIZED_MUSIC_VIDEOS' => '2,147,483,647',
            'MONETIZED_MUSIC_VIDEOS_WORD' => 'two billion one hundred forty-seven million four hundred eighty-three thousand six hundred forty-seven',
            'AUDIO_STREAMS' => '250,000',
            'NON_MONETIZED_VIDEO_STREAMS_ALLOWED' => 'unlimited',
            'MONETIZED_VIDEO_STREAMS_ALLOWED' => '50,000',
            'FREE_DOWNLOADS' => '3,000',
            'PERFORMANCES_FOR_PROFIT' => '21',
            'PERFORMANCES_NOT_FOR_PROFIT' => 'unlimited',
        ], $values);
        $this->assertNotContains('Ada Example', $values);
    }

    public function test_golden_exclusive_line_binds_the_artist_name_and_substitutes_a_template(): void
    {
        $terms = self::terms(['required_asset_roles' => ['master_wav', 'stems_zip'], 'publishing_income.licensor_bps' => 1250]);
        $values = self::bind(self::taxInput(self::exclusiveBuyer(), self::license($terms, 'exclusive', 'Synthetic Exclusive'), 100, '2026-02-28T00:00:00Z'));

        $this->assertSame(ContractVariableBinder::PLACEHOLDERS, array_keys($values));
        $this->assertSame('MC Example', $values['CUSTOMER_ALIAS']);
        $this->assertSame('Émile Example', $values['CUSTOMER_FULLNAME']);
        $this->assertSame('3 Test Avenue, Toronto, ON, Canada', $values['CUSTOMER_ADDRESS']);
        $this->assertSame(['$1.00', 'one dollar', 'WAV master and Stems ZIP', '12.50%', 'February 28, 2026'],
            [$values['PRODUCT_PRICE'], $values['PRODUCT_PRICE_WORD'], $values['FILE_TYPE'], $values['PUBLISHING_RIGHTS'], $values['CONTRACT_DATE']]);

        $template = 'SYNTHETIC. On {CONTRACT_DATE}, {CUSTOMER_FULLNAME} residing at {CUSTOMER_ADDRESS} ({CUSTOMER_ALIAS}) '
            .'licensed "{PRODUCT_TITLE}" under {LICENSE_NAME} for {PRODUCT_PRICE} ({PRODUCT_PRICE_WORD}) from {PRODUCER_ALIAS}, '
            .'{STATE_PROVINCE_COUNTRY}. Files: {FILE_TYPE}; publishing {PUBLISHING_RIGHTS}; copies {DISTRIBUTE_COPIES}; '
            .'radio {NUMBER_OF_RADIO_STATIONS}; videos {MONETIZED_MUSIC_VIDEOS} ({MONETIZED_MUSIC_VIDEOS_WORD}); audio {AUDIO_STREAMS}; '
            .'video {NON_MONETIZED_VIDEO_STREAMS_ALLOWED}/{MONETIZED_VIDEO_STREAMS_ALLOWED}; free {FREE_DOWNLOADS}; '
            .'shows {PERFORMANCES_FOR_PROFIT}/{PERFORMANCES_NOT_FOR_PROFIT}. {CUSTOMER_ALIAS}.';
        $this->assertSame('SYNTHETIC. On February 28, 2026, Émile Example residing at 3 Test Avenue, Toronto, ON, Canada (MC Example) '
            .'licensed "Synthetic Track Title" under Synthetic Exclusive for $1.00 (one dollar) from Synthetic Producer Alias, '
            .'Synthetic State, Synthetic Country. Files: WAV master and Stems ZIP; publishing 12.50%; copies 1,500; '
            .'radio 2; videos 1 (one); audio 250,000; video unlimited/50,000; free 3,000; shows 21/unlimited. MC Example.',
            ContractVariableBinder::substitute($template, $values));
    }

    public function test_binding_is_deterministic_and_ignores_key_order(): void
    {
        $input = self::taxInput(self::individual(), self::license(self::terms()));
        $terms = $input['source']['license']['structured_terms'];
        $reordered = array_reverse($terms, true);
        $reordered['usage'] = array_reverse($terms['usage'], true);

        $this->assertSame(self::bind($input), self::bind($input, $reordered));
    }

    public function test_contract_date_is_the_utc_calendar_date_of_effective_at(): void
    {
        foreach (['2026-11-08T00:00:00Z' => 'November 8, 2026', '2026-11-07T23:59:59Z' => 'November 7, 2026', '2024-02-29T12:00:00Z' => 'February 29, 2024'] as $at => $date) {
            $this->assertSame($date, self::bind(self::taxInput(self::individual(), self::license(self::terms()), 100, $at))['CONTRACT_DATE']);
        }
        foreach (['2026-11-07T23:59:59+01:00', '2026-02-30T00:00:00Z', '2026-11-07 23:59:59Z', '2026-11-07T23:59:59.000Z'] as $at) {
            $this->refuses('unsupported_input', null, fn () => self::bind(self::taxInput(self::individual(), self::license(self::terms()), 100, $at)));
        }
        // effective_at must be the frozen assent instant, not another time.
        $input = self::taxInput(self::individual(), self::license(self::terms()));
        $input['effective_at'] = '2026-11-06T10:00:00Z';
        $this->refuses('unsupported_input', null, fn () => self::bind($input));
    }

    public function test_legacy_two_key_declarations_refuse_as_contract_identity_missing(): void
    {
        $legacy = ['legal_name' => 'Legacy Synthetic Buyer', 'identity_meaning' => 'buyer_declared_legal_name'];
        $this->refuses('contract_identity_missing', null, fn () => self::bind(self::taxInput($legacy, self::license(self::terms()))));
        $this->refuses('contract_identity_missing', null, fn () => self::bind(self::v1Input($legacy, self::license(self::terms()))));
        // A declaration that merely claims another schema is not v2 either.
        $this->refuses('contract_identity_missing', null, fn () => self::bind(self::taxInput(['schema' => 'buyer-contract-identity-v3'] + self::individual(), self::license(self::terms()))));
    }

    public function test_a_v2_declaration_must_reverify_against_the_line(): void
    {
        // Exclusive needs the artist name; a non-exclusive line must not carry one.
        $this->refuses('contract_identity_invalid', null, fn () => self::bind(self::taxInput(self::individual(), self::license(self::terms(), 'exclusive'))));
        $this->refuses('contract_identity_invalid', null, fn () => self::bind(self::taxInput(self::exclusiveBuyer(), self::license(self::terms()))));
        $this->refuses('contract_identity_invalid', null, fn () => self::bind(self::taxInput(array_replace(self::business(), ['representative_name' => null]), self::license(self::terms()))));
        $this->refuses('contract_identity_invalid', null, fn () => self::bind(self::taxInput(array_replace(self::individual(), ['legal_name' => "Zoe\u{202E}Buyer"]), self::license(self::terms()))));
        // The render input's buyer copy must be the source's frozen declaration.
        $input = self::taxInput(self::individual(), self::license(self::terms()));
        $input['buyer']['declarations']['legal_name'] = 'Someone Else';
        $this->refuses('unsupported_input', null, fn () => self::bind($input));
    }

    public function test_only_schema_5_terms_bind_so_a_v4_limit_never_fills_a_monetized_placeholder(): void
    {
        $v4 = EconomicLicenseFixtures::terms();
        $v4['usage']['music_videos'] = ['mode' => 'limited', 'limit' => 9];
        $v4['required_asset_roles'] = ['download_mp3'];
        // A v4 line, with its own v4 terms.
        $this->refuses('license_schema_unsupported', null, fn () => self::bind(self::taxInput(self::individual(), self::license($v4))));
        // A v5 line offered v4 terms, and a v4 line offered v5 terms.
        $this->refuses('license_schema_unsupported', null, fn () => self::bind(self::taxInput(self::individual(), self::license(self::terms())), $v4));
        $this->refuses('license_schema_unsupported', null, fn () => self::bind(self::taxInput(self::individual(), self::license($v4)), self::terms()));
        // A v4 usage object relabelled as schema 5 fails v5 validation instead of being read.
        $relabelled = array_replace($v4, ['schema_version' => 5]);
        $this->refuses('license_terms_invalid', null, fn () => self::bind(self::taxInput(self::individual(), self::license($relabelled))));
    }

    public function test_terms_must_equal_the_lines_frozen_terms(): void
    {
        $input = self::taxInput(self::individual(), self::license(self::terms()));
        $this->refuses('license_terms_mismatch', null, fn () => self::bind($input, self::terms(['usage.free_downloads.limit' => 3001])));
    }

    public function test_a_prohibited_use_prints_zero_and_no_publishing_share_still_refuses(): void
    {
        // Sean, 2026-11-07: a prohibited use prints 0 and its clause stays verbatim.
        foreach (ContractVariableBinder::USAGE as $placeholder => $slot) {
            $terms = self::terms(['usage.'.$slot => ['mode' => 'prohibited']]);
            $values = self::bind(self::taxInput(self::individual(), self::license($terms)));
            $this->assertSame('0', $values[$placeholder]);
            if ($slot === 'music_videos') {
                $this->assertSame('zero', $values['MONETIZED_MUSIC_VIDEOS_WORD']);
            }
        }
        $terms = self::terms(['publishing_income' => ['mode' => 'none', 'policy_key' => 'economic-fixture']]);
        $this->refuses('publishing_share_unbound', 'PUBLISHING_RIGHTS', fn () => self::bind(self::taxInput(self::individual(), self::license($terms))));
    }

    public function test_price_comes_only_from_bounded_usd_minor_units(): void
    {
        $this->refuses('amount_invalid', null, fn () => self::bind(self::taxInput(self::individual(), self::license(self::terms()), 0)));
        $this->refuses('amount_invalid', null, fn () => self::bind(self::taxInput(self::individual(), self::license(self::terms()), 2147483648)));
        $this->assertSame('$21,474,836.47', self::bind(self::taxInput(self::individual(), self::license(self::terms()), 2147483647))['PRODUCT_PRICE']);
        $input = self::taxInput(self::individual(), self::license(self::terms()));
        $input['source']['pre_tax']['currency'] = 'EUR';
        $input['source']['tax']['currency'] = 'EUR';
        $this->refuses('currency_unsupported', null, fn () => self::bind(self::reseal($input)));
        $input = self::taxInput(self::individual(), self::license(self::terms()));
        $input['source']['tax']['line_subtotal_minor']++;
        $this->refuses('amount_invalid', null, fn () => self::bind(self::reseal($input)));
        $input = self::taxInput(self::individual(), self::license(self::terms()));
        $input['source']['pre_tax']['line_amount_minor'] = '150050';
        $this->refuses('amount_invalid', null, fn () => self::bind(self::reseal($input)));
        $input = self::v1Input(self::individual(), self::license(self::terms()));
        $input['source']['amounts']['currency'] = 'usd';
        $this->refuses('currency_unsupported', null, fn () => self::bind(self::reseal($input)));
        // V1 orders are tax-exempt; a V1 line carrying tax refuses instead of printing a taxed figure.
        $input = self::v1Input(self::individual(), self::license(self::terms()));
        $input['source']['line_tax_minor'] = 5;
        $this->refuses('amount_invalid', null, fn () => self::bind(self::reseal($input)));
    }

    public function test_the_contract_states_the_pre_tax_license_fee_for_either_tax_behavior(): void
    {
        // Tax-exclusive: the subtotal is the fee; the tax is added on top and never printed.
        $exclusive = self::bind(self::taxInput(self::individual(), self::license(self::terms()), 1999, '2026-11-07T23:59:59Z', 160, 'exclusive'));
        $this->assertSame(['$19.99', 'nineteen dollars and ninety-nine cents'], [$exclusive['PRODUCT_PRICE'], $exclusive['PRODUCT_PRICE_WORD']]);
        // Tax-inclusive: the subtotal is the gross, so the fee is the subtotal less the line's own tax, exactly.
        $inclusive = self::bind(self::taxInput(self::individual(), self::license(self::terms()), 2159, '2026-11-07T23:59:59Z', 160, 'inclusive'));
        $this->assertSame(['$19.99', 'nineteen dollars and ninety-nine cents'], [$inclusive['PRODUCT_PRICE'], $inclusive['PRODUCT_PRICE_WORD']]);
        $this->assertSame('$21.59', self::bind(self::taxInput(self::individual(), self::license(self::terms()), 2159, '2026-11-07T23:59:59Z', 0, 'inclusive'))['PRODUCT_PRICE']);
        // Inclusive tax equal to the gross leaves no fee; missing tax, an unknown behavior or totals that disagree refuse.
        $this->refuses('amount_invalid', null, fn () => self::bind(self::taxInput(self::individual(), self::license(self::terms()), 500, '2026-11-07T23:59:59Z', 500, 'inclusive')));
        foreach ([['tax.line_tax_minor', null], ['tax.line_tax_minor', -1], ['tax.tax_behavior', 'mixed'], ['tax.line_total_minor', 2160]] as [$path, $value]) {
            $input = self::taxInput(self::individual(), self::license(self::terms()), 2159, '2026-11-07T23:59:59Z', 160, 'inclusive');
            Arr::set($input['source'], $path, $value);
            $this->refuses('amount_invalid', null, fn () => self::bind(self::reseal($input)));
        }
        $input = self::taxInput(self::individual(), self::license(self::terms()), 2159, '2026-11-07T23:59:59Z', 160, 'inclusive');
        unset($input['source']['tax']['line_tax_minor']);
        $this->refuses('amount_invalid', null, fn () => self::bind(self::reseal($input)));
        $input = self::taxInput(self::individual(), self::license(self::terms()), 1999, '2026-11-07T23:59:59Z', 160, 'exclusive');
        $input['source']['tax']['line_total_minor'] = 1999;
        $this->refuses('amount_invalid', null, fn () => self::bind(self::reseal($input)));
    }

    public function test_hash_commitments_must_match_the_frozen_values(): void
    {
        // A stale title under an unchanged hash: the title no longer matches product_hash or source_hash.
        $input = self::taxInput(self::individual(), self::license(self::terms()));
        $input['source']['product']['title'] = 'Edited Title';
        $this->refuses('unsupported_input', null, fn () => self::bind($input));
        // Even with product_hash recomputed, the line's source_hash still names the original title.
        $input['source']['product_hash'] = CanonicalJson::hash($input['source']['product']);
        $this->refuses('unsupported_input', null, fn () => self::bind($input));
        $this->assertSame('Edited Title', self::bind(self::reseal($input))['PRODUCT_TITLE']);

        // An edited product, license, assent or buyer binding whose own hash is stale refuses even when source_hash,
        // the commitments and the assent copy are all recomputed around it.
        foreach (['product' => 'slug', 'license' => 'model_hash', 'assent' => 'statement_version', 'buyer' => 'provenance'] as $value => $key) {
            $input = self::taxInput(self::individual(), self::license(self::terms()));
            $input['source'][$value][$key] = 'changed';
            $unhashed = $input['source'];
            unset($unhashed['source_hash']);
            $input['source']['source_hash'] = CanonicalJson::hash($unhashed);
            $input['source_commitments']['source_hash'] = $input['source']['source_hash'];
            $input['assent'] = $input['source']['assent'];
            $this->refuses('unsupported_input', null, fn () => self::bind($input));
            $this->assertIsArray(self::bind(self::reseal($input)));
        }
        $base = self::taxInput(self::individual(), self::license(self::terms()));
        // Zeroed, missing, extra or drifted commitments.
        $zeroed = $base;
        $zeroed['source_commitments'] = array_fill_keys(array_keys($base['source_commitments']), str_repeat('0', 64));
        $this->refuses('unsupported_input', null, fn () => self::bind($zeroed));
        foreach (array_keys($base['source_commitments']) as $key) {
            $one = $base;
            $one['source_commitments'][$key] = $key === 'asset_graph_hash' ? 'not-a-hash' : str_repeat('0', 64);
            $this->refuses('unsupported_input', null, fn () => self::bind($one));
            $missing = $base;
            unset($missing['source_commitments'][$key]);
            $this->refuses('unsupported_input', null, fn () => self::bind($missing));
        }
        $extra = $base;
        $extra['source_commitments']['other'] = str_repeat('0', 64);
        $this->refuses('unsupported_input', null, fn () => self::bind($extra));
        $assent = $base;
        $assent['assent']['statement_version'] = 'changed';
        $this->refuses('unsupported_input', null, fn () => self::bind($assent));
        $v1 = self::v1Input(self::individual(), self::license(self::terms()));
        $v1['source']['line_amount_minor'] = 7600;
        $this->refuses('unsupported_input', null, fn () => self::bind($v1));
        // A snapshot without review evidence freezes a null review hash, and that null is the commitment.
        $unreviewed = array_replace(self::license(self::terms()), ['review_evidence_id' => null, 'review_evidence_hash' => null]);
        $input = self::taxInput(self::individual(), $unreviewed);
        $this->assertNull($input['source_commitments']['license_review_hash']);
        $this->assertSame('Synthetic Track Title', self::bind($input)['PRODUCT_TITLE']);
        $input['source_commitments']['license_review_hash'] = str_repeat('5', 64);
        $this->refuses('unsupported_input', null, fn () => self::bind($input));
    }

    public static function unprintable(): array
    {
        return ['bidi override' => ["Track\u{202E}kcarT"], 'zero width space' => ["Tra\u{200B}ck"], 'next line' => ["Track\u{0085}X"],
            'line separator' => ["Track\u{2028}X"], 'replacement character' => ["Track\u{FFFD}"], 'combining mark' => ["T\u{0301}rack"],
            'uncovered CJK' => ['Track 中文'], 'punctuation only' => ['!!!']];
    }

    #[DataProvider('unprintable')]
    public function test_title_license_name_and_constants_follow_the_d30_printability_rule(string $value): void
    {
        $input = self::taxInput(self::individual(), self::license(self::terms()));
        $input['source']['product']['title'] = $value;
        $this->refuses('unsupported_characters', 'PRODUCT_TITLE', fn () => self::bind(self::reseal($input)));

        $license = self::license(self::terms(), 'non-exclusive', $value);
        $this->refuses('unsupported_characters', 'LICENSE_NAME', fn () => self::bind(self::taxInput(self::individual(), $license)));

        $input = self::taxInput(self::individual(), self::license(self::terms()));
        foreach ([[new ContractLicenseConstants($value, 'Synthetic Place'), 'PRODUCER_ALIAS'], [new ContractLicenseConstants('Synthetic Alias', $value), 'STATE_PROVINCE_COUNTRY']] as [$constants, $placeholder]) {
            $this->refuses('unsupported_characters', $placeholder, fn () => ContractVariableBinder::bind($input, self::terms(), $constants, self::coverage()));
        }
    }

    public function test_title_and_license_name_are_byte_capped(): void
    {
        foreach ([255 => true, 256 => false, 100000 => false] as $bytes => $binds) {
            $input = self::taxInput(self::individual(), self::license(self::terms()));
            $input['source']['product']['title'] = str_repeat('a', $bytes);
            if ($binds) {
                $this->assertSame(str_repeat('a', $bytes), self::bind(self::reseal($input))['PRODUCT_TITLE']);
            } else {
                $this->refuses('value_invalid', 'PRODUCT_TITLE', fn () => self::bind(self::reseal($input)));
                $this->refuses('value_invalid', 'LICENSE_NAME', fn () => self::bind(self::taxInput(self::individual(), self::license(self::terms(), 'non-exclusive', str_repeat('a', $bytes)))));
            }
        }
    }

    public function test_a_business_licensee_on_an_exclusive_line(): void
    {
        $buyer = array_replace(self::business(), ['artist_name' => 'Example Collective']);
        $values = self::bind(self::taxInput($buyer, self::license(self::terms(), 'exclusive', 'Synthetic Exclusive')));

        $this->assertSame(['Example Records Ltd', 'Example Collective', '2 Sample Road, London, SW1A 1AA, United Kingdom'],
            [$values['CUSTOMER_FULLNAME'], $values['CUSTOMER_ALIAS'], $values['CUSTOMER_ADDRESS']]);
        $this->assertNotContains('Ada Example', $values);
        $this->assertSame(ContractVariableBinder::PLACEHOLDERS, array_keys($values));
    }

    public function test_the_publishing_share_matches_the_licenses_statement_schedule(): void
    {
        foreach ([1 => '0.01%', 1250 => '12.50%', 5000 => '50.00%', 10000 => '100.00%'] as $bps => $printed) {
            $terms = self::terms(['publishing_income.licensor_bps' => $bps]);
            $this->assertSame($printed, self::bind(self::taxInput(self::individual(), self::license($terms)))['PUBLISHING_RIGHTS']);
            $this->assertStringContainsString('licensor share is '.$printed.' of', app(TieredLicenseTerms::class)->statements($terms)['publishing_income']);
        }
    }

    public function test_unsupported_shapes_and_license_types_refuse(): void
    {
        $this->refuses('license_type_unsupported', null, fn () => self::bind(self::taxInput(self::individual(), self::license(self::terms(), 'free'))));
        $input = self::taxInput(self::individual(), self::license(self::terms()));
        foreach (['schema_version', 'purpose', 'source.producer', 'source.schema_version', 'source.product', 'license', 'source.pre_tax'] as $path) {
            $broken = $input;
            Arr::forget($broken, $path);
            $this->refuses('unsupported_input', null, fn () => self::bind($broken));
        }
        // A V2 input cannot carry a V1 source, nor the reverse.
        $v1 = self::v1Input(self::individual(), self::license(self::terms()));
        $this->refuses('unsupported_input', null, fn () => self::bind(array_replace($input, ['source' => $v1['source']])));
        $this->refuses('unsupported_input', null, fn () => self::bind(array_replace($v1, ['source' => $input['source']])));
        // The disclosed license must be the frozen one.
        $renamed = $input;
        $renamed['license']['name'] = 'Another License';
        $this->refuses('unsupported_input', null, fn () => self::bind($renamed));
        $title = $input;
        $title['source']['product']['title'] = " Padded\n";
        $this->refuses('value_invalid', 'PRODUCT_TITLE', fn () => self::bind(self::reseal($title)));
    }

    public function test_substitution_refuses_unknown_placeholders_missing_values_and_malformed_braces(): void
    {
        $values = self::bind(self::taxInput(self::individual(), self::license(self::terms())));

        $this->refuses('unknown_placeholder', 'SAMPLES_DEFINITION', fn () => ContractVariableBinder::substitute('{SAMPLES_DEFINITION}', $values));
        $this->refuses('unknown_placeholder', 'customer_fullname', fn () => ContractVariableBinder::substitute('{customer_fullname}', $values));
        $this->refuses('unknown_placeholder', '', fn () => ContractVariableBinder::substitute('a {} b', $values));
        $this->refuses('unknown_placeholder', 'EXTRA', fn () => ContractVariableBinder::substitute('{CONTRACT_DATE}', [...$values, 'EXTRA' => 'x']));
        // The Exclusive-only alias has no value on a non-exclusive line.
        $this->refuses('missing_value', 'CUSTOMER_ALIAS', fn () => ContractVariableBinder::substitute('{CUSTOMER_FULLNAME} as {CUSTOMER_ALIAS}', $values));
        $this->refuses('missing_value', 'CONTRACT_DATE', fn () => ContractVariableBinder::substitute('{CONTRACT_DATE}', []));
        $this->refuses('missing_value', 'CONTRACT_DATE', fn () => ContractVariableBinder::substitute('{CONTRACT_DATE}', ['CONTRACT_DATE' => '']));
        $this->refuses('missing_value', 'CONTRACT_DATE', fn () => ContractVariableBinder::substitute('x', ['CONTRACT_DATE' => null]));
        foreach (["Line\nBreak", "Tab\tbed", "Null\0", "Del\x7f"] as $control) {
            $this->refuses('value_invalid', 'PRODUCT_TITLE', fn () => ContractVariableBinder::substitute('{PRODUCT_TITLE}', [...$values, 'PRODUCT_TITLE' => $control]));
        }
        foreach (['{{usage.audio_streams}}', '{CONTRACT_DATE', 'CONTRACT_DATE}', '{CONTRACT_{DATE}}', "\xff{CONTRACT_DATE}"] as $template) {
            $this->refuses('malformed_template', null, fn () => ContractVariableBinder::substitute($template, $values));
        }
        $this->assertSame('No placeholders here.', ContractVariableBinder::substitute('No placeholders here.', $values));
    }

    public function test_placeholder_looking_buyer_text_is_never_substituted_again(): void
    {
        $buyer = array_replace(self::individual(), ['legal_name' => '{PRODUCT_TITLE} {CUSTOMER_ADDRESS}',
            'postal_address' => ['country' => 'US', 'lines' => ['{LICENSE_NAME} Street']]]);
        $values = self::bind(self::taxInput($buyer, self::license(self::terms())));

        $this->assertSame('{PRODUCT_TITLE} {CUSTOMER_ADDRESS}', $values['CUSTOMER_FULLNAME']);
        $this->assertSame('Licensee {PRODUCT_TITLE} {CUSTOMER_ADDRESS} of {LICENSE_NAME} Street, United States buys Synthetic Track Title.',
            ContractVariableBinder::substitute('Licensee {CUSTOMER_FULLNAME} of {CUSTOMER_ADDRESS} buys {PRODUCT_TITLE}.', $values));
    }

    public function test_license_constants_are_required_and_cannot_look_like_placeholders(): void
    {
        foreach ([['', 'x', 'PRODUCER_ALIAS'], [' Padded', 'x', 'PRODUCER_ALIAS'], ['{PRODUCER_ALIAS}', 'x', 'PRODUCER_ALIAS'],
            ['x', "Line\nBreak", 'STATE_PROVINCE_COUNTRY'], ['x', str_repeat('a', 161), 'STATE_PROVINCE_COUNTRY'], ['x', "\xff", 'STATE_PROVINCE_COUNTRY']] as [$alias, $place, $placeholder]) {
            $this->refuses('value_invalid', $placeholder, fn () => new ContractLicenseConstants($alias, $place));
        }
    }

    public static function spelled(): array
    {
        return [[0, 'zero'], [1, 'one'], [13, 'thirteen'], [19, 'nineteen'], [20, 'twenty'], [21, 'twenty-one'], [99, 'ninety-nine'],
            [100, 'one hundred'], [101, 'one hundred one'], [110, 'one hundred ten'], [999, 'nine hundred ninety-nine'],
            [1000, 'one thousand'], [1001, 'one thousand one'], [1500, 'one thousand five hundred'], [10000, 'ten thousand'],
            [250000, 'two hundred fifty thousand'], [1000000, 'one million'], [1000001, 'one million one'],
            [2000000040, 'two billion forty'], [ContractNumberWords::MAX, 'nine hundred ninety-nine billion nine hundred ninety-nine million nine hundred ninety-nine thousand nine hundred ninety-nine']];
    }

    #[DataProvider('spelled')]
    public function test_the_number_speller_is_fixed_english(int $number, string $words): void
    {
        $this->assertSame($words, ContractNumberWords::spell($number));
    }

    public function test_money_words_and_figures(): void
    {
        $this->assertSame(['one dollar', 'zero dollars and one cent', 'one dollar and five cents', 'two dollars', 'twenty-one dollars and ninety-nine cents'],
            array_map(ContractNumberWords::dollars(...), [100, 1, 105, 200, 2199]));
        $this->assertSame(['$0.01', '$0.10', '$1.00', '$1,000,000.00'], array_map(ContractNumberWords::usd(...), [1, 10, 100, 100000000]));
        $this->refuses('number_out_of_range', null, fn () => ContractNumberWords::spell(-1));
        $this->refuses('number_out_of_range', null, fn () => ContractNumberWords::spell(ContractNumberWords::MAX + 1));
        $this->refuses('number_out_of_range', null, fn () => ContractNumberWords::usd(-1));
        $this->refuses('number_out_of_range', null, fn () => ContractNumberWords::dollars(-1));
    }
}
