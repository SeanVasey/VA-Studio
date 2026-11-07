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
    private const STATEMENT = ['statement_version' => 'legal-name-confirmation-v1', 'statement_hash' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'];

    private static ?ContractGlyphCoverage $coverage = null;

    private static function coverage(): ContractGlyphCoverage
    {
        $fonts = dirname(__DIR__, 2).'/resources/contracts/test-v2/fonts/generated/';

        return self::$coverage ??= ContractGlyphCoverage::fromTcpdfMetrics([$fonts.'dejavusans.json', $fonts.'dejavusansb.json']);
    }

    private static function input(array $overrides = [], array $address = []): array
    {
        return array_replace([
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
        $declaration = ContractIdentityDeclarationV2::fromInput(self::input(), false, self::STATEMENT, self::coverage());

        $this->assertSame([
            'schema' => 'buyer-contract-identity-v2',
            'legal_name' => 'Zoë Émile Łukasz',
            'legal_name_confirmation' => ['confirmed' => true, ...self::STATEMENT],
            'postal_address' => ['country' => 'US', 'lines' => ['1 Example Street', 'Suite 2'], 'locality' => 'Springfield', 'region' => 'IA', 'postalCode' => '50000-1234'],
            'artist_name' => null,
            'identity_meaning' => 'buyer_declared_contract_identity',
        ], $declaration);
        $this->assertSame($declaration, ContractIdentityDeclarationV2::verify($declaration, false, self::coverage()));
        // Key order does not change the frozen hash the order chain binds.
        $this->assertSame(CanonicalJson::hash($declaration), CanonicalJson::hash(array_reverse($declaration, true)));
        $this->assertSame('1 Example Street, Suite 2, Springfield, IA, 50000-1234, United States',
            ContractIdentityDeclarationV2::customerAddress($declaration['postal_address']));
    }

    public function test_optional_address_parts_are_omitted_when_absent_null_or_blank(): void
    {
        $declaration = ContractIdentityDeclarationV2::fromInput(self::input([], ['country' => 'AX', 'lines' => ['Torggatan 1'],
            'locality' => '  ', 'region' => null, 'postalCode' => '']), false, self::STATEMENT, self::coverage());

        $this->assertSame(['country' => 'AX', 'lines' => ['Torggatan 1']], $declaration['postal_address']);
        $this->assertSame('Torggatan 1, Åland Islands', ContractIdentityDeclarationV2::customerAddress($declaration['postal_address']));
    }

    public function test_artist_name_is_required_if_and_only_if_the_order_is_exclusive(): void
    {
        $exclusive = ContractIdentityDeclarationV2::fromInput(self::input(['artistName' => ' DJ Example ']), true, self::STATEMENT, self::coverage());
        $this->assertSame('DJ Example', $exclusive['artist_name']);
        $this->assertSame($exclusive, ContractIdentityDeclarationV2::verify($exclusive, true, self::coverage()));

        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::fromInput(self::input(), true, self::STATEMENT, self::coverage()));
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::fromInput(self::input(['artistName' => '  ']), true, self::STATEMENT, self::coverage()));
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::fromInput(self::input(['artistName' => 'DJ Example']), false, self::STATEMENT, self::coverage()));
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::verify($exclusive, false, self::coverage()));
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::verify([...$exclusive, 'artist_name' => null], true, self::coverage()));
        // An omitted artist name on a non-exclusive order is the same as a blank one.
        $this->assertNull(ContractIdentityDeclarationV2::fromInput(self::input(['artistName' => '']), false, self::STATEMENT, self::coverage())['artist_name']);
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
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::fromInput($input, false, self::STATEMENT, self::coverage()));
    }

    public function test_the_address_cap_is_inclusive_at_512_bytes(): void
    {
        // 3 * 120 + 80 + 49 + 13 ("United States") + 5 separators * 2 = 512 bytes after joining.
        $address = ['lines' => [str_repeat('a', 120), str_repeat('b', 120), str_repeat('c', 120)], 'locality' => str_repeat('d', 80), 'region' => str_repeat('e', 49), 'postalCode' => null];
        $declaration = ContractIdentityDeclarationV2::fromInput(self::input([], $address), false, self::STATEMENT, self::coverage());
        $this->assertSame(512, strlen(ContractIdentityDeclarationV2::customerAddress($declaration['postal_address'])));

        $address['region'] .= 'e';
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::fromInput(self::input([], $address), false, self::STATEMENT, self::coverage()));
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
        ];
    }

    #[DataProvider('unprintable')]
    public function test_characters_the_contract_cannot_print_refuse_as_unsupported_in_every_printed_field(string $value): void
    {
        $this->refuses('unsupported_characters', fn () => ContractIdentityDeclarationV2::fromInput(self::input(['legalName' => $value]), false, self::STATEMENT, self::coverage()));
        $this->refuses('unsupported_characters', fn () => ContractIdentityDeclarationV2::fromInput(self::input(['artistName' => $value]), true, self::STATEMENT, self::coverage()));
        $this->refuses('unsupported_characters', fn () => ContractIdentityDeclarationV2::fromInput(self::input([], ['lines' => ['1 Main St', $value]]), false, self::STATEMENT, self::coverage()));
        $this->refuses('unsupported_characters', fn () => ContractIdentityDeclarationV2::fromInput(self::input([], ['locality' => $value]), false, self::STATEMENT, self::coverage()));
        $this->refuses('unsupported_characters', fn () => ContractIdentityDeclarationV2::fromInput(self::input([], ['region' => $value]), false, self::STATEMENT, self::coverage()));
    }

    public function test_a_shape_error_is_reported_before_a_character_problem(): void
    {
        // An unprintable name and an over-long line together report the shape error, never the character one.
        $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::fromInput(
            self::input(['legalName' => '王小明'], ['lines' => [str_repeat('a', 121)]]), false, self::STATEMENT, self::coverage()));
    }

    public function test_supported_scripts_across_the_pinned_faces_are_accepted(): void
    {
        foreach (['Zoë Émile', 'Łukasz Żółć', 'Ἀλέξανδρος', 'Đorđe Đorđević', 'Иван Петров', "O'Brien-Smith Jr.", 'Søren Ærø'] as $name) {
            $declaration = ContractIdentityDeclarationV2::fromInput(self::input(['legalName' => $name]), false, self::STATEMENT, self::coverage());
            $this->assertSame($name, $declaration['legal_name']);
        }
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
            [...self::STATEMENT, 'extra' => 1]] as $statement) {
            $this->refuses('invalid', fn () => ContractIdentityDeclarationV2::fromInput(self::input(), false, $statement, self::coverage()));
        }
    }

    public function test_stored_declarations_reverify_exactly(): void
    {
        $declaration = ContractIdentityDeclarationV2::fromInput(self::input(), false, self::STATEMENT, self::coverage());
        $mutations = [
            'untrimmed name' => [...$declaration, 'legal_name' => ' '.$declaration['legal_name']],
            'unconfirmed' => [...$declaration, 'legal_name_confirmation' => [...$declaration['legal_name_confirmation'], 'confirmed' => false]],
            'meaning' => [...$declaration, 'identity_meaning' => 'buyer_declared_legal_name'],
            'extra key' => [...$declaration, 'email' => 'x@example.test'],
            'missing artist key' => array_diff_key($declaration, ['artist_name' => true]),
            'blank optional kept' => [...$declaration, 'postal_address' => [...$declaration['postal_address'], 'region' => '']],
            'untrimmed line' => [...$declaration, 'postal_address' => [...$declaration['postal_address'], 'lines' => ['1 Example Street ']]],
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
