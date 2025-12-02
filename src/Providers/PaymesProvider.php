<?php

namespace Yakupeyisan\CodeIgniter4\VirtualPos\Providers;

use Yakupeyisan\CodeIgniter4\VirtualPos\Base\VirtualPosBase;
use Yakupeyisan\CodeIgniter4\VirtualPos\Exceptions\ConfigurationException;
use Yakupeyisan\CodeIgniter4\VirtualPos\Models\PaymentRequest;
use Yakupeyisan\CodeIgniter4\VirtualPos\Models\PaymentResponse;

class PaymesProvider extends VirtualPosBase
{
    protected function validateConfiguration(): void
    {
        $config = $this->getAccountConfig();
        
        if (empty($config['apiKey'])) {
            throw new ConfigurationException('Paymes apiKey yapılandırılmamış');
        }
        
        if (empty($config['secretKey'])) {
            throw new ConfigurationException('Paymes secretKey yapılandırılmamış');
        }
        
        if (empty($config['merchantId'])) {
            throw new ConfigurationException('Paymes merchantId yapılandırılmamış');
        }
    }

    /**
     * Aktif hesap yapılandırmasını döndürür
     */
    protected function getAccountConfig(): array
    {
        $providerConfig = $this->config->paymes;
        $accounts = $providerConfig['accounts'] ?? [];
        $accountId = $this->accountId ?? $providerConfig['defaultAccount'] ?? 'default';
        
        if (!isset($accounts[$accountId])) {
            throw new ConfigurationException("Paymes account '{$accountId}' bulunamadı");
        }
        
        return $accounts[$accountId];
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        $config = $this->getAccountConfig();
        $baseUrl = $config['baseUrl'] ?? 'https://api.paymes.com';
        $url = $baseUrl . '/api/payment/create';

        $data = [
            'merchant_id' => $config['merchantId'],
            'order_id' => $request->orderId,
            'amount' => number_format($request->amount, 2, '.', ''),
            'currency' => $request->currency ?? 'TRY',
            'installment' => $request->installment ? (int)$request->installment : 1,
            'card_number' => $this->cleanCardNumber($request->cardNumber),
            'card_holder_name' => $request->cardHolderName,
            'card_expiry_month' => $request->cardExpiryMonth,
            'card_expiry_year' => '20' . $request->cardExpiryYear,
            'card_cvv' => $request->cardCvv,
            'customer_name' => $request->customerName ?? '',
            'customer_email' => $request->customerEmail ?? '',
            'customer_phone' => $request->customerPhone ?? '',
            'customer_ip' => $request->customerIp ?? $this->getClientIp(),
            'billing_address' => $request->billingAddress ?? '',
            'billing_city' => $request->billingCity ?? '',
            'billing_country' => $request->billingCountry ?? 'TR',
            'billing_zip_code' => $request->billingZipCode ?? '',
            'success_url' => $this->getCallbackUrl('success'),
            'fail_url' => $this->getCallbackUrl('fail'),
            'callback_url' => $this->getCallbackUrl('callback'),
        ];

        try {
            $response = $this->postJson($url, $data);
            
            if (isset($response['status']) && $response['status'] === 'success') {
                return PaymentResponse::success(
                    $response['transaction_id'] ?? $request->orderId,
                    $request->orderId,
                    'Ödeme başarılı',
                    $response
                );
            }

            return PaymentResponse::failed(
                $response['message'] ?? 'Ödeme başarısız',
                $response['error_code'] ?? null,
                $request->orderId,
                $response
            );
        } catch (\Exception $e) {
            return PaymentResponse::failed($e->getMessage(), null, $request->orderId);
        }
    }

    public function pay3D(PaymentRequest $request): PaymentResponse
    {
        $config = $this->getAccountConfig();
        $baseUrl = $config['baseUrl'] ?? 'https://api.paymes.com';
        $url = $baseUrl . '/api/payment/3d/create';

        $data = [
            'merchant_id' => $config['merchantId'],
            'order_id' => $request->orderId,
            'amount' => number_format($request->amount, 2, '.', ''),
            'currency' => $request->currency ?? 'TRY',
            'installment' => $request->installment ? (int)$request->installment : 1,
            'card_number' => $this->cleanCardNumber($request->cardNumber),
            'card_holder_name' => $request->cardHolderName,
            'card_expiry_month' => $request->cardExpiryMonth,
            'card_expiry_year' => '20' . $request->cardExpiryYear,
            'card_cvv' => $request->cardCvv,
            'customer_name' => $request->customerName ?? '',
            'customer_email' => $request->customerEmail ?? '',
            'customer_phone' => $request->customerPhone ?? '',
            'customer_ip' => $request->customerIp ?? $this->getClientIp(),
            'billing_address' => $request->billingAddress ?? '',
            'billing_city' => $request->billingCity ?? '',
            'billing_country' => $request->billingCountry ?? 'TR',
            'billing_zip_code' => $request->billingZipCode ?? '',
            'success_url' => $this->getCallbackUrl('success'),
            'fail_url' => $this->getCallbackUrl('fail'),
            'callback_url' => $this->getCallbackUrl('callback'),
        ];

        try {
            $response = $this->postJson($url, $data);
            
            if (isset($response['status']) && $response['status'] === 'success') {
                return PaymentResponse::pending(
                    $request->orderId,
                    $response['redirect_url'] ?? null,
                    $response['html_content'] ?? null,
                    $response
                );
            }

            return PaymentResponse::failed(
                $response['message'] ?? '3D Secure başlatılamadı',
                $response['error_code'] ?? null,
                $request->orderId,
                $response
            );
        } catch (\Exception $e) {
            return PaymentResponse::failed($e->getMessage(), null, $request->orderId);
        }
    }

    public function status(string $orderId): PaymentResponse
    {
        $config = $this->getAccountConfig();
        $baseUrl = $config['baseUrl'] ?? 'https://api.paymes.com';
        $url = $baseUrl . '/api/payment/status';

        $data = [
            'merchant_id' => $config['merchantId'],
            'order_id' => $orderId,
        ];

        try {
            $response = $this->postJson($url, $data);
            
            if (isset($response['status']) && $response['status'] === 'success') {
                $paymentStatus = $response['payment_status'] ?? 'failed';
                if ($paymentStatus === 'success' || $paymentStatus === 'approved') {
                    return PaymentResponse::success(
                        $response['transaction_id'] ?? $orderId,
                        $orderId,
                        'Ödeme başarılı',
                        $response
                    );
                }
            }

            return PaymentResponse::failed(
                $response['message'] ?? 'Ödeme bulunamadı',
                $response['error_code'] ?? null,
                $orderId,
                $response
            );
        } catch (\Exception $e) {
            return PaymentResponse::failed($e->getMessage(), null, $orderId);
        }
    }

    public function cancel(string $orderId, ?float $amount = null): PaymentResponse
    {
        $config = $this->getAccountConfig();
        $baseUrl = $config['baseUrl'] ?? 'https://api.paymes.com';
        $url = $baseUrl . '/api/payment/cancel';

        $data = [
            'merchant_id' => $config['merchantId'],
            'order_id' => $orderId,
        ];

        if ($amount) {
            $data['amount'] = number_format($amount, 2, '.', '');
        }

        try {
            $response = $this->postJson($url, $data);
            
            if (isset($response['status']) && $response['status'] === 'success') {
                return PaymentResponse::success(
                    $response['transaction_id'] ?? $orderId,
                    $orderId,
                    'İptal işlemi başarılı',
                    $response
                );
            }

            return PaymentResponse::failed(
                $response['message'] ?? 'İptal işlemi başarısız',
                $response['error_code'] ?? null,
                $orderId,
                $response
            );
        } catch (\Exception $e) {
            return PaymentResponse::failed($e->getMessage(), null, $orderId);
        }
    }

    public function refund(string $orderId, float $amount, ?string $transactionId = null): PaymentResponse
    {
        $config = $this->getAccountConfig();
        $baseUrl = $config['baseUrl'] ?? 'https://api.paymes.com';
        $url = $baseUrl . '/api/payment/refund';

        $data = [
            'merchant_id' => $config['merchantId'],
            'order_id' => $orderId,
            'amount' => number_format($amount, 2, '.', ''),
        ];

        if ($transactionId) {
            $data['transaction_id'] = $transactionId;
        }

        try {
            $response = $this->postJson($url, $data);
            
            if (isset($response['status']) && $response['status'] === 'success') {
                return PaymentResponse::success(
                    $response['transaction_id'] ?? $orderId,
                    $orderId,
                    'İade işlemi başarılı',
                    $response
                );
            }

            return PaymentResponse::failed(
                $response['message'] ?? 'İade işlemi başarısız',
                $response['error_code'] ?? null,
                $orderId,
                $response
            );
        } catch (\Exception $e) {
            return PaymentResponse::failed($e->getMessage(), null, $orderId);
        }
    }

    public function handleCallback(array $data): PaymentResponse
    {
        $config = $this->getAccountConfig();
        
        // Hash doğrulama
        $hash = $data['hash'] ?? '';
        $orderId = $data['order_id'] ?? '';
        
        $hashData = $config['merchantId'] . $orderId . $data['amount'] ?? '' . $config['secretKey'];
        $calculatedHash = hash('sha256', $hashData);
        
        if ($calculatedHash !== $hash) {
            return PaymentResponse::failed('Hash doğrulama başarısız', null, $orderId, $data);
        }

        $status = $data['status'] ?? '';
        
        if ($status === 'success' || $status === 'approved') {
            return PaymentResponse::success(
                $data['transaction_id'] ?? $orderId,
                $orderId,
                'Ödeme başarılı',
                $data
            );
        }

        return PaymentResponse::failed(
            $data['message'] ?? 'Ödeme başarısız',
            $data['error_code'] ?? null,
            $orderId,
            $data
        );
    }

    public function getInstallments(float $amount): array
    {
        // Paymes taksit bilgileri genellikle API'den alınır
        return [];
    }

    /**
     * JSON POST isteği gönderir
     */
    private function postJson(string $url, array $data): array
    {
        $config = $this->getAccountConfig();
        
        // Authorization header
        $auth = base64_encode($config['apiKey'] . ':' . $config['secretKey']);

        try {
            $response = $this->client->request('POST', $url, [
                'json' => $data,
                'headers' => [
                    'Authorization' => 'Basic ' . $auth,
                    'Content-Type' => 'application/json',
                ],
                'timeout' => $this->config->timeout,
                'verify' => true,
            ]);

            $body = $response->getBody();
            return json_decode($body, true) ?? [];
        } catch (\Exception $e) {
            throw new \RuntimeException('HTTP isteği başarısız: ' . $e->getMessage());
        }
    }
}

