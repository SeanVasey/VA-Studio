<?php

namespace App\Domain\Rights;

use App\Domain\Rights\Models\RightsDeclaration;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Current staff writes for pending evidence; verified corrections append a new declaration. */
final class SaveRightsDeclaration
{
    private const FIELDS = ['track_id', 'provenance_reference', 'sample_disclosure'];

    public function create(array $data, User $actor): RightsDeclaration
    {
        $boundary = app(RightsDeclarationBoundary::class);

        return $boundary->transaction($actor, function (User $current) use ($data, $boundary): RightsDeclaration {
            $data = $this->validate($data, $boundary);
            $boundary->lockTracks([$data['track_id']]);
            $created = RightsDeclaration::create($data + ['status' => 'pending', 'verified_by' => null, 'verified_at' => null]);
            $this->audit('rights.declaration.created', $created, $current, self::FIELDS, null, $boundary);

            return $created;
        });
    }

    public function review(RightsDeclaration $record, User $actor, string $intent = 'edit'): array
    {
        $boundary = app(RightsDeclarationBoundary::class);

        return $boundary->transaction($actor, function (User $current) use ($record, $intent, $boundary): array {
            [$locked, $track] = $boundary->lockIdentity($record);

            return $boundary->capture($locked, $track, $current, $intent);
        });
    }

    public function updateReviewed(array $review, array $data, User $actor): RightsDeclaration
    {
        $boundary = app(RightsDeclarationBoundary::class);

        return $boundary->transaction($actor, function (User $current) use ($review, $data, $boundary): RightsDeclaration {
            $boundary->validateReview($review, $current, 'edit');
            $data = $this->validate($data, $boundary);
            [$locked, $track] = $boundary->lockDeclaration($review['declaration_id'], $review['track_id'], $data['track_id']);
            $boundary->compareReview($review, $locked, $track, $current, 'edit');
            $changed = array_values(array_filter(self::FIELDS, fn (string $field) => $field === 'track_id'
                ? $boundary->id($locked->track_id, 'rights') !== $data[$field] : $locked->{$field} !== $data[$field]));
            if ($changed === []) {
                return $locked;
            }
            $before = $boundary->evidenceHash($locked);
            $locked->fill($data)->save();
            $this->audit('rights.declaration.updated', $locked, $current, $changed, $before, $boundary);

            return $locked;
        });
    }

    private function validate(array $data, RightsDeclarationBoundary $boundary): array
    {
        if (array_diff(array_keys($data), self::FIELDS) !== [] || array_diff(self::FIELDS, array_keys($data)) !== []) {
            throw ValidationException::withMessages(['rights' => 'Only the track, provenance reference and sample disclosure may be saved.']);
        }
        $data['track_id'] = $boundary->id($data['track_id'], 'track_id');
        $text = ['required', 'string', function (string $field, mixed $value, \Closure $fail): void {
            // MySQL TEXT has a byte capacity; character counts alone are not an engine-shared bound.
            if (is_string($value) && (strlen($value) > 65535 || ! mb_check_encoding($value, 'UTF-8'))) {
                $fail('The :attribute must be valid UTF-8 and fit within 65,535 bytes.');
            }
        }];

        return Validator::make($data, ['track_id' => ['required', 'integer', 'min:1'],
            'provenance_reference' => $text, 'sample_disclosure' => $text])->validate();
    }

    private function audit(string $action, RightsDeclaration $declaration, User $actor, array $changed, ?string $before, RightsDeclarationBoundary $boundary): void
    {
        AuditEvent::record($action, $declaration, ['schema_version' => 1, 'track_id' => (int) $declaration->track_id,
            'changed_fields' => $changed, 'before_hash' => $before, 'after_hash' => $boundary->evidenceHash($declaration)], $actor->id);
    }
}
