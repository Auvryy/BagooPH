<?php

namespace Tests\Concerns;

use App\Services\RegistrationPolicyService;

trait WithRegistrationPolicies
{
    protected function registrationPolicies(): array
    {
        return ['policy_accepted' => true, 'terms_version' => RegistrationPolicyService::TERMS_VERSION, 'privacy_version' => RegistrationPolicyService::PRIVACY_VERSION];
    }
}
