<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use App\NotificationChannel;
use App\NotificationType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class BulkNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string'],
            'type'            => ['required', new Enum(NotificationType::class)],
            'channel'         => ['required', new Enum(NotificationChannel::class)],
            'message'         => ['required', 'string', 'max:1000'],
            'recipient_ids'   => ['required', 'array', 'min:1', 'max:100000'],
            'recipient_ids.*' => ['required', 'string', 'max:255'],
        ];
    }

    public function idempotencyKey(): string
    {
        /** @var string $key */
        $key = $this->validated('idempotency_key');

        return $key;
    }

    public function type(): NotificationType
    {
        /** @var string $type */
        $type = $this->validated('type');

        return NotificationType::from($type);
    }

    public function authenticatedUser(): User
    {
        /** @var User $user */
        $user = $this->user();

        return $user;
    }

    /** @return list<string> */
    public function recipientIds(): array
    {
        /** @var list<string> $ids */
        $ids = $this->validated('recipient_ids');

        return $ids;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }
}
