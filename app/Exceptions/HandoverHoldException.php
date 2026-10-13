<?php

namespace App\Exceptions;

use DomainException;

class HandoverHoldException extends DomainException
{
    public function __construct()
    {
        parent::__construct('This parcel is on a reviewed handover hold. Complete its authorized recovery before changing it.');
    }
}
