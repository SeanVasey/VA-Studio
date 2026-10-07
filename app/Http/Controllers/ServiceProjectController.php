<?php

namespace App\Http\Controllers;

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Services\Projects\ServiceProjectException;
use App\Domain\Services\Projects\ServiceProjectJson;
use App\Domain\Services\Projects\ServiceProjects;
use App\Http\Middleware\ServiceProjectPrivacy;
use App\Support\CommerceRequestIdentity;
use App\Support\SupportAttachmentUi;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ServiceProjectController
{
    public function page(Request $request): Response
    {
        return $this->run(fn (): Response => Inertia::render('ServiceProjects', ['initial' => $this->indexData($request), 'attachmentsEnabled' => SupportAttachmentUi::enabled()])->toResponse($request));
    }

    public function index(Request $request): Response
    {
        return $this->run(fn (): Response => response()->json($this->indexData($request)));
    }

    public function store(Request $request): Response
    {
        return $this->run(function () use ($request): Response {
            [$principal, $actor] = $this->identity($request);
            $result = app(ServiceProjects::class)->submitBrief($this->body($request), $principal, $actor);

            return response()->json($result, $result['replayed'] ? 200 : 201);
        });
    }

    public function show(string $project, Request $request): Response
    {
        return $this->run(function () use ($project, $request): Response {
            [$principal, $actor] = $this->identity($request);

            return response()->json(['project' => app(ServiceProjects::class)->customerShow($project, $principal, $actor)]);
        });
    }

    public function command(string $project, Request $request): Response
    {
        return $this->run(function () use ($project, $request): Response {
            [$principal, $actor] = $this->identity($request);

            return response()->json(app(ServiceProjects::class)->customerCommand($project, $this->body($request), $principal, $actor));
        });
    }

    private function indexData(Request $request): array
    {
        [$principal, $actor] = $this->identity($request);

        return app(ServiceProjects::class)->customerIndex($principal, $actor);
    }

    private function identity(Request $request): array
    {
        $identity = app(CommerceRequestIdentity::class);
        $principal = $identity->principal($request);
        $actor = $identity->actor($request);
        if (! $principal || ! $actor) {
            throw new CustomerAccessException;
        }

        return [$principal, $actor];
    }

    private function body(Request $request): array
    {
        return ServiceProjectJson::body($request->attributes->get('_service_project_body'));
    }

    private function run(callable $operation): Response
    {
        try {
            return ServiceProjectPrivacy::protect($operation());
        } catch (ServiceProjectException $error) {
            return ServiceProjectPrivacy::error($error->status);
        } catch (CustomerAccessException|AuthorizationException) {
            return ServiceProjectPrivacy::error(403);
        } catch (ValidationException) {
            return ServiceProjectPrivacy::error(422);
        } catch (Throwable $error) {
            return ServiceProjectPrivacy::failure($error);
        }
    }
}
