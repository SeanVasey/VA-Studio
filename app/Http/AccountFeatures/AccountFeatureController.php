<?php

namespace App\Http\AccountFeatures;

use App\Domain\Customers\Listening\ListeningException;
use App\Domain\Customers\Preferences\ConsentException;
use App\Domain\Customers\ProductionFeatures\Listening\ProductionListeningLibrary;
use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentPreferences;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureException;
use App\Domain\Customers\ProductionFeatures\ProductionFeaturePreparation;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureIdentity;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class AccountFeatureController
{
    public function page(Request $request): Response
    {
        if (! AccountFeatureMount::admitted()) {
            return AccountFeaturePrivacy::error(503);
        }
        Inertia::encryptHistory();
        $response = Inertia::render('ProductionCustomerFeatures', ['scope' => bin2hex(random_bytes(24))])->toResponse($request);
        try {
            ProductionAccountFeatureIdentity::forRequest($request, 'listening_library');
        } catch (IdentityException) {
            return AccountFeaturePrivacy::error(403);
        }

        return AccountFeaturePrivacy::protect($response);
    }

    public function listeningRead(Request $request): Response
    {
        return $this->execute($request, 'listening_library', 'read');
    }

    public function listeningInitialize(Request $request): Response
    {
        return $this->execute($request, 'listening_library', 'initialize');
    }

    public function listeningChange(Request $request): Response
    {
        return $this->execute($request, 'listening_library', 'change');
    }

    public function listeningExport(Request $request): Response
    {
        return $this->execute($request, 'listening_library', 'export');
    }

    public function preferencesRead(Request $request): Response
    {
        return $this->execute($request, 'consent_preferences', 'read');
    }

    public function preferencesInitialize(Request $request): Response
    {
        return $this->execute($request, 'consent_preferences', 'initialize');
    }

    public function preferencesChange(Request $request): Response
    {
        return $this->execute($request, 'consent_preferences', 'change');
    }

    private function execute(Request $request, string $feature, string $operation): Response
    {
        if (! AccountFeatureMount::admitted()) {
            return AccountFeaturePrivacy::error(503);
        }
        $response = new AccountFeatureResponse($request);
        $body = $operation === 'read' ? [] : AccountFeatureBody::read($request);
        if ($operation === 'initialize') {
            abort_unless($body === [], 422);
        }
        if ($operation === 'export') {
            abort_unless(array_keys($body) === ['version'] && is_int($body['version']) && $body['version'] >= 0 && $body['version'] <= 2147483646, 422);
        }
        try {
            $identity = ProductionAccountFeatureIdentity::forRequest($request, $feature);
        } catch (IdentityException) {
            return AccountFeaturePrivacy::error(403);
        }
        try {
            $guard = new AccountFeatureRequestGuard($request, $identity);
            $prepared = new ProductionFeaturePreparation(function () use ($guard, $response): void {
                $guard->requireCurrent();
                $response->requireUnchanged();
            }, $feature === 'listening_library' ? 3 * 1024 * 1024 : 65536, $operation === 'export');
            $service = $feature === 'listening_library' ? new ProductionListeningLibrary : new ProductionConsentPreferences;
            match ($operation) {
                'read' => $service->read($identity, $prepared),
                'initialize' => $service->initialize($identity, $prepared),
                'change' => $service->change($identity, $body, $prepared),
                'export' => $service->export($identity, $body['version'], $prepared),
            };
            $response->attach($prepared);

            return $response;
        } catch (ProductionFeatureException|ListeningException|ConsentException $error) {
            return AccountFeaturePrivacy::error($error->status);
        } catch (IdentityException) {
            return AccountFeaturePrivacy::error(503);
        }
    }
}
