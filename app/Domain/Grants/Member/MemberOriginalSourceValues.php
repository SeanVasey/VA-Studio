<?php

namespace App\Domain\Grants\Member;

use App\Domain\Memberships\Production\MemberGrantIntent;
use App\Domain\Memberships\Production\MembershipInvoiceBinding;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipPolicy;
use App\Domain\Memberships\Production\MembershipPolicyDocument;
use App\Domain\Memberships\Production\MembershipValues;
use App\Support\CanonicalJson;
use Throwable;

/** Detached deterministic values only; even a valid public construction grants no admission. */
final readonly class MemberOriginalSourceValues
{
    private function __construct(private array $source) {}

    public static function from(array $document): self
    {
        try {
            $remaining = 30000;
            self::bounded($document, $remaining);
            $bytes = CanonicalJson::encode($document);
            MemberGrantException::require(strlen($bytes) <= 1048576, 'source_value');
            // Detach every PHP reference; a readonly array can otherwise retain a caller's aliases.
            $document = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
            MembershipPolicyDocument::keys($document, ['schema_version', 'family', 'purpose', 'provenance', 'origin_id',
                'buyer_binding', 'invoice', 'period', 'redemption', 'reserve_event', 'selection', 'definition', 'profile']);
            MemberGrantException::require($document['schema_version'] === 'member-original-source-v1'
                && $document['family'] === MemberGrantIntent::FAMILY && $document['purpose'] === MemberGrantIntent::PURPOSE, 'source_value');
            MembershipValues::provenance($document['provenance']);
            MembershipValues::id($document['origin_id']);
            MembershipValues::buyer($document['buyer_binding']);
            foreach (['period', 'reserve_event', 'definition', 'profile'] as $key) {
                MembershipPolicyDocument::keys($document[$key], in_array($key, ['definition', 'profile'], true)
                    ? ['id', 'seal', 'document'] : ['id', 'seal']);
                MembershipValues::id($document[$key]['id']);
                MembershipValues::hash($document[$key]['seal']);
            }
            $redemption = $document['redemption'];
            MembershipPolicyDocument::keys($redemption, ['id', 'seal', 'intent_hash', 'credit_amount', 'created_at', 'honor_deadline']);
            MembershipValues::id($redemption['id']);
            MembershipValues::hash($redemption['seal']);
            MembershipValues::hash($redemption['intent_hash']);
            MembershipValues::utc($redemption['created_at']);
            MembershipValues::utc($redemption['honor_deadline']);
            MemberGrantException::require($document['origin_id'] === $redemption['id']
                && is_int($redemption['credit_amount']) && $redemption['credit_amount'] > 0
                && $redemption['credit_amount'] <= MembershipPolicy::MAX_CREDITS
                && $redemption['created_at'] < $redemption['honor_deadline'], 'source_value');
            $invoice = $document['invoice'];
            MembershipPolicyDocument::keys($invoice, ['source_invoice_hash', 'invoice_graph_hash', 'provider_scope_hash',
                'amount_minor', 'currency', 'period_start', 'period_end', 'paid_at', 'plan_version_id', 'policy_hash',
                'original_terms_hash', 'buyer_binding', 'provenance']);
            MemberGrantException::require(is_string($invoice['paid_at']) && is_string($invoice['plan_version_id']), 'source_value');
            $binding = new MembershipInvoiceBinding($invoice['source_invoice_hash'], $invoice['invoice_graph_hash'],
                $invoice['provider_scope_hash'], $invoice['amount_minor'], $invoice['currency'], $invoice['period_start'],
                $invoice['period_end'], $invoice['policy_hash'], $invoice['original_terms_hash'], $invoice['buyer_binding'],
                $invoice['provenance'], $invoice['paid_at'], $invoice['plan_version_id']);
            MemberGrantException::require(CanonicalJson::encode(MembershipPeriodRecords::semantic($binding)) === CanonicalJson::encode($invoice)
                && $document['buyer_binding'] === $invoice['buyer_binding']
                && $document['provenance'] === $invoice['provenance'], 'source_value');
            $profile = MemberGrantProfileDocument::from($document['profile']['document']);
            $definition = MemberGrantDefinitionDocument::from($document['definition']['document']);
            $profileValue = $profile->value();
            $definitionValue = $definition->value();
            MemberGrantException::require($profileValue['provenance'] === $document['provenance']
                && $definitionValue['provenance'] === $document['provenance']
                && $definitionValue['profile_hash'] === $profile->hash()
                && $definitionValue['plan_version_id'] === $invoice['plan_version_id']
                && $definitionValue['policy_hash'] === $invoice['policy_hash']
                && $definitionValue['original_terms_hash'] === CanonicalJson::hash($profileValue['original_terms'])
                && $definitionValue['original_terms_hash'] === $document['selection']['original_terms_hash']
                && CanonicalJson::hash($definitionValue['license_manifest']) === $document['selection']['license_manifest_hash']
                && CanonicalJson::hash($definitionValue['asset_manifest']) === $document['selection']['asset_manifest_hash'], 'source_value');
            $source = new self($document);
            $source->renderInput();

            return $source;
        } catch (MemberGrantException $error) {
            throw $error;
        } catch (Throwable) {
            throw new MemberGrantException('source_value');
        }
    }

    public function document(): array
    {
        return $this->source;
    }

    public function hash(): string
    {
        return CanonicalJson::hash($this->source);
    }

    public function renderInput(): MemberOriginalRenderInput
    {
        return MemberOriginalRenderInput::from(['schema_version' => 'member-original-render-input-v1',
            'family' => $this->source['family'], 'purpose' => $this->source['purpose'], 'provenance' => $this->source['provenance'],
            'origin_id' => $this->source['origin_id'], 'render_source_hash' => $this->hash(),
            'buyer_account_id' => $this->source['buyer_binding']['account_id'],
            'reservation_created_at' => $this->source['redemption']['created_at'], 'selection' => $this->source['selection'],
            'original_terms' => $this->source['profile']['document']['original_terms']]);
    }

    private static function bounded(mixed $value, int &$remaining, int $depth = 0): void
    {
        MemberGrantException::require(--$remaining >= 0 && $depth <= 26, 'source_value');
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                MemberGrantException::require(is_int($key) || (is_string($key) && strlen($key) <= 256), 'source_value');
                self::bounded($child, $remaining, $depth + 1);
            }
        } else {
            MemberGrantException::require($value === null || is_bool($value) || is_int($value)
                || (is_string($value) && strlen($value) <= 65536 && mb_check_encoding($value, 'UTF-8')), 'source_value');
        }
    }
}
