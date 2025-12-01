<?php

namespace Yakupeyisan\CodeIgniter4\VirtualPos\Contracts;

use Yakupeyisan\CodeIgniter4\VirtualPos\Models\PaymentRequest;
use Yakupeyisan\CodeIgniter4\VirtualPos\Models\PaymentResponse;

interface VirtualPosInterface
{
    /**
     * Ödeme işlemi başlatır
     */
    public function pay(PaymentRequest $request): PaymentResponse;

    /**
     * 3D Secure ödeme işlemi başlatır
     */
    public function pay3D(PaymentRequest $request): PaymentResponse;

    /**
     * Ödeme durumunu sorgular
     */
    public function status(string $orderId): PaymentResponse;

    /**
     * Ödeme iptal eder
     */
    public function cancel(string $orderId, ?float $amount = null): PaymentResponse;

    /**
     * Ödeme iade eder
     */
    public function refund(string $orderId, float $amount, ?string $transactionId = null): PaymentResponse;

    /**
     * Callback'i işler (3D Secure sonrası)
     */
    public function handleCallback(array $data): PaymentResponse;

    /**
     * Taksit seçeneklerini getirir
     */
    public function getInstallments(float $amount): array;
}

