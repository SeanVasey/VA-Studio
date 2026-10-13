<?php

namespace Tests\Unit;

use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberOriginalSourceValues;
use App\Support\CanonicalJson;
use PHPUnit\Framework\TestCase;
use Tests\Support\MemberOriginalRendererFixtures;

class MemberOriginalSourceValuesTest extends TestCase
{
    public function test_complete_source_projects_exact_child_values_without_private_rows_or_live_authority(): void
    {
        $document = MemberOriginalRendererFixtures::sourceDocument();
        $source = MemberOriginalSourceValues::from($document);
        $this->assertSame(CanonicalJson::hash($document), $source->hash());
        $this->assertSame(CanonicalJson::encode($document), CanonicalJson::encode($source->document()));
        $input = $source->renderInput()->value();
        $this->assertSame($document['origin_id'], $input['origin_id']);
        $this->assertSame(CanonicalJson::encode($document['selection']), CanonicalJson::encode($input['selection']));
        $this->assertSame(CanonicalJson::encode($document['profile']['document']['original_terms']), CanonicalJson::encode($input['original_terms']));
        $this->assertSame($document['redemption']['created_at'], $input['reservation_created_at']);
        $this->assertSame($source->hash(), $input['render_source_hash']);
        $this->assertArrayNotHasKey('invoice', $input);
        $this->assertArrayNotHasKey('seal', $input);
    }

    public function test_nested_caller_references_and_returned_arrays_cannot_mutate_retained_source(): void
    {
        $document = MemberOriginalRendererFixtures::sourceDocument();
        $account = $document['buyer_binding']['account_id'];
        $document['buyer_binding']['account_id'] = &$account;
        $document['invoice']['buyer_binding']['account_id'] = &$account;
        $source = MemberOriginalSourceValues::from($document);
        $hash = $source->hash();
        $account++;
        $copy = $source->document();
        $copy['buyer_binding']['account_id']++;
        $this->assertSame($hash, $source->hash());
        $this->assertNotSame($account, $source->document()['buyer_binding']['account_id']);
    }

    public function test_historical_identity_observation_remains_in_the_semantic_source_hash(): void
    {
        $document = MemberOriginalRendererFixtures::sourceDocument();
        $first = MemberOriginalSourceValues::from($document);
        $id = '00000000-0000-4000-8000-000000000099';
        $document['buyer_binding']['verification_observation_id'] = $id;
        $document['invoice']['buyer_binding']['verification_observation_id'] = $id;
        $second = MemberOriginalSourceValues::from($document);
        $this->assertNotSame($first->hash(), $second->hash());
        $this->assertSame($id, $second->document()['invoice']['buyer_binding']['verification_observation_id']);
    }

    public function test_fresh_observation_fields_are_not_accepted_as_render_source_members(): void
    {
        $document = MemberOriginalRendererFixtures::sourceDocument();
        $document['invoice']['observation_id'] = '00000000-0000-4000-8000-000000000099';
        $this->expectException(MemberGrantException::class);
        MemberOriginalSourceValues::from($document);
    }

    public function test_changed_definition_profile_selection_and_owner_relations_refuse(): void
    {
        foreach (['origin', 'profile', 'license', 'owner', 'float', 'extra'] as $change) {
            $document = MemberOriginalRendererFixtures::sourceDocument();
            match ($change) {
                'origin' => $document['origin_id'] = '00000000-0000-4000-8000-000000000099',
                'profile' => $document['definition']['document']['profile_hash'] = str_repeat('a', 64),
                'license' => $document['selection']['license_manifest_hash'] = str_repeat('b', 64),
                'owner' => $document['buyer_binding']['account_id']++,
                'float' => $document['redemption']['credit_amount'] = 1.5,
                'extra' => $document['private_path'] = '/not-a-source-member',
            };
            try {
                MemberOriginalSourceValues::from($document);
                $this->fail('Conflicting or shaped source values must refuse.');
            } catch (MemberGrantException $error) {
                $this->assertNotSame('', $error->reason);
            }
        }
    }
}
