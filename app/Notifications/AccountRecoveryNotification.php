<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;

class AccountRecoveryNotification extends ResetPassword
{
    public function __construct(#[\SensitiveParameter] string $token, public string $recipient)
    {
        parent::__construct($token);
    }
}
