<?php

namespace Tests\Feature;

use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use PHPUnit\Framework\Attributes\Depends;
use Tests\TestCase;

/**
 * Livewire keeps its asset auto-injection flags in static properties that outlive each test's application. A test that
 * renders a component through a real HTTP request (a Filament page, for example) leaves them set, and Livewire then
 * injects its <style>/<script> into every later full-HTML response in the same process, such as the script-free public
 * embed. The base test case flushes that state before each test.
 */
final class LivewireStateIsolationTest extends TestCase
{
    public function test_a_test_can_leave_livewire_asset_injection_state_set(): void
    {
        // The state a component rendered outside Livewire::test() leaves behind (Livewire::test() flushes its own).
        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = true;
        SupportAutoInjectedAssets::$forceAssetInjection = true;

        $this->assertTrue(SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest);
    }

    #[Depends('test_a_test_can_leave_livewire_asset_injection_state_set')]
    public function test_the_next_test_starts_without_livewire_asset_injection_state(): void
    {
        $this->assertFalse(SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest);
        $this->assertFalse(SupportAutoInjectedAssets::$forceAssetInjection);
    }
}
