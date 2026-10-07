<?php

namespace App\Domain\Services\Projects\Attachments;

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Services\Projects\ServiceProjectException;
use App\Domain\SupportAttachments\AttachmentActor;
use App\Domain\SupportAttachments\AttachmentException;
use App\Domain\SupportAttachments\AttachmentMutationAuthority;
use App\Domain\SupportAttachments\AttachmentRows;
use App\Domain\SupportAttachments\AttachmentSourceProof;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Server registry bridge; no HTTP body can select or manufacture this adapter. */
final class ServiceProjectAttachmentAuthority implements AttachmentMutationAuthority
{
    public function lock(string $sourceId, ?int $expectedVersion, string $purpose, AttachmentActor $actor, AttachmentRows $rows): AttachmentSourceProof
    {
        return $this->closed(fn () => new AttachmentSourceProof(ServiceProjectAttachmentSourceV1::lock($sourceId, $expectedVersion, $purpose, $actor, $rows)));
    }

    public function proveCurrent(AttachmentSourceProof $proof, AttachmentRows $rows): void
    {
        $this->closed(function () use ($proof, $rows): void {
            AttachmentException::require($proof->token instanceof ServiceProjectAttachmentSourceV1);
            $proof->token->proveCurrent($rows);
        });
    }

    public function authorizeMutation(AttachmentSourceProof $proof, int $expectedVersion, string $purpose, AttachmentRows $rows): void
    {
        $this->closed(function () use ($proof, $expectedVersion, $purpose, $rows): void {
            AttachmentException::require($proof->token instanceof ServiceProjectAttachmentSourceV1);
            $proof->token->authorizeMutation($expectedVersion, $purpose, $rows);
        });
    }

    private function closed(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (AttachmentException $error) {
            throw $error;
        } catch (ServiceProjectException $error) {
            throw new AttachmentException($error->status);
        } catch (CustomerAccessException) {
            throw new AttachmentException(403);
        } catch (ValidationException) {
            throw new AttachmentException(404);
        } catch (Throwable) {
            throw new AttachmentException(503);
        }
    }
}
