<?php

namespace Yakupeyisan\CodeIgniter4\VirtualPos\Providers;

use Yakupeyisan\CodeIgniter4\VirtualPos\Base\VirtualPosBase;
use Yakupeyisan\CodeIgniter4\VirtualPos\Exceptions\ConfigurationException;
use Yakupeyisan\CodeIgniter4\VirtualPos\Exceptions\PaymentException;
use Yakupeyisan\CodeIgniter4\VirtualPos\Models\PaymentRequest;
use Yakupeyisan\CodeIgniter4\VirtualPos\Models\PaymentResponse;

class Get724Provider extends VirtualPosBase
{
    protected function validateConfiguration(): void
    {
        $config = $this->config->get724;
        
        if (empty($config['clientId'])) {
            throw new ConfigurationException('Get724 clientId yapılandırılmamış');
        }
        
        if (empty($config['storeKey'])) {
            throw new ConfigurationException('Get724 storeKey yapılandırılmamış');
        }
        
        if (empty($config['bank'])) {
            throw new ConfigurationException('Get724 bank yapılandırılmamış');
        }
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        return $this->pay3D($request);
    }

    public function pay3D(PaymentRequest $request): PaymentResponse
    {
        $config = $this->config->get724;
        
        // Banka tipine göre URL belirle
        $url = $this->getPaymentUrl($config['bank']);
        
        $data = [
            'clientid' => $config['clientId'],
            'storetype' => $config['storeType'] ?? '3d',
            'amount' => $this->formatAmount($request->amount),
            'oid' => $request->orderId,
            'okUrl' => $this->getCallbackUrl('success'),
            'failUrl' => $this->getCallbackUrl('fail'),
            'rnd' => time(),
            'currency' => $this->getCurrencyCode($request->currency ?? 'TRY'),
            'taksit' => $request->installment ?? '',
            'islemtipi' => 'Auth',
            'hashAlgorithm' => 'ver3',
        ];

        // Hash oluştur
        $hashData = $config['storeKey'] . $data['clientid'] . $data['oid'] . $data['amount'] . 
                   $data['okUrl'] . $data['failUrl'] . $data['islemtipi'] . $data['taksit'] . 
                   $data['rnd'] . $data['currency'];
        $data['hash'] = base64_encode(pack('H*', sha1($hashData)));

        // Müşteri bilgileri
        if ($request->customerName) {
            $data['fname'] = $request->customerName;
        }
        if ($request->customerEmail) {
            $data['email'] = $request->customerEmail;
        }
        if ($request->customerPhone) {
            $data['tel'] = $request->customerPhone;
        }
        if ($request->billingAddress) {
            $data['BillToStreet1'] = $request->billingAddress;
            $data['BillToCity'] = $request->billingCity ?? '';
            $data['BillToCountry'] = $request->billingCountry ?? 'TR';
            $data['BillToPostalCode'] = $request->billingZipCode ?? '';
        }

        // Vakıfbank için özel parametreler
        if ($config['bank'] === 'vakifbank') {
            $data['storetype'] = '3d_pay_hosting';
        }

        // HTML form oluştur
        $html = $this->buildForm($url, $data);

        return PaymentResponse::pending(
            $request->orderId,
            null,
            $html,
            $data
        );
    }

    public function status(string $orderId): PaymentResponse
    {
        $config = $this->config->get724;
        $url = $this->getApiUrl($config['bank']);

        $data = [
            'Name' => $config['clientId'],
            'Password' => $config['storeKey'],
            'ClientId' => $config['clientId'],
            'OrderId' => $orderId,
            'Type' => 'Status',
        ];

        try {
            $response = $this->post($url, $data);
            
            // Response string formatında gelebilir
            if (is_string($response)) {
                parse_str($response, $response);
            }
            
            if (isset($response['Response']) && $response['Response'] === 'Approved') {
                return PaymentResponse::success(
                    $response['TransId'] ?? $orderId,
                    $orderId,
                    'Ödeme başarılı',
                    $response
                );
            }

            return PaymentResponse::failed(
                $response['ErrMsg'] ?? 'Ödeme bulunamadı',
                $response['ProcReturnCode'] ?? null,
                $orderId,
                $response
            );
        } catch (\Exception $e) {
            return PaymentResponse::failed($e->getMessage(), null, $orderId);
        }
    }

    public function cancel(string $orderId, ?float $amount = null): PaymentResponse
    {
        $config = $this->config->get724;
        $url = $this->getApiUrl($config['bank']);

        $data = [
            'Name' => $config['clientId'],
            'Password' => $config['storeKey'],
            'ClientId' => $config['clientId'],
            'OrderId' => $orderId,
            'Type' => 'Void',
        ];

        try {
            $response = $this->post($url, $data);
            
            // Response string formatında gelebilir
            if (is_string($response)) {
                parse_str($response, $response);
            }
            
            if (isset($response['Response']) && $response['Response'] === 'Approved') {
                return PaymentResponse::success(
                    $response['TransId'] ?? $orderId,
                    $orderId,
                    'İptal işlemi başarılı',
                    $response
                );
            }

            return PaymentResponse::failed(
                $response['ErrMsg'] ?? 'İptal işlemi başarısız',
                $response['ProcReturnCode'] ?? null,
                $orderId,
                $response
            );
        } catch (\Exception $e) {
            return PaymentResponse::failed($e->getMessage(), null, $orderId);
        }
    }

    public function refund(string $orderId, float $amount, ?string $transactionId = null): PaymentResponse
    {
        $config = $this->config->get724;
        $url = $this->getApiUrl($config['bank']);

        $data = [
            'Name' => $config['clientId'],
            'Password' => $config['storeKey'],
            'ClientId' => $config['clientId'],
            'OrderId' => $orderId,
            'Type' => 'Credit',
            'Total' => $this->formatAmount($amount),
        ];

        if ($transactionId) {
            $data['TransId'] = $transactionId;
        }

        try {
            $response = $this->post($url, $data);
            
            // Response string formatında gelebilir
            if (is_string($response)) {
                parse_str($response, $response);
            }
            
            if (isset($response['Response']) && $response['Response'] === 'Approved') {
                return PaymentResponse::success(
                    $response['TransId'] ?? $orderId,
                    $orderId,
                    'İade işlemi başarılı',
                    $response
                );
            }

            return PaymentResponse::failed(
                $response['ErrMsg'] ?? 'İade işlemi başarısız',
                $response['ProcReturnCode'] ?? null,
                $orderId,
                $response
            );
        } catch (\Exception $e) {
            return PaymentResponse::failed($e->getMessage(), null, $orderId);
        }
    }

    public function handleCallback(array $data): PaymentResponse
    {
        $config = $this->config->get724;
        
        // Hash doğrulama
        $hashParams = $data['HASHPARAMS'] ?? '';
        $hashParamsVal = $data['HASHPARAMSVAL'] ?? '';
        $hash = $data['HASH'] ?? '';
        
        if (empty($hashParams) || empty($hashParamsVal) || empty($hash)) {
            return PaymentResponse::failed('Geçersiz callback verisi', null, $data['oid'] ?? null);
        }

        // Hash doğrulama
        $hashData = $hashParamsVal . $config['storeKey'];
        $calculatedHash = base64_encode(pack('H*', sha1($hashData)));
        
        if ($calculatedHash !== $hash) {
            return PaymentResponse::failed('Hash doğrulama başarısız', null, $data['oid'] ?? null);
        }

        $orderId = $data['oid'] ?? '';
        $response = $data['Response'] ?? '';
        $procReturnCode = $data['ProcReturnCode'] ?? '';
        $transId = $data['TransId'] ?? '';
        $mdStatus = $data['mdStatus'] ?? '';

        // 3D Secure doğrulama
        if ($mdStatus !== '1' && $mdStatus !== '2' && $mdStatus !== '3' && $mdStatus !== '4') {
            return PaymentResponse::failed('3D Secure doğrulama başarısız', $mdStatus, $orderId, $data);
        }

        // Ödeme durumu
        if ($response === 'Approved' && $procReturnCode === '00') {
            return PaymentResponse::success(
                $transId,
                $orderId,
                'Ödeme başarılı',
                $data
            );
        }

        $errorMsg = $data['ErrMsg'] ?? 'Ödeme başarısız';
        return PaymentResponse::failed(
            $errorMsg,
            $procReturnCode,
            $orderId,
            $data
        );
    }

    public function getInstallments(float $amount): array
    {
        // Get724 taksit bilgileri genellikle banka tarafından sağlanır
        // Bu metod bankaya özel implementasyon gerektirebilir
        return [];
    }

    /**
     * Ödeme URL'ini banka tipine göre döndürür
     */
    private function getPaymentUrl(string $bank): string
    {
        $config = $this->config->get724;
        $isTest = $this->isTestMode();
        
        // Vakıfbank için özel URL
        if ($bank === 'vakifbank') {
            return $isTest 
                ? 'https://test.get724.com.tr/vakifbank/3dgate'
                : 'https://www.get724.com.tr/vakifbank/3dgate';
        }
        
        // NestPay EST bankaları için
        return $isTest 
            ? 'https://test.get724.com.tr/nestpay/est3Dgate'
            : 'https://www.get724.com.tr/nestpay/est3Dgate';
    }

    /**
     * API URL'ini banka tipine göre döndürür
     */
    private function getApiUrl(string $bank): string
    {
        $isTest = $this->isTestMode();
        
        // Vakıfbank için özel API URL
        if ($bank === 'vakifbank') {
            return $isTest 
                ? 'https://test.get724.com.tr/vakifbank/api'
                : 'https://www.get724.com.tr/vakifbank/api';
        }
        
        // NestPay EST bankaları için
        return $isTest 
            ? 'https://test.get724.com.tr/nestpay/api'
            : 'https://www.get724.com.tr/nestpay/api';
    }

    /**
     * Para birimi kodunu döndürür
     */
    private function getCurrencyCode(string $currency): string
    {
        $currencies = [
            'TRY' => '949',
            'USD' => '840',
            'EUR' => '978',
            'GBP' => '826',
        ];
        
        return $currencies[$currency] ?? '949';
    }

    /**
     * HTML form oluşturur
     */
    private function buildForm(string $url, array $data): string
    {
        $form = '<form id="get724_form" method="post" action="' . htmlspecialchars($url) . '">';
        foreach ($data as $key => $value) {
            $form .= '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($value) . '">';
        }
        $form .= '</form>';
        $form .= '<script>document.getElementById("get724_form").submit();</script>';
        return $form;
    }
}

