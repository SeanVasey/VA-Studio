<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDefinitions;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantInput;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRecords;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRenderable;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRendererProcess;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRenderInput;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRenderProfile;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRows;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * Codex P2 (7): text the input layer accepts but the renderer refuses (Arabic, combining marks, characters with no glyph
 * in the retained font) would seal an immutable definition or origin that can never render. Every renderable field is
 * checked with the renderer's own code before anything is written. No normalization is applied: such a value is
 * refused as typed, so the sealed value is always what the person entered.
 */
final class ProductionFreeGrantRenderableTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup();
    }

    public function test_an_arabic_or_markless_unsupported_definition_field_is_refused_at_propose_with_nothing_written(): void
    {
        $staff = $this->staff();
        $definitions = new ProductionFreeGrantDefinitions;
        foreach ([
            ['title' => "\u{0639}\u{0646}\u{0648}\u{0627}\u{0646}"],
            ['title' => "Cafe\u{0301} release"],
            ['termsReference' => "\u{0627}\u{0644}\u{0634}\u{0631}\u{0648}\u{0637}"],
            ['termsText' => "Synthetic terms.\nAn Arabic line: \u{0627}\u{0644}\u{0634}\u{0631}\u{0648}\u{0637}"],
            ['assentText' => "I affirm \u{02EF} this."],
        ] as $override) {
            $this->refuses(fn () => $definitions->propose($this->definitionInput($override), $staff), 'unsupported_text');
        }
        $this->assertSame(0, DB::table('production_free_definitions')->count());
        $this->assertNotEmpty($definitions->propose($this->definitionInput(['title' => "Caf\u{00E9} release \u{0416}\u{0391}"]), $staff)['id']);
    }

    public function test_a_stored_definition_with_unrenderable_text_cannot_be_approved(): void
    {
        $definitions = new ProductionFreeGrantDefinitions;
        $author = $this->staff();
        $good = $definitions->propose($this->definitionInput(), $author);
        $row = (array) DB::table('production_free_definitions')->where('id', $good['id'])->first();
        $at = ProductionFreeGrantInput::now();
        $payload = ProductionFreeGrantRecords::decrypt($row);
        $payload['definition_id'] = (string) Str::uuid();
        $payload['proposed_at'] = ProductionFreeGrantInput::iso($at);
        $payload['title'] = "\u{0639}\u{0646}\u{0648}\u{0627}\u{0646}";
        $columns = (new ReflectionMethod(ProductionFreeGrantDefinitions::class, 'columns'))->invoke($definitions, $payload, $at);
        DB::transaction(fn () => (new ProductionFreeGrantRows)->insert('production_free_definitions', $columns, $payload));

        $this->refuses(fn () => $definitions->approve($payload['definition_id'], ['definitionHash' => $columns['definition_hash']], $this->staff()), 'unsupported_text');
        $this->assertSame(0, DB::table('production_free_reviews')->where('definition_id', $payload['definition_id'])->count());
    }

    public function test_an_unrenderable_buyer_name_is_refused_at_review_and_accept_with_no_origin_written(): void
    {
        $definition = $this->openDefinition();
        $owner = $this->customer();
        $grants = new ProductionFreeGrants;
        $review = $grants->review($definition['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user']);
        foreach (["Jose\u{0301} Garc\u{00ED}a", "\u{0645}\u{062D}\u{0645}\u{062F}", "Buyer \u{02EF}"] as $name) {
            $this->refuses(fn () => $grants->review($definition['id'], $name, $owner['principal'], $owner['user']), 'unsupported_text');
            $this->refuses(fn () => $grants->accept($definition['id'], $this->assentInput($review, ['declaredName' => $name]), $owner['principal'], $owner['user']), 'unsupported_text');
        }
        $this->assertSame(0, DB::table('production_free_origins')->count());
    }

    public function test_a_supported_name_still_works_end_to_end(): void
    {
        $definition = $this->openDefinition(overrides: ['title' => "Caf\u{00E9} \u{0416} \u{0391} free grant"]);
        $owner = $this->customer();
        $grants = new ProductionFreeGrants;
        $name = "Zo\u{00EB} M\u{00FC}ller \u{0416}";
        $review = $grants->review($definition['id'], $name, $owner['principal'], $owner['user']);
        $origin = $grants->accept($definition['id'], $this->assentInput($review), $owner['principal'], $owner['user']);
        $rendered = (new ProductionFreeGrantDocuments)->render($origin['id']);
        $this->assertSame('complete', $rendered['documentStatus']);
        $shown = (new ProductionFreeGrantLibrary)->show($origin['id'], $owner['principal'], $owner['user']);
        $this->assertSame($name, $shown['declaredName']);
    }

    /** The admission check and the real isolated renderer must agree on a representative set of characters. */
    public function test_the_admission_check_agrees_with_the_real_renderer(): void
    {
        $accepted = ['Ana', "Z\u{00E9}", "\u{0416}", "\u{0391}\u{03C9}", "\u{20AC}", "\u{2713}", "\u{01C5}"];
        $refused = ["e\u{0301}", "\u{0627}", "\u{02EF}", "\u{02F0}", "\u{0601}", "x\u{200B}y"];
        foreach ([...$accepted, ...$refused] as $name) {
            $admitted = true;
            try {
                ProductionFreeGrantRenderable::require(declaredName: $name);
            } catch (ProductionFreeGrantException $error) {
                $admitted = false;
            }
            $this->assertSame($admitted, $this->rendersOnTheRealRenderer($name), 'Disagreement for '.bin2hex($name));
            $this->assertSame(in_array($name, $accepted, true), $admitted, bin2hex($name));
        }
    }

    /** The glyph test is proved identical to the renderer's for one revision; a new revision must re-prove it. */
    public function test_the_glyph_check_is_verified_against_the_current_renderer_revision(): void
    {
        $this->assertSame(ProductionFreeGrantRenderable::VERIFIED_REVISION, array_key_last(ProductionFreeGrantRenderProfile::RELEASED),
            'A renderer revision was released: repeat the glyph equivalence comparison, then move VERIFIED_REVISION.');
        foreach (['synthetic_rehearsal', 'verified_production'] as $provenance) {
            $this->assertSame(ProductionFreeGrantRenderProfile::RELEASED[ProductionFreeGrantRenderable::VERIFIED_REVISION][$provenance],
                CanonicalJson::hash(ProductionFreeGrantRenderProfile::current($provenance)));
        }
    }

    private function rendersOnTheRealRenderer(string $name): bool
    {
        $origin = ['provenance' => 'synthetic_rehearsal', 'origin_id' => '00000000-0000-4000-8000-000000000000', 'accepted_at' => '2000-01-01T00:00:00Z',
            'declared_name' => $name, 'definition_id' => '00000000-0000-4000-8000-000000000001', 'definition_hash' => str_repeat('a', 64),
            'review_id' => '00000000-0000-4000-8000-000000000002', 'display_hash' => str_repeat('b', 64), 'buyer_binding' => ['account_public_id' => 'probe'],
            'definition' => ['title' => 'Title', 'terms_reference' => 'Reference', 'terms_text' => 'Terms', 'terms_hash' => hash('sha256', 'Terms'),
                'assent_text' => 'Assent', 'assets' => [['role' => 'master_wav', 'source_id' => 'probe', 'sha256' => str_repeat('c', 64), 'bytes' => 1,
                    'mime_type' => 'audio/wav', 'filename' => 'production-free-master_wav.wav']]]];
        try {
            (new ProductionFreeGrantRendererProcess)->render(ProductionFreeGrantRenderInput::fromOrigin($origin), ProductionFreeGrantRenderProfile::current('synthetic_rehearsal'));

            return true;
        } catch (ContractIssuanceException $error) {
            $this->assertSame('unsupported_input', $error->reason);

            return false;
        }
    }

    private function refuses(callable $operation, string $reason): void
    {
        try {
            $operation();
            $this->fail('Must refuse '.$reason);
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }
}
