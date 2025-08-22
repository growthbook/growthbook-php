<?php

namespace Growthbook;

class FeatureFetchException extends \Exception
{
    const NO_RESPONSE_ERROR = 'NO_RESPONSE_ERROR';
    const INVALID_RESPONSE = 'INVALID_RESPONSE';
    const NETWORK_ERROR = 'NETWORK_ERROR';
    const HTTP_RESPONSE_ERROR = 'HTTP_RESPONSE_ERROR';
    const UNAUTHORIZED = 'UNAUTHORIZED';
    const FORBIDDEN = 'FORBIDDEN';
    const NOT_FOUND = 'NOT_FOUND';
    const SERVER_ERROR = 'SERVER_ERROR';
    const UNKNOWN = 'UNKNOWN';

    private string $errorCode;

    /**
     * @param string $errorCode
     * @param string $message
     * @param \Throwable|null $previous
     */
    public function __construct(string $errorCode, string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
    }

    /**
     * @return string
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}