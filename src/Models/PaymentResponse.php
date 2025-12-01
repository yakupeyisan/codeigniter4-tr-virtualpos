<?php

namespace Yakupeyisan\CodeIgniter4\VirtualPos\Models;

class PaymentResponse
{
    public bool $success;
    public string $status; // success, failed, pending, cancelled
    public ?string $transactionId = null;
    public ?string $orderId = null;
    public ?string $message = null;
    public ?string $errorCode = null;
    public ?string $errorMessage = null;
    public ?string $redirectUrl = null;
    public ?string $htmlContent = null;
    public array $rawData = [];
    public ?float $amount = null;
    public ?string $currency = null;
    public ?string $cardMask = null;
    public ?string $installment = null;
    public ?string $paymentDate = null;
    public array $metadata = [];

    public function __construct(bool $success = false, string $status = 'failed')
    {
        $this->success = $success;
        $this->status = $status;
    }

    public static function success(
        string $transactionId,
        string $orderId,
        ?string $message = null,
        array $rawData = []
    ): self {
        $response = new self(true, 'success');
        $response->transactionId = $transactionId;
        $response->orderId = $orderId;
        $response->message = $message ?? 'Ödeme başarılı';
        $response->rawData = $rawData;
        return $response;
    }

    public static function failed(
        string $errorMessage,
        ?string $errorCode = null,
        ?string $orderId = null,
        array $rawData = []
    ): self {
        $response = new self(false, 'failed');
        $response->errorMessage = $errorMessage;
        $response->errorCode = $errorCode;
        $response->orderId = $orderId;
        $response->rawData = $rawData;
        return $response;
    }

    public static function pending(
        string $orderId,
        ?string $redirectUrl = null,
        ?string $htmlContent = null,
        array $rawData = []
    ): self {
        $response = new self(false, 'pending');
        $response->orderId = $orderId;
        $response->redirectUrl = $redirectUrl;
        $response->htmlContent = $htmlContent;
        $response->rawData = $rawData;
        return $response;
    }
}

