<?php

namespace Tests\Unit;

use App\Domain\Commerce\ContractIdentity\ContractGlyphCoverage;
use App\Domain\Commerce\ContractIdentity\ContractIdentityDeclarationV2;
use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Rights\LicenseTerritories;
use App\Support\CanonicalJson;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContractIdentityDeclarationV2Test extends TestCase
{
    private const STATEMENT = ['statement_version' => 'individual-legal-name-confirmation-v1', 'statement_hash' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'];

    private const BUSINESS_STATEMENT = ['statement_version' => 'business-legal-name-confirmation-v1', 'statement_hash' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'];

    private const STATEMENTS = ['individual' => self::STATEMENT, 'business' => self::BUSINESS_STATEMENT];

    private static ?ContractGlyphCoverage $coverage = null;

    private static function coverage(): ContractGlyphCoverage
    {
        $fonts = dirname(__DIR__, 2).'/resources/contracts/test-v2/fonts/generated/';

        return self::$coverage ??= ContractGlyphCoverage::fromTcpdfMetrics([$fonts.'dejavusans.json', $fonts.'dejavusansb.json']);
    }

    private static function input(array $overrides = [], array $address = []): array
    {
        return array_replace([
            'licenseeType' => 'individual',
            'legalName' => '  Zoë Émile Łukasz  ',
            'legalNameConfirmed' => true,
            'postalAddress' => array_replace(['country' => 'US', 'lines' => [' 1 Example Street ', 'Suite 2'], 'locality' => 'Springfield',
                'region' => 'IA', 'postalCode' => '50000-1234'], $address),
        ], $overrides);
    }

    private function refuses(string $reason, callable $call): void
    {
        try {
            $call();
            $this->fail('Expected refusal '.$reason);
        } catch (CheckoutException $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertSame(422, $e->status);
        }
    }

    public function test_input_becomes_the_trimmed_canonical_declaration_and_reverifies_unchanged(): void
    {
        $declaration = ContractIdentityDeclarationV2::fromInput(self::input(), false, self::STATEMENTS, self::coverage());

        $this->assertSame([
            'schema' => 'buyer-contract-identity-v2',
            'licensee_type' => 'individual',
            'legal_name' => 'Zoë Émile Łukasz',
            'representative_name' => null,
            'legal_name_confirmation' => ['confirmed' => true, ...self::STATEMENT],
            'postal_address' => ['country' => 'US', 'lines' => ['1 Example Street', 'Suite 2'], 'locality' => 'Springfield', 'region' => 'IA', 'postalCode' => '50000-1234'],
            'artist_name' => null,
            'identity_meaning' => 'buyer_declared_contract_identity',
        ], $declaration);
        $this->assertSame($declaration, ContractIdentityDeclarationV2::verify($declaration, false, self::coverage()));
        // Key order does not change the frozen hash the order chain binds.
        $reordered = array_reverse([...$declaration, 'postal_address' => array_reverse($declaration['postal_address'], true),
            'legal_name_confirmation' => array_reverse($declaration['legal_name_confirmation'], true)], true);
        $this->assertSame(CanonicalJson::hash($declaration), CanonicalJson::hash($reordered));
        $this->assertSame('1 Example Street, Suite 2, Springfield, IA, 50000-1234, United States',
            ContractIdentityDeclarationV2::customerAddress($declaration['postal_address']));
    }

    public function test_optional_address_parts_are_omitted_when_absent_null_or_blank(): void
    {
        $declaration = ContractIdentityDeclarationV2::fromInput(self::input([], ['country' => 'AX', 'lines' => ['Torggatan 1'],
            'locality' => '  ', 'region' => null, 'postalCode' => '']), false, self::STATEMENTS, self::coverage());

        $this->assertSame(['country' => 'AX', 'lines' => ['Torggatan 1']], $declaration['postal_address']);
        $this->assertSame('Torggatan 1, Åland Islands', ContractIdentityDeclarationV2::customerAddress($declaration['postal_address']));
    }

    public function test_a_business_licensee_records_the_representative_and_the_business_statement(): void
    {
        $input = self::input(['licenseeType' => 'business', 'legalName' => ' Example Records LLC ', 'representativeName' => ' Zoë Émile ']);
        $declaration = ContractIdentityDeclarationV2::fromInput($input, false, self::STATEMENTS, self::coverage());

        $this->assertSame('business', $declaration['licensee_type']);
        $this->assertSame('Example Records LLC', $declaration['legal_name']);
        $this->assertSame('Zoë Émile', $declaration['representative_name']);
        $this->assertSame(['confirmed' => true, ...self::BUSINESS_STATEMENT], $declaration['legal_name_confirmation']);
        $this->assertSame($declaration, ContractIdentityDeclarationV2::verify($declaration, false, self::coverage()));

        // The representative is required for a business and refused for an individual.
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::fromInput([...$input, 'representativeName' => '  '], false, self::STATEMENTS, self::coverage()));
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::fromInput(array_diff_key($input, ['representativeName' => true]), false, self::STATEMENTS, self::coverage()));
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::fromInput(self::input(['representativeName' => 'Zoë Émile']), false, self::STATEMENTS, self::coverage()));
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::verify([...$declaration, 'representative_name' => null], false, self::coverage()));
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::verify([...$declaration, 'licensee_type' => 'individual'], false, self::coverage()));
        foreach (['trust', 'Business', 1, null] as $type) {
            $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::verify([...$declaration, 'licensee_type' => $type], false, self::coverage()));
            $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::verify([...$declaration, 'licensee_type' => $type, 'representative_name' => null], false, self::coverage()));
        }
        $this->refuses('unsupported_characters', fn () => ContractIdentityDeclarationV2::fromInput([...$input, 'representativeName' => "Zo\u{200B}e"], false, self::STATEMENTS, self::coverage()));
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::fromInput([...$input, 'representativeName' => str_repeat('a', 256)], false, self::STATEMENTS, self::coverage()));
    }

    public function test_the_licensee_type_selects_its_own_statement_and_needs_one_on_offer(): void
    {
        $individual = ContractIdentityDeclarationV2::fromInput(self::input(), false, self::STATEMENTS, self::coverage());
        $this->assertSame(self::STATEMENT['statement_hash'], $individual['legal_name_confirmation']['statement_hash']);

        $business = self::input(['licenseeType' => 'business', 'representativeName' => 'Zoë Émile']);
        // A type whose statement is not offered (for example, not yet approved) cannot be chosen.
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::fromInput($business, false, ['individual' => self::STATEMENT], self::coverage()));
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::fromInput(self::input(['licenseeType' => 'trust']), false, self::STATEMENTS, self::coverage()));
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::fromInput(array_diff_key(self::input(), ['licenseeType' => true]), false, self::STATEMENTS, self::coverage()));
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::fromInput(self::input(), false, [...self::STATEMENTS, 'trust' => self::STATEMENT], self::coverage()));
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::fromInput(self::input(), false, [self::STATEMENT], self::coverage()));
    }

    public function test_a_frozen_statement_must_belong_to_the_licensee_type(): void
    {
        $individual = ContractIdentityDeclarationV2::fromInput(self::input(), false, self::STATEMENTS, self::coverage());
        $business = ContractIdentityDeclarationV2::fromInput(self::input(['licenseeType' => 'business', 'representativeName' => 'Zoë Émile']),
            false, self::STATEMENTS, self::coverage());

        // Swapped statements in stored evidence refuse.
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::verify([...$business, 'legal_name_confirmation' => $individual['legal_name_confirmation']], false, self::coverage()));
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::verify([...$individual, 'legal_name_confirmation' => $business['legal_name_confirmation']], false, self::coverage()));
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::verify([...$individual, 'legal_name_confirmation' => ['confirmed' => true, 'statement_version' => 'individualx-v1', 'statement_hash' => self::STATEMENT['statement_hash']]], false, self::coverage()));

        // A miskeyed or duplicated map refuses at capture.
        $swapped = ['individual' => self::BUSINESS_STATEMENT, 'business' => self::STATEMENT];
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::fromInput(self::input(), false, $swapped, self::coverage()));
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::fromInput(self::input(['licenseeType' => 'business', 'representativeName' => 'Zoë Émile']), false, $swapped, self::coverage()));
        $same = ['individual' => self::STATEMENT, 'business' => ['statement_version' => 'business-legal-name-confirmation-v1', 'statement_hash' => self::STATEMENT['statement_hash']]];
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::fromInput(self::input(), false, $same, self::coverage()));
    }

    public function test_every_offered_statement_is_validated_not_only_the_selected_one(): void
    {
        foreach ([
            'malformed business entry' => [...self::STATEMENTS, 'business' => ['x' => 1]],
            'business entry not an array' => [...self::STATEMENTS, 'business' => 'junk'],
            'business hash is an array' => [...self::STATEMENTS, 'business' => ['statement_version' => 'business-v1', 'statement_hash' => ['a']]],
            'business version names the other type' => [...self::STATEMENTS, 'business' => ['statement_version' => 'individual-other-v1', 'statement_hash' => self::BUSINESS_STATEMENT['statement_hash']]],
            'bare type prefix' => ['individual' => ['statement_version' => 'individual-', 'statement_hash' => self::STATEMENT['statement_hash']]],
        ] as $name => $statements) {
            try {
                ContractIdentityDeclarationV2::fromInput(self::input(), false, $statements, self::coverage());
                $this->fail($name);
            } catch (CheckoutException $e) {
                $this->assertSame('invalid', $e->reason, $name);
            }
        }
        $declaration = ContractIdentityDeclarationV2::fromInput(self::input(), false, self::STATEMENTS, self::coverage());
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::verify([...$declaration,
            'legal_name_confirmation' => ['confirmed' => true, 'statement_version' => 'individual-', 'statement_hash' => self::STATEMENT['statement_hash']]], false, self::coverage()));
    }

    public function test_the_pinned_contract_faces_load_only_when_their_hashes_match_the_profile(): void
    {
        $this->assertTrue(\App\Domain\Commerce\ContractIdentity\ContractGlyphCoverage::pinnedContractFaces()->covers('Zoë Émile'));
        $this->assertFalse(\App\Domain\Commerce\ContractIdentity\ContractGlyphCoverage::pinnedContractFaces()->covers('王'));
    }

    public function test_artist_name_is_required_if_and_only_if_the_order_is_exclusive(): void
    {
        $exclusive = ContractIdentityDeclarationV2::fromInput(self::input(['artistName' => ' DJ Example ']), true, self::STATEMENTS, self::coverage());
        $this->assertSame('DJ Example', $exclusive['artist_name']);
        $this->assertSame($exclusive, ContractIdentityDeclarationV2::verify($exclusive, true, self::coverage()));

        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::fromInput(self::input(), true, self::STATEMENTS, self::coverage()));
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::fromInput(self::input(['artistName' => '  ']), true, self::STATEMENTS, self::coverage()));
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::fromInput(self::input(['artistName' => 'DJ Example']), false, self::STATEMENTS, self::coverage()));
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::verify($exclusive, false, self::coverage()));
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::verify([...$exclusive, 'artist_name' => null], true, self::coverage()));
        // An omitted artist name on a non-exclusive order is the same as a blank one.
        $this->assertNull(ContractIdentityDeclarationV2::fromInput(self::input(['artistName' => '']), false, self::STATEMENTS, self::coverage())['artist_name']);
    }

    public static function invalidInputs(): array
    {
        $long = static fn (int $bytes): string => str_repeat('a', $bytes);

        return [
            'confirmation missing' => [['legalNameConfirmed' => null]],
            'confirmation truthy, not true' => [['legalNameConfirmed' => 1]],
            'confirmation false' => [['legalNameConfirmed' => false]],
            'legal name blank' => [['legalName' => '   ']],
            'legal name not a string' => [['legalName' => ['Name']]],
            'legal name 256 bytes' => [['legalName' => $long(256)]],
            'legal name invalid UTF-8' => [['legalName' => "Bad \xC3\x28"]],
            'legal name with newline' => [['legalName' => "Two\nLines"]],
            'legal name with tab' => [['legalName' => "Tab\tName"]],
            'legal name with DEL' => [['legalName' => "Del\x7fName"]],
            'unknown key' => [['email' => 'x@example.test']],
            'address not an object' => [['postalAddress' => 'Somewhere']],
            'unknown address key' => [[], ['street' => 'x']],
            'country missing' => [[], ['country' => null]],
            'country lower case' => [[], ['country' => 'us']],
            'country not in the pinned list' => [[], ['country' => 'XK']],
            'no lines' => [[], ['lines' => []]],
            'four lines' => [[], ['lines' => ['a', 'b', 'c', 'd']]],
            'lines not a list' => [[], ['lines' => ['first' => 'a']]],
            'blank first line' => [[], ['lines' => [' ', 'b']]],
            'blank later line' => [[], ['lines' => ['a', '']]],
            'line 121 bytes' => [[], ['lines' => [$long(121)]]],
            'locality 81 bytes' => [[], ['locality' => $long(81)]],
            'region not a string' => [[], ['region' => 5]],
            'postal code 21 bytes' => [[], ['postalCode' => str_repeat('1', 21)]],
            'postal code punctuation' => [[], ['postalCode' => '50000/1']],
            'postal code non-ASCII' => [[], ['postalCode' => '５００００']],
            'address over 512 bytes' => [[], ['lines' => [$long(120), $long(120), $long(120)], 'locality' => $long(80), 'region' => $long(80), 'postalCode' => '12345']],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_malformed_input_refuses_as_invalid(array $overrides, array $address = []): void
    {
        $input = self::input($overrides, $address);
        if (array_key_exists('legalNameConfirmed', $overrides) && $overrides['legalNameConfirmed'] === null) {
            unset($input['legalNameConfirmed']);
        }
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::fromInput($input, false, self::STATEMENTS, self::coverage()));
    }

    public function test_the_address_cap_is_inclusive_at_512_bytes(): void
    {
        // 3 * 120 + 80 + 49 + 13 ("United States") + 5 separators * 2 = 512 bytes after joining.
        $address = ['lines' => [str_repeat('a', 120), str_repeat('b', 120), str_repeat('c', 120)], 'locality' => str_repeat('d', 80), 'region' => str_repeat('e', 49), 'postalCode' => null];
        $declaration = ContractIdentityDeclarationV2::fromInput(self::input([], $address), false, self::STATEMENTS, self::coverage());
        $this->assertSame(512, strlen(ContractIdentityDeclarationV2::customerAddress($declaration['postal_address'])));

        $address['region'] .= 'e';
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::fromInput(self::input([], $address), false, self::STATEMENTS, self::coverage()));
    }

    public static function unprintable(): array
    {
        return [
            'right-to-left override U+202E' => ["Name\u{202E}evil"],
            'left-to-right embedding U+202A' => ["Name\u{202A}x"],
            'first strong isolate U+2068' => ["Name\u{2068}x"],
            'left-to-right mark U+200E' => ["Name\u{200E}x"],
            'zero-width space U+200B' => ["Na\u{200B}me"],
            'zero-width joiner U+200D' => ["Na\u{200D}me"],
            'byte order mark U+FEFF' => ["\u{FEFF}Name"],
            'soft hyphen U+00AD' => ["Na\u{AD}me"],
            'C1 control U+0085' => ["Na\u{85}me"],
            'private use U+E000' => ["Na\u{E000}me"],
            'no-break space U+00A0' => ["Zoë\u{A0}Émile"],
            'ideographic space U+3000' => ["Zoë\u{3000}Émile"],
            'line separator U+2028' => ["Zoë\u{2028}Émile"],
            'decomposed e + U+0301' => ["Zoe\u{301}"],
            'Hebrew, which the face draws but would not reorder' => ['אבג'],
            'Arabic, which the face draws but would not shape' => ['محمد'],
            'CJK, which the face lacks' => ['王小明'],
            'thumbs-up emoji U+1F44D, which the face lacks' => ["Name \u{1F44D}"],
            'braille pattern blank U+2800 alone' => ["\u{2800}"],
            'braille pattern blank U+2800 between words' => ["John\u{2800}Smith"],
            'object replacement U+FFFC, zero width' => ["Zoe\u{FFFC}"],
            'replacement character U+FFFD' => ["Zo\u{FFFD}"],
            'Kelvin sign U+212A (NFC is K)' => ["\u{212A}elvin"],
            'Angstrom sign U+212B (NFC is Å)' => ["\u{212B}sa"],
            'Ohm sign U+2126' => ["\u{2126}mega"],
            'Greek question mark U+037E' => ["Name\u{37E}"],
            'Greek oxia U+1F71' => ["\u{1F71}lpha"],
            'punctuation only' => ['.'],
            'modifier letter apostrophe U+02BC alone' => ["\u{2BC}"],
            'superscript two alone' => ['²'],
            'vulgar fraction one half alone' => ['½'],
            'symbols only' => ['-- ¶'],
        ];
    }

    #[DataProvider('unprintable')]
    public function test_characters_the_contract_cannot_print_refuse_as_unsupported_in_every_printed_field(string $value): void
    {
        $this->refuses('unsupported_characters', fn () => ContractIdentityDeclarationV2::fromInput(self::input(['legalName' => $value]), false, self::STATEMENTS, self::coverage()));
        $this->refuses('unsupported_characters', fn () => ContractIdentityDeclarationV2::fromInput(self::input(['artistName' => $value]), true, self::STATEMENTS, self::coverage()));
        $this->refuses('unsupported_characters', fn () => ContractIdentityDeclarationV2::fromInput(self::input([], ['lines' => ['1 Main St', $value]]), false, self::STATEMENTS, self::coverage()));
        $this->refuses('unsupported_characters', fn () => ContractIdentityDeclarationV2::fromInput(self::input([], ['locality' => $value]), false, self::STATEMENTS, self::coverage()));
        $this->refuses('unsupported_characters', fn () => ContractIdentityDeclarationV2::fromInput(self::input([], ['region' => $value]), false, self::STATEMENTS, self::coverage()));
        $this->refuses('unsupported_characters', fn () => ContractIdentityDeclarationV2::fromInput(self::input(['licenseeType' => 'business', 'representativeName' => $value]), false, self::STATEMENTS, self::coverage()));
    }

    public function test_a_shape_error_is_reported_before_a_character_problem(): void
    {
        // An unprintable name and an over-long line together report the shape error, never the character one.
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::fromInput(
            self::input(['legalName' => '王小明'], ['lines' => [str_repeat('a', 121)]]), false, self::STATEMENTS, self::coverage()));
    }

    public function test_supported_scripts_across_the_pinned_faces_are_accepted(): void
    {
        foreach (['Zoë Émile', 'Łukasz Żółć', 'Ἀλέξανδρος', 'Đorđe Đorđević', 'Иван Петров', "O'Brien-Smith Jr.", 'Søren Ærø'] as $name) {
            $declaration = ContractIdentityDeclarationV2::fromInput(self::input(['legalName' => $name]), false, self::STATEMENTS, self::coverage());
            $this->assertSame($name, $declaration['legal_name']);
        }
    }

    public function test_no_accepted_codepoint_changes_under_nfc_and_none_draws_at_zero_width(): void
    {
        // Guards the pinned REFUSED list: if a face or Unicode update adds a covered codepoint that NFC rewrites,
        // this fails instead of letting two spellings of one printed name hash differently.
        $fonts = dirname(__DIR__, 2).'/resources/contracts/test-v2/fonts/generated/';
        $regular = json_decode((string) file_get_contents($fonts.'dejavusans.json'), true)['cw'];
        $bold = json_decode((string) file_get_contents($fonts.'dejavusansb.json'), true)['cw'];
        $accepted = 0;
        foreach (array_keys(array_intersect_key($regular, $bold)) as $codepoint) {
            $character = mb_chr($codepoint, 'UTF-8');
            try {
                ContractIdentityDeclarationV2::fromInput(self::input(['legalName' => 'A'.$character]), false, self::STATEMENTS, self::coverage());
            } catch (CheckoutException) {
                continue;
            }
            $accepted++;
            $this->assertTrue(\Normalizer::isNormalized($character, \Normalizer::FORM_C), sprintf('U+%04X', $codepoint));
            $this->assertGreaterThan(0, $regular[$codepoint], sprintf('U+%04X', $codepoint));
            $this->assertGreaterThan(0, $bold[$codepoint], sprintf('U+%04X', $codepoint));
            $this->assertSame(0, preg_match('/[\p{C}\p{M}\p{Z}]/u', str_replace(' ', '', $character)), sprintf('U+%04X', $codepoint));
        }
        // The accepted repertoire is pinned: a face, PCRE or list change must be reviewed, not absorbed.
        $this->assertSame(4899, $accepted);
    }

    public function test_every_pinned_country_name_is_printable_in_the_pinned_faces(): void
    {
        $this->assertCount(249, LicenseTerritories::COUNTRIES);
        foreach (LicenseTerritories::COUNTRIES as $code => $name) {
            $this->assertTrue(self::coverage()->covers($name), $code);
        }
    }

    public function test_statement_binding_must_be_a_version_and_a_sha256(): void
    {
        foreach ([['statement_version' => '', 'statement_hash' => self::STATEMENT['statement_hash']],
            ['statement_version' => 'v1', 'statement_hash' => strtoupper(self::STATEMENT['statement_hash'])],
            ['statement_version' => 'v1', 'statement_hash' => 'abc'],
            ['statement_version' => 'v1'],
            [...self::STATEMENT, 'extra' => 1],
            [...self::STATEMENT, 'confirmed' => false]] as $statement) {
            $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::fromInput(self::input(), false, ['individual' => $statement], self::coverage()));
        }
    }

    public function test_stored_declarations_reverify_exactly(): void
    {
        $declaration = ContractIdentityDeclarationV2::fromInput(self::input(), false, self::STATEMENTS, self::coverage());
        $mutations = [
            'untrimmed name' => [...$declaration, 'legal_name' => ' '.$declaration['legal_name']],
            'unconfirmed' => [...$declaration, 'legal_name_confirmation' => [...$declaration['legal_name_confirmation'], 'confirmed' => false]],
            'meaning' => [...$declaration, 'identity_meaning' => 'buyer_declared_legal_name'],
            'extra key' => [...$declaration, 'email' => 'x@example.test'],
            'missing artist key' => array_diff_key($declaration, ['artist_name' => true]),
            'blank optional kept' => [...$declaration, 'postal_address' => [...$declaration['postal_address'], 'region' => '']],
            'untrimmed line' => [...$declaration, 'postal_address' => [...$declaration['postal_address'], 'lines' => ['1 Example Street ']]],
            'lines not a list' => [...$declaration, 'postal_address' => [...$declaration['postal_address'], 'lines' => [1 => '1 Example Street']]],
            'country key missing' => [...$declaration, 'postal_address' => array_diff_key($declaration['postal_address'], ['country' => true])],
            'postal code trailing newline' => [...$declaration, 'postal_address' => [...$declaration['postal_address'], 'postalCode' => "50000\n"]],
            'statement version trailing newline' => [...$declaration, 'legal_name_confirmation' => [...$declaration['legal_name_confirmation'], 'statement_version' => "v1\n"]],
            'confirmation extra key' => [...$declaration, 'legal_name_confirmation' => [...$declaration['legal_name_confirmation'], 'at' => 'now']],
        ];
        foreach ($mutations as $name => $mutation) {
            try {
                ContractIdentityDeclarationV2::verify($mutation, false, self::coverage());
                $this->fail($name);
            } catch (CheckoutException $e) {
                $this->assertSame('invalid', $e->reason, $name);
            }
        }
    }

    public function test_legacy_two_key_declarations_are_not_v2_and_do_not_verify(): void
    {
        $legacy = ['legal_name' => 'Zoë Émile', 'identity_meaning' => 'buyer_declared_legal_name'];
        $this->assertFalse(ContractIdentityDeclarationV2::isV2($legacy));
        $this->assertFalse(ContractIdentityDeclarationV2::isV2(null));
        $this->assertTrue(ContractIdentityDeclarationV2::isV2(['schema' => 'buyer-contract-identity-v2']));
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::verify($legacy, false, self::coverage()));
    }

    public function test_coverage_intersects_faces_and_refuses_bad_metrics(): void
    {
        $fonts = dirname(__DIR__, 2).'/resources/contracts/test-v2/fonts/generated/';
        $regular = ContractGlyphCoverage::fromTcpdfMetrics([$fonts.'dejavusans.json']);
        $bold = json_decode((string) file_get_contents($fonts.'dejavusansb.json'), true)['cw'];
        $regularOnly = array_diff_key(json_decode((string) file_get_contents($fonts.'dejavusans.json'), true)['cw'], $bold);
        $this->assertNotSame([], $regularOnly);
        $codepoint = array_key_first($regularOnly);
        $this->assertTrue($regular->covers(mb_chr($codepoint, 'UTF-8')));
        $this->assertFalse(self::coverage()->covers(mb_chr($codepoint, 'UTF-8')));
        $this->assertFalse(self::coverage()->covers("\xC3\x28"));
        // U+FFFC is in both width tables at width 0; a zero-width glyph is not coverage.
        $this->assertFalse(self::coverage()->covers("\u{FFFC}"));
        $this->assertFalse($regular->covers("\u{FFFC}"));

        foreach ([[], [$fonts.'missing.json'], [__FILE__]] as $paths) {
            try {
                ContractGlyphCoverage::fromTcpdfMetrics($paths);
                $this->fail('Expected refusal');
            } catch (InvalidArgumentException|\JsonException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
