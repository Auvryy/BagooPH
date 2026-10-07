<?php

namespace App\Http\Requests;

use App\Services\ProfileInputService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->fresh()?->canAccessPortal() ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($user = $this->user()) {
            $this->merge(app(ProfileInputService::class)->normalize($this->all(), $user));
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return app(ProfileInputService::class)->rules($this->user(), ['name', 'email']);
    }
}
