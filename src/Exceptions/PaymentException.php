<?php

namespace Yakupeyisan\CodeIgniter4\VirtualPos\Exceptions;

class PaymentException extends VirtualPosException
{
    protected ?string $errorCode = null;
    protected array $rawData = [];

    public function __construct(string $message, ?string $errorCode = null, array $rawData = [], int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->errorCode = $errorCode;
        $this->rawData = $rawData;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getRawData(): array
    {
        return $this->rawData;
    }
}

