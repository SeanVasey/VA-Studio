<?php

namespace App\Http\Controllers;

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\Preferences\ConsentException;
use App\Domain\Customers\Preferences\CustomerConsentPreferences;
use App\Http\Middleware\CustomerPrivacy;
use App\Http\Requests\CustomerCommunicationPreferencesRequest;
use App\Support\CommerceRequestIdentity;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class CustomerCommunicationPreferencesController
{
    public function index(Request $request): Response
    {
        $stream = $request->getContent(true);
        if (! is_resource($stream) || stream_get_contents($stream, 1) !== '') {
            return CustomerPrivacy::error(422);
        }

        return $this->handle($request);
    }

    public function store(Request $request): Response
    {
        return $this->handle($request, true);
    }

    private function handle(Request $request, bool $change = false): Response
    {
        try {
            $identity = app(CommerceRequestIdentity::class);
            $principal = $identity->principal($request);
            $actor = $identity->actor($request);
            if ($principal === null || $actor === null) {
                return CustomerPrivacy::error(403);
            }
            $preferences = app(CustomerConsentPreferences::class);
            $result = $change ? $preferences->change($principal, $actor, CustomerCommunicationPreferencesRequest::body($request))
                : $preferences->read($principal, $actor);

            return CustomerPrivacy::protect(response()->json(['preferences' => $result]));
        } catch (CustomerAccessException) {
            return CustomerPrivacy::error(403);
        } catch (ConsentException $error) {
            return CustomerPrivacy::error($error->status);
        } catch (ValidationException) {
            return CustomerPrivacy::error(422);
        }
    }
}
