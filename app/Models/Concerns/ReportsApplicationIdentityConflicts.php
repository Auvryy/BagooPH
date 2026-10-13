<?php

namespace App\Models\Concerns;

use App\Services\ApplicationIdentityService;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

trait ReportsApplicationIdentityConflicts
{
    public function save(array $options = [])
    {
        try {
            return parent::save($options);
        } catch (QueryException $exception) {
            if ($errors = ApplicationIdentityService::conflictErrors($exception)) {
                throw ValidationException::withMessages($errors);
            }

            throw $exception;
        }
    }
}
