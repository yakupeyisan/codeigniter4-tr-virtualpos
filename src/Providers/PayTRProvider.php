<?php

namespace Yakupeyisan\CodeIgniter4\VirtualPos\Providers;

use Yakupeyisan\CodeIgniter4\VirtualPos\Base\VirtualPosBase;
use Yakupeyisan\CodeIgniter4\VirtualPos\Exceptions\ConfigurationException;
use Yakupeyisan\CodeIgniter4\VirtualPos\Models\PaymentRequest;
use Yakupeyisan\CodeIgniter4\VirtualPos\Models\PaymentResponse;

class PayTRProvider extends VirtualPosBase
{
    protected function validateConfiguration(): void
    {
        $config = $this->config->paytr;
        
        if (empty($config['merchantId'])) {
            throw new ConfigurationException('PayTR merchantId yapılandırılmamış');
        }
        
        if (empty($config['merchantKey'])) {
            throw new ConfigurationException('PayTR merchantKey yapılandırılmamış');
        }
        
        if (empty($config['merchantSalt'])) {
            throw new ConfigurationException('PayTR merchantSalt yapılandırılmamış');
        }
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        return $this->pay3D($request);
    }

    public function pay3D(PaymentRequest $request): PaymentResponse
    {
        $config = $this->config->paytr;
        $url = $this->isTestMode() ? $config['testUrl'] : $config['productionUrl'];

        $merchantId = $config['merchantId'];
        $merchantKey = $config['merchantKey'];
        $merchantSalt = $config['merchantSalt'];

        $email = $request->customerEmail ?? '';
        $paymentAmount = (int)($request->amount * 100); // Kuruş cinsinden
        $merchantOid = $request->orderId;
        $userName = $request->customerName ?? '';
        $userAddress = $request->billingAddress ?? '';
        $userPhone = $request->customerPhone ?? '';
        $userBasket = base64_encode(json_encode($this->prepareBasket($request)));
        $userIp = $request->customerIp ?? $this->getClientIp();
        $installmentCount = $request->installment ? (int)$request->installment : 0;
        $currency = $request->currency ?? 'TL';

        // Hash oluştur
        $hashStr = $merchantId . $userIp . $merchantOid . $email . $paymentAmount . $userBasket . $installmentCount . $currency . $merchantSalt;
        $token = base64_encode(hash_hmac('sha256', $hashStr, $merchantKey, true));

        $data = [
            'merchant_id' => $merchantId,
            'user_ip' => $userIp,
            'merchant_oid' => $merchantOid,
            'email' => $email,
            'payment_amount' => $paymentAmount,
            'paytr_token' => $token,
            'user_basket' => $userBasket,
            'debug_on' => $this->isTestMode() ? 1 : 0,
            'no_installment' => $installmentCount === 0 ? 1 : 0,
            'max_installment' => $installmentCount > 0 ? $installmentCount : 0,
            'user_name' => $userName,
            'user_address' => $userAddress,
            'user_phone' => $userPhone,
            'merchant_ok_url' => $this->getCallbackUrl('success'),
            'merchant_fail_url' => $this->getCallbackUrl('fail'),
            'timeout_limit' => 30,
            'currency' => $currency,
        ];

        try {
            $response = $this->post($url, $data);
            
            // PayTR response string formatında gelebilir
            if (is_string($response)) {
                parse_str($response, $response);
            }
            
            if (isset($response['status']) && $response['status'] === 'success') {
                $iframeUrl = 'https://www.paytr.com/odeme/guvenli/' . $response['token'];
                return PaymentResponse::pending(
                    $request->orderId,
                    $iframeUrl,
                    $this->buildIframe($iframeUrl),
                    $response
                );
            }

            return PaymentResponse::failed(
                $response['reason'] ?? 'Ödeme başlatılamadı',
                null,
                $request->orderId,
                $response
            );
        } catch (\Exception $e) {
            return PaymentResponse::failed($e->getMessage(), null, $request->orderId);
        }
    }

    public function status(string $orderId): PaymentResponse
    {
        // PayTR'de status sorgulama için özel endpoint gerekebilir
        throw new \RuntimeException('Status sorgulama bu provider için desteklenmiyor');
    }

    public function cancel(string $orderId, ?float $amount = null): PaymentResponse
    {
        throw new \RuntimeException('İptal işlemi bu provider için desteklenmiyor');
    }

    public function refund(string $orderId, float $amount, ?string $transactionId = null): PaymentResponse
    {
        $config = $this->config->paytr;
        $url = 'https://www.paytr.com/odeme/iade';

        $merchantId = $config['merchantId'];
        $merchantKey = $config['merchantKey'];
        $merchantSalt = $config['merchantSalt'];

        $merchantOid = $orderId;
        $returnAmount = (int)($amount * 100);

        // Hash oluştur
        $hashStr = $merchantId . $merchantOid . $returnAmount . $merchantSalt;
        $hash = base64_encode(hash_hmac('sha256', $hashStr, $merchantKey, true));

        $data = [
            'merchant_id' => $merchantId,
            'merchant_oid' => $merchantOid,
            'return_amount' => $returnAmount,
            'hash' => $hash,
        ];

        try {
            $response = $this->post($url, $data);
            
            // PayTR response string formatında gelebilir
            if (is_string($response)) {
                parse_str($response, $response);
            }
            
            if (isset($response['status']) && $response['status'] === 'success') {
                return PaymentResponse::success(
                    $response['merchant_oid'] ?? $orderId,
                    $orderId,
                    'İade işlemi başarılı',
                    $response
                );
            }

            return PaymentResponse::failed(
                $response['reason'] ?? 'İade işlemi başarısız',
                null,
                $orderId,
                $response
            );
        } catch (\Exception $e) {
            return PaymentResponse::failed($e->getMessage(), null, $orderId);
        }
    }

    public function handleCallback(array $data): PaymentResponse
    {
        $config = $this->config->paytr;
        $merchantKey = $config['merchantKey'];
        $merchantSalt = $config['merchantSalt'];

        $merchantOid = $data['merchant_oid'] ?? '';
        $status = $data['status'] ?? '';
        $totalAmount = $data['total_amount'] ?? '';
        $hash = $data['hash'] ?? '';

        // Hash doğrulama
        $hashStr = $merchantOid . $merchantSalt . $status . $totalAmount;
        $calculatedHash = base64_encode(hash_hmac('sha256', $hashStr, $merchantKey, true));

        if ($calculatedHash !== $hash) {
            return PaymentResponse::failed('Hash doğrulama başarısız', null, $merchantOid, $data);
        }

        if ($status === 'success') {
            return PaymentResponse::success(
                $data['payment_id'] ?? $merchantOid,
                $merchantOid,
                'Ödeme başarılı',
                $data
            );
        }

        return PaymentResponse::failed(
            $data['failed_reason_code'] ?? 'Ödeme başarısız',
            $data['failed_reason_msg'] ?? null,
            $merchantOid,
            $data
        );
    }

    public function getInstallments(float $amount): array
    {
        // PayTR taksit bilgileri genellikle iframe içinde gösterilir
        return [];
    }

    /**
     * Sepet bilgisini hazırlar
     */
    private function prepareBasket(PaymentRequest $request): array
    {
        if (!empty($request->items)) {
            $basket = [];
            foreach ($request->items as $item) {
                $basket[] = [
                    $item['name'],
                    number_format($item['price'] * $item['quantity'], 2, '.', ''),
                    $item['quantity'],
                ];
            }
            return $basket;
        }

        return [
            [$request->description ?? 'Ödeme', number_format($request->amount, 2, '.', ''), 1],
        ];
    }

    /**
     * Iframe HTML oluşturur
     */
    private function buildIframe(string $url): string
    {
        return '<iframe src="' . htmlspecialchars($url) . '" width="100%" height="600" frameborder="0" scrolling="no"></iframe>';
    }
}

