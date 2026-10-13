<?php

namespace Runtime\MemberPolicyAdmission;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberGrantFactsAuthority;
use App\Domain\Grants\Member\MemberGrantPolicy;
use App\Domain\Grants\Member\MemberOriginalArtifactAuthority;
use App\Domain\Memberships\Production\MemberGrantIntent;
use App\Domain\Memberships\Production\MembershipPolicy;
use App\Domain\Memberships\Production\MembershipReservationAuthority;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Synthetic configuration-only admission proof. No app bootstrap, PDO, schema, authority method, or grant. */
final class MemberGrantMixedProvenanceRegressionTest extends TestCase
{
    public function test_verified_258_with_rehearsal_257_on_configured_sqlite_refuses_before_factory_callbacks(): void
    {
        $previous = Container::getInstance();
        $container = new Container;
        Container::setInstance($container);
        try {
            $container->instance('config', new Repository([
                'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]],
                'production-memberships' => ['enabled' => true, 'authoring_enabled' => false,
                    'version' => MembershipPolicy::VERSION, 'provenance' => IdentityPolicy::REHEARSAL,
                    'approved_policy_hash' => str_repeat('d', 64)],
                'member-grants' => ['enabled' => true, 'version' => 1, 'family' => MemberGrantIntent::FAMILY,
                    'purpose' => MemberGrantIntent::PURPOSE, 'provenance' => IdentityPolicy::PRODUCTION,
                    'approved_definition_hash' => str_repeat('a', 64), 'approved_profile_hash' => str_repeat('b', 64),
                    'approved_original_terms_hash' => str_repeat('c', 64)],
            ]));
            // Real Container::offsetSet provides the framework-scoped captured environment closure.
            $container['env'] = 'testing';
            $factories = [];
            $capabilities = [MemberGrantFactsAuthority::class, MemberOriginalArtifactAuthority::class, MembershipReservationAuthority::class];
            foreach ($capabilities as $capability) {
                $double = $this->createMock($capability);
                foreach ((new ReflectionClass($capability))->getMethods() as $method) {
                    $double->expects($this->never())->method($method->getName());
                }
                $container->bind($capability, static function () use ($capability, $double, &$factories): object {
                    $factories[] = $capability;

                    return $double;
                });
            }
            $this->assertSame([], $factories);
            $this->assertFalse($container->bound('db'), 'The configuration-only container has no database manager or connection factory.');
            $this->assertSame(IdentityPolicy::PRODUCTION, $container->make('config')->get('member-grants.provenance'));
            $this->assertSame(IdentityPolicy::REHEARSAL, $container->make('config')->get('production-memberships.provenance'));
            $this->assertSame('sqlite', $container->make('config')->get('database.connections.sqlite.driver'));

            $decision = 'admitted';
            $current = null;
            try {
                $current = (new MemberGrantPolicy)->current();
            } catch (MemberGrantException $error) {
                $decision = $error->reason;
            }
            if ($decision === 'admitted') {
                $this->assertIsArray($current, 'The old API returned an actual admitted configuration.');
                $this->assertSame(['testing', IdentityPolicy::PRODUCTION], [$current['environment'], $current['provenance']]);
                $this->assertSame($capabilities, $factories, 'The old API actually resolved every nonoperative authority factory.');
            }
            $this->assertFalse($container->bound('db'));
            $observed = ['decision' => $decision, 'factory_calls' => $factories];
            $directory = getenv('MEMBER_POLICY_PROBE_OUTPUT');
            if (! is_string($directory) || ! is_dir($directory)) {
                throw new \LogicException('The external runner must select a private runtime evidence directory.');
            }
            file_put_contents($directory.'/observed.json', json_encode([
                'scope' => 'synthetic configuration-only proof', 'observed' => $observed,
                'configured_258_provenance' => IdentityPolicy::PRODUCTION,
                'configured_257_provenance' => IdentityPolicy::REHEARSAL, 'configured_driver' => 'sqlite',
                'database_manager_bound' => false, 'policy_source' => (new ReflectionClass(MemberGrantPolicy::class))->getFileName(),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");

            $this->assertSame(['decision' => 'provenance_driver', 'factory_calls' => []], $observed,
                'Verified member grant configuration must refuse SQLite before any authority factory callback.');
        } finally {
            Container::setInstance($previous);
        }
    }
}
