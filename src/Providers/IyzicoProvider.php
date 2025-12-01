<?php

namespace Yakupeyisan\CodeIgniter4\VirtualPos\Providers;

use Yakupeyisan\CodeIgniter4\VirtualPos\Base\VirtualPosBase;
use Yakupeyisan\CodeIgniter4\VirtualPos\Exceptions\ConfigurationException;
use Yakupeyisan\CodeIgniter4\VirtualPos\Exceptions\PaymentException;
use Yakupeyisan\CodeIgniter4\VirtualPos\Models\PaymentRequest;
use Yakupeyisan\CodeIgniter4\VirtualPos\Models\PaymentResponse;

class IyzicoProvider extends VirtualPosBase
{
    protected function validateConfiguration(): void
    {
        $config = $this->config->iyzico;
        
        if (empty($config['apiKey'])) {
            throw new ConfigurationException('İyzico apiKey yapılandırılmamış');
        }
        
        if (empty($config['secretKey'])) {
            throw new ConfigurationException('İyzico secretKey yapılandırılmamış');
        }
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        $config = $this->config->iyzico;
        $baseUrl = $config['baseUrl'] ?? 'https://api.iyzipay.com';
        $url = $baseUrl . '/payment/auth';

        $data = [
            'locale' => $request->language ?? 'tr',
            'conversationId' => $request->orderId,
            'price' => number_format($request->amount, 2, '.', ''),
            'paidPrice' => number_format($request->amount, 2, '.', ''),
            'currency' => $request->currency ?? 'TRY',
            'installment' => $request->installment ? (int)$request->installment : '1',
            'paymentCard' => [
                'cardHolderName' => $request->cardHolderName,
                'cardNumber' => $this->cleanCardNumber($request->cardNumber),
                'expireMonth' => $request->cardExpiryMonth,
                'expireYear' => '20' . $request->cardExpiryYear,
                'cvc' => $request->cardCvv,
            ],
            'buyer' => [
                'id' => $request->orderId,
                'name' => $request->customerName ?? '',
                'surname' => '',
                'gsmNumber' => $request->customerPhone ?? '',
                'email' => $request->customerEmail ?? '',
                'identityNumber' => '',
                'lastLoginDate' => date('Y-m-d H:i:s'),
                'registrationDate' => date('Y-m-d H:i:s'),
                'registrationAddress' => $request->billingAddress ?? '',
                'ip' => $request->customerIp ?? $this->getClientIp(),
                'city' => $request->billingCity ?? '',
                'country' => $request->billingCountry ?? 'Turkey',
                'zipCode' => $request->billingZipCode ?? '',
            ],
            'shippingAddress' => [
                'contactName' => $request->customerName ?? '',
                'city' => $request->shippingCity ?? $request->billingCity ?? '',
                'country' => $request->shippingCountry ?? $request->billingCountry ?? 'Turkey',
                'address' => $request->shippingAddress ?? $request->billingAddress ?? '',
                'zipCode' => $request->shippingZipCode ?? $request->billingZipCode ?? '',
            ],
            'billingAddress' => [
                'contactName' => $request->customerName ?? '',
                'city' => $request->billingCity ?? '',
                'country' => $request->billingCountry ?? 'Turkey',
                'address' => $request->billingAddress ?? '',
                'zipCode' => $request->billingZipCode ?? '',
            ],
            'basketItems' => [],
        ];

        // Sepet ürünleri
        if (!empty($request->items)) {
            foreach ($request->items as $item) {
                $data['basketItems'][] = [
                    'id' => $item['code'] ?? uniqid(),
                    'name' => $item['name'],
                    'category1' => 'Genel',
                    'itemType' => 'PHYSICAL',
                    'price' => number_format($item['price'] * $item['quantity'], 2, '.', ''),
                ];
            }
        } else {
            $data['basketItems'][] = [
                'id' => $request->orderId,
                'name' => $request->description ?? 'Ödeme',
                'category1' => 'Genel',
                'itemType' => 'PHYSICAL',
                'price' => number_format($request->amount, 2, '.', ''),
            ];
        }

        try {
            $response = $this->postJson($url, $data);
            
            if (isset($response['status']) && $response['status'] === 'success') {
                return PaymentResponse::success(
                    $response['paymentId'] ?? $request->orderId,
                    $request->orderId,
                    $response['statusMessage'] ?? 'Ödeme başarılı',
                    $response
                );
            }

            return PaymentResponse::failed(
                $response['errorMessage'] ?? 'Ödeme başarısız',
                $response['errorCode'] ?? null,
                $request->orderId,
                $response
            );
        } catch (\Exception $e) {
            return PaymentResponse::failed($e->getMessage(), null, $request->orderId);
        }
    }

    public function pay3D(PaymentRequest $request): PaymentResponse
    {
        $config = $this->config->iyzico;
        $baseUrl = $config['baseUrl'] ?? 'https://api.iyzipay.com';
        $url = $baseUrl . '/payment/3dsecure/initialize';

        $data = [
            'locale' => $request->language ?? 'tr',
            'conversationId' => $request->orderId,
            'price' => number_format($request->amount, 2, '.', ''),
            'paidPrice' => number_format($request->amount, 2, '.', ''),
            'currency' => $request->currency ?? 'TRY',
            'installment' => $request->installment ? (int)$request->installment : '1',
            'paymentCard' => [
                'cardHolderName' => $request->cardHolderName,
                'cardNumber' => $this->cleanCardNumber($request->cardNumber),
                'expireMonth' => $request->cardExpiryMonth,
                'expireYear' => '20' . $request->cardExpiryYear,
                'cvc' => $request->cardCvv,
            ],
            'buyer' => [
                'id' => $request->orderId,
                'name' => $request->customerName ?? '',
                'surname' => '',
                'gsmNumber' => $request->customerPhone ?? '',
                'email' => $request->customerEmail ?? '',
                'identityNumber' => '',
                'lastLoginDate' => date('Y-m-d H:i:s'),
                'registrationDate' => date('Y-m-d H:i:s'),
                'registrationAddress' => $request->billingAddress ?? '',
                'ip' => $request->customerIp ?? $this->getClientIp(),
                'city' => $request->billingCity ?? '',
                'country' => $request->billingCountry ?? 'Turkey',
                'zipCode' => $request->billingZipCode ?? '',
            ],
            'shippingAddress' => [
                'contactName' => $request->customerName ?? '',
                'city' => $request->shippingCity ?? $request->billingCity ?? '',
                'country' => $request->shippingCountry ?? $request->billingCountry ?? 'Turkey',
                'address' => $request->shippingAddress ?? $request->billingAddress ?? '',
                'zipCode' => $request->shippingZipCode ?? $request->billingZipCode ?? '',
            ],
            'billingAddress' => [
                'contactName' => $request->customerName ?? '',
                'city' => $request->billingCity ?? '',
                'country' => $request->billingCountry ?? 'Turkey',
                'address' => $request->billingAddress ?? '',
                'zipCode' => $request->billingZipCode ?? '',
            ],
            'callbackUrl' => $this->getCallbackUrl('callback'),
            'basketItems' => [],
        ];

        // Sepet ürünleri
        if (!empty($request->items)) {
            foreach ($request->items as $item) {
                $data['basketItems'][] = [
                    'id' => $item['code'] ?? uniqid(),
                    'name' => $item['name'],
                    'category1' => 'Genel',
                    'itemType' => 'PHYSICAL',
                    'price' => number_format($item['price'] * $item['quantity'], 2, '.', ''),
                ];
            }
        } else {
            $data['basketItems'][] = [
                'id' => $request->orderId,
                'name' => $request->description ?? 'Ödeme',
                'category1' => 'Genel',
                'itemType' => 'PHYSICAL',
                'price' => number_format($request->amount, 2, '.', ''),
            ];
        }

        try {
            $response = $this->postJson($url, $data);
            
            if (isset($response['status']) && $response['status'] === 'success') {
                return PaymentResponse::pending(
                    $request->orderId,
                    $response['threeDSHtmlContent'] ?? null,
                    $response['threeDSHtmlContent'] ?? null,
                    $response
                );
            }

            return PaymentResponse::failed(
                $response['errorMessage'] ?? '3D Secure başlatılamadı',
                $response['errorCode'] ?? null,
                $request->orderId,
                $response
            );
        } catch (\Exception $e) {
            return PaymentResponse::failed($e->getMessage(), null, $request->orderId);
        }
    }

    public function status(string $orderId): PaymentResponse
    {
        $config = $this->config->iyzico;
        $baseUrl = $config['baseUrl'] ?? 'https://api.iyzipay.com';
        $url = $baseUrl . '/payment/detail';

        $data = [
            'locale' => 'tr',
            'conversationId' => $orderId,
            'paymentId' => $orderId,
        ];

        try {
            $response = $this->postJson($url, $data);
            
            if (isset($response['status']) && $response['status'] === 'success') {
                $paymentStatus = $response['paymentStatus'] ?? 'FAILURE';
                if ($paymentStatus === 'SUCCESS') {
                    return PaymentResponse::success(
                        $response['paymentId'] ?? $orderId,
                        $orderId,
                        'Ödeme başarılı',
                        $response
                    );
                }
            }

            return PaymentResponse::failed(
                $response['errorMessage'] ?? 'Ödeme bulunamadı',
                $response['errorCode'] ?? null,
                $orderId,
                $response
            );
        } catch (\Exception $e) {
            return PaymentResponse::failed($e->getMessage(), null, $orderId);
        }
    }

    public function cancel(string $orderId, ?float $amount = null): PaymentResponse
    {
        $config = $this->config->iyzico;
        $baseUrl = $config['baseUrl'] ?? 'https://api.iyzipay.com';
        $url = $baseUrl . '/payment/cancel';

        $data = [
            'locale' => 'tr',
            'conversationId' => $orderId,
            'paymentId' => $orderId,
        ];

        if ($amount) {
            $data['ip'] = $this->getClientIp();
            $data['price'] = number_format($amount, 2, '.', '');
        }

        try {
            $response = $this->postJson($url, $data);
            
            if (isset($response['status']) && $response['status'] === 'success') {
                return PaymentResponse::success(
                    $response['paymentId'] ?? $orderId,
                    $orderId,
                    'İptal işlemi başarılı',
                    $response
                );
            }

            return PaymentResponse::failed(
                $response['errorMessage'] ?? 'İptal işlemi başarısız',
                $response['errorCode'] ?? null,
                $orderId,
                $response
            );
        } catch (\Exception $e) {
            return PaymentResponse::failed($e->getMessage(), null, $orderId);
        }
    }

    public function refund(string $orderId, float $amount, ?string $transactionId = null): PaymentResponse
    {
        $config = $this->config->iyzico;
        $baseUrl = $config['baseUrl'] ?? 'https://api.iyzipay.com';
        $url = $baseUrl . '/payment/refund';

        $data = [
            'locale' => 'tr',
            'conversationId' => $orderId,
            'paymentTransactionId' => $transactionId ?? $orderId,
            'price' => number_format($amount, 2, '.', ''),
            'ip' => $this->getClientIp(),
        ];

        try {
            $response = $this->postJson($url, $data);
            
            if (isset($response['status']) && $response['status'] === 'success') {
                return PaymentResponse::success(
                    $response['paymentId'] ?? $orderId,
                    $orderId,
                    'İade işlemi başarılı',
                    $response
                );
            }

            return PaymentResponse::failed(
                $response['errorMessage'] ?? 'İade işlemi başarısız',
                $response['errorCode'] ?? null,
                $orderId,
                $response
            );
        } catch (\Exception $e) {
            return PaymentResponse::failed($e->getMessage(), null, $orderId);
        }
    }

    public function handleCallback(array $data): PaymentResponse
    {
        $config = $this->config->iyzico;
        $baseUrl = $config['baseUrl'] ?? 'https://api.iyzipay.com';
        $url = $baseUrl . '/payment/3dsecure/auth';

        $data['locale'] = 'tr';

        try {
            $response = $this->postJson($url, $data);
            
            if (isset($response['status']) && $response['status'] === 'success') {
                return PaymentResponse::success(
                    $response['paymentId'] ?? $data['conversationId'] ?? '',
                    $data['conversationId'] ?? '',
                    'Ödeme başarılı',
                    $response
                );
            }

            return PaymentResponse::failed(
                $response['errorMessage'] ?? 'Ödeme başarısız',
                $response['errorCode'] ?? null,
                $data['conversationId'] ?? null,
                $response
            );
        } catch (\Exception $e) {
            return PaymentResponse::failed($e->getMessage(), null, $data['conversationId'] ?? null);
        }
    }

    public function getInstallments(float $amount): array
    {
        $config = $this->config->iyzico;
        $baseUrl = $config['baseUrl'] ?? 'https://api.iyzipay.com';
        $url = $baseUrl . '/payment/installment';

        $data = [
            'locale' => 'tr',
            'binNumber' => '540667',
            'price' => number_format($amount, 2, '.', ''),
        ];

        try {
            $response = $this->postJson($url, $data);
            
            if (isset($response['status']) && $response['status'] === 'success') {
                return $response['installmentDetails'] ?? [];
            }
            
            return [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * JSON POST isteği gönderir (İyzico için)
     */
    private function postJson(string $url, array $data): array
    {
        $config = $this->config->iyzico;
        
        // Authorization header oluştur
        $randomString = $this->generateRandomString();
        $requestString = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signature = $this->generateIyzicoSignature($config['apiKey'], $config['secretKey'], $randomString, $requestString);

        try {
            $response = $this->client->request('POST', $url, [
                'json' => $data,
                'headers' => [
                    'Authorization' => 'IYZWS ' . $config['apiKey'] . ':' . $signature,
                    'x-iyzi-rnd' => $randomString,
                    'x-iyzi-client-version' => 'iyzipay-php-2.0.50',
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

    /**
     * İyzico signature oluşturur
     */
    private function generateIyzicoSignature(string $apiKey, string $secretKey, string $randomString, string $requestString): string
    {
        $hash = base64_encode(sha1($randomString . $secretKey . $requestString, true));
        return $hash;
    }

    /**
     * Rastgele string oluşturur
     */
    private function generateRandomString(int $length = 8): string
    {
        return bin2hex(random_bytes($length / 2));
    }
}

