<?php

namespace App\Exceptions;

use App\Exceptions\Api\ApiException;

class NoPromotionFoundException extends ApiException
{
    protected $errorCode = 'no_promotion_found';
    protected $message = 'Promotion not found.';
    protected int $httpStatusCode = 404;
}
