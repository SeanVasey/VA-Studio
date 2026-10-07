<?php

namespace App\Http\Controllers;

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\Listening\ListeningException;
use App\Domain\Customers\Listening\ListeningLibrary;
use App\Http\Middleware\CustomerPrivacy;
use App\Http\Requests\CustomerListeningRequest;
use App\Support\CommerceRequestIdentity;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class CustomerListeningLibraryController
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
        return $this->handle($request, 'change');
    }

    public function export(Request $request): Response
    {
        return $this->handle($request, 'export');
    }

    private function handle(Request $request, string $operation = 'read'): Response
    {
        try {
            $identity = app(CommerceRequestIdentity::class);
            $principal = $identity->principal($request);
            $buyer = $identity->actor($request);
            if ($principal === null || $buyer === null) {
                return CustomerPrivacy::error(403);
            }
            $service = app(ListeningLibrary::class);
            $result = match ($operation) {
                'change' => $service->change($principal, $buyer, CustomerListeningRequest::body($request)),
                'export' => $service->export($principal, $buyer, CustomerListeningRequest::exportVersion($request)),
                default => $service->read($principal, $buyer),
            };

            return CustomerPrivacy::protect(response()->json([$operation === 'export' ? 'export' : 'library' => $result]));
        } catch (CustomerAccessException) {
            return CustomerPrivacy::error(403);
        } catch (ListeningException $error) {
            return CustomerPrivacy::error($error->status);
        } catch (ValidationException) {
            return CustomerPrivacy::error(422);
        }
    }
}
