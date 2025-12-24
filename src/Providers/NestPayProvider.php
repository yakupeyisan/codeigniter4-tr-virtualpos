<?php

namespace Yakupeyisan\CodeIgniter4\VirtualPos\Providers;

use Yakupeyisan\CodeIgniter4\VirtualPos\Base\VirtualPosBase;
use Yakupeyisan\CodeIgniter4\VirtualPos\Exceptions\ConfigurationException;
use Yakupeyisan\CodeIgniter4\VirtualPos\Exceptions\PaymentException;
use Yakupeyisan\CodeIgniter4\VirtualPos\Models\PaymentRequest;
use Yakupeyisan\CodeIgniter4\VirtualPos\Models\PaymentResponse;

class NestPayProvider extends VirtualPosBase
{
    protected function validateConfiguration(): void
    {
        $config = $this->getAccountConfig();
        
        if (empty($config['clientId'])) {
            throw new ConfigurationException('NestPay clientId yapılandırılmamış');
        }
        
        if (empty($config['storeKey'])) {
            throw new ConfigurationException('NestPay storeKey yapılandırılmamış');
        }
    }

    /**
     * Aktif hesap yapılandırmasını döndürür
     */
    protected function getAccountConfig(): array
    {
        $providerConfig = $this->config->nestpay;
        $accounts = $providerConfig['accounts'] ?? [];
        $accountId = $this->accountId ?? $providerConfig['defaultAccount'] ?? 'default';
        
        if (!isset($accounts[$accountId])) {
            throw new ConfigurationException("NestPay account '{$accountId}' bulunamadı");
        }
        
        return $accounts[$accountId];
    }

    public function pay(PaymentRequest $request): PaymentResponse
    {
        return $this->pay3D($request);
    }

    public function pay3D(PaymentRequest $request): PaymentResponse
    {
        $config = $this->getAccountConfig();
        $url = $this->isTestMode() ? $config['testUrl'] : $config['productionUrl'];
        log_message('debug','PaymentConfig: '.json_encode($config));
        $data = [
            'clientid' => $config['clientId'],
            'storetype' => $config['storeType'] ?? '3d',
            'amount' => $request->amount,
            'oid' => $request->orderId,
            'okUrl' => $this->getCallbackUrl('success'),
            'failUrl' => $this->getCallbackUrl('fail'),
            'islemtipi' => 'Auth',
            'taksit' => $request->installment ?? '',
            'callbackUrl' => $this->getCallbackUrl('callback'),
            'currency' => $request->currency ?? '949',
            'rnd' => microtime(),
            "lang" => "tr",
            "hashalgorithm" => "ver3",
            "refreshtime" => 5
        ];

        $hash = $this->createHash($data, $config['storeKey']);
        $data['hash'] = $hash;

        // HTML form oluştur
        $html = $this->buildForm($url, $data);

        return PaymentResponse::pending(
            $request->orderId,
            null,
            $html,
            $data
        );
    }
    public function createHash(array $input, string $storeKey)
    {
        $keys = array_keys($input);
        natcasesort($keys);
        $hashval = "";
        foreach ($keys as $param) {
            $paramValue = $input[$param];
            $escapedParamValue = str_replace("|", "\\|", str_replace("\\", "\\\\", $paramValue));

            $lowerParam = strtolower($param);
            if ($lowerParam != "hash" && $lowerParam != "encoding" && $lowerParam != "countdown") {
                $hashval = $hashval . $escapedParamValue . "|";
            }
        }

        $escapedStoreKey = str_replace("|", "\\|", str_replace("\\", "\\\\", $storeKey));
        $hashval = $hashval . $escapedStoreKey;

        $calculatedHashValue = hash('sha512', $hashval);
        return base64_encode(pack('H*', $calculatedHashValue));
    }

    public function status(string $orderId): PaymentResponse
    {
        $config = $this->getAccountConfig();
            $bank = strtolower($config['bank'] ?? 'isbank');
            
        return $this->checkPaymentStatus($orderId, $config);
    }

    /**
     * Ziraat Bankası için ödeme durumu sorgulama (mütabakat)
     * Optimized with better timeout handling and error checking
     * 
     * @param string $orderId Sipariş ID
     * @param array $config Hesap yapılandırması
     * @return PaymentResponse
     */
    private function checkPaymentStatus(string $orderId, array $config): PaymentResponse
    {
        log_message('debug','NestPayProvider checkPaymentStatus: '.json_encode($config));
        // Get credentials from config
        $clientName = $config['clientName'] ?? '';
        $password = $config['storeKey'] ?? '';
        $clientId = $config['clientId'] ?? '';
        $ipAddress = $this->getClientIp();
        
        // Build XML request
        $xmlRequest = "<?xml version=\"1.0\" encoding=\"ISO-8859-9\"?>
						<CC5Request>
						<Name>{NAME}</Name>
						<Password>{PASSWORD}</Password>
						<ClientId>{CLIENTID}</ClientId>
						<OrderId>{OID}</OrderId>	
						<Mode>P</Mode>
						<Extra><ORDERSTATUS>SOR</ORDERSTATUS></Extra>
						</CC5Request>";
        
        $xmlRequest = str_replace(
            ["{NAME}", "{PASSWORD}", "{CLIENTID}", "{OID}", "{IP}"],
            [$clientName, $password, $clientId, $orderId, $ipAddress],
            $xmlRequest
        );
        log_message('debug','NestPayProvider checkPaymentStatus xmlRequest: '.$xmlRequest);
        $requestData = "DATA=" . $xmlRequest;
        $url = $this->isTestMode() ? $config['testUrl'] : $config['productionUrl'];
        log_message('debug','NestPayProvider checkPaymentStatus url: '.$url);
        // Use optimized cURL with better timeout settings
        $ch = curl_init();
        
        // Basic cURL options
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestData);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        // Optimized timeout settings
        // CONNECTTIMEOUT: DNS lookup + connection time (10 seconds max)
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        // TIMEOUT: Total request time including data transfer (30 seconds max)
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        // Performance optimizations
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, false); // Reuse connections
        curl_setopt($ch, CURLOPT_FORBID_REUSE, false); // Allow connection reuse
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1); // Use HTTP/1.1
        curl_setopt($ch, CURLOPT_TCP_NODELAY, true); // Disable Nagle algorithm for faster response
        
        // Headers for better performance
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'Connection: keep-alive',
            'Cache-Control: no-cache'
        ]);
        
        // DNS caching (if available)
        if (defined('CURLOPT_DNS_CACHE_TIMEOUT')) {
            curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 300); // Cache DNS for 5 minutes
        }
        
        // Execute request
        $startTime = microtime(true);
        $result = curl_exec($ch);
        $executionTime = microtime(true) - $startTime;
        
        // Check for cURL errors
        if ($result === false) {
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);
            curl_close($ch);
            
            // Log error details
            log_message('error', "Nestpay CheckPayment cURL Error (OrderID: $orderId): [$curlErrno] $curlError | Execution time: " . round($executionTime, 2) . "s");
            
            return PaymentResponse::failed(
                "Banka API hatası: $curlError",
                (string)$curlErrno,
                $orderId
            );
        }
        
        // Get HTTP response code
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Log slow requests (more than 5 seconds)
        if ($executionTime > 5) {
            log_message('warning', "Nestpay CheckPayment slow response (OrderID: $orderId): " . round($executionTime, 2) . "s | HTTP Code: $httpCode");
        }
        
        // Check HTTP response code
        if ($httpCode !== 200) {
            log_message('error', "Nestpay CheckPayment HTTP Error (OrderID: $orderId): HTTP $httpCode | Execution time: " . round($executionTime, 2) . "s");
            return PaymentResponse::failed(
                "Banka API HTTP hatası: $httpCode",
                (string)$httpCode,
                $orderId
            );
        }
        
        // Parse XML response
        if (empty($result)) {
            log_message('error', "Nestpay CheckPayment empty response (OrderID: $orderId)");
            return PaymentResponse::failed('Banka yanıtı boş', null, $orderId);
        }
        
        try {
            // Suppress XML warnings for invalid characters
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($result);
            
            if ($xml === false) {
                $errors = libxml_get_errors();
                libxml_clear_errors();
                log_message('error', "Nestpay CheckPayment XML parse error (OrderID: $orderId): " . json_encode($errors));
                return PaymentResponse::failed('Banka yanıtı parse edilemedi', null, $orderId);
            }
            
            // Convert XML to array
            $responseData = json_decode(json_encode($xml), true);
            
            // Parse response to determine payment status
            // Ziraat Bankası response format: CC5Response -> Response, ProcReturnCode, etc.
            $response = $responseData['Response'] ?? '';
            $procReturnCode = $responseData['ProcReturnCode'] ?? '';
            $transId = $responseData['TransId'] ?? '';
            $orderStatus = $responseData['Extra']['ORDERSTATUS'] ?? '';
            
            // Check if payment is successful
            if ($response === 'Approved' && $procReturnCode === '00') {
                return PaymentResponse::success(
                    $transId ?: $orderId,
                    $orderId,
                    'Ödeme durumu: Onaylandı',
                    $responseData
                );
            }
            
            // Payment failed or pending
            $errorMsg = $responseData['ErrMsg'] ?? ($orderStatus === 'SOR' ? 'Ödeme sorgulama yapıldı' : 'Ödeme durumu bilinmiyor');
            return PaymentResponse::failed(
                $errorMsg,
                $procReturnCode,
                $orderId,
                $responseData
            );
            
        } catch (\Exception $e) {
            log_message('error', "Nestpay CheckPayment exception (OrderID: $orderId): " . $e->getMessage());
            return PaymentResponse::failed(
                'Yanıt işlenirken hata oluştu: ' . $e->getMessage(),
                null,
                $orderId
            );
        }
    }

    public function cancel(string $orderId, ?float $amount = null): PaymentResponse
    {
        $config = $this->getAccountConfig();
        $url = $this->isTestMode() ? 
            'https://entegrasyon.asseco-see.com.tr/fim/api' : 
            'https://www.muze.com.tr/fim/api';

        $data = [
            'Name' => $config['clientId'],
            'Password' => $config['storeKey'],
            'ClientId' => $config['clientId'],
            'OrderId' => $orderId,
            'Type' => 'Void',
        ];

        try {
            $response = $this->post($url, $data);
            
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
        $config = $this->getAccountConfig();
        $url = $this->isTestMode() ? 
            'https://entegrasyon.asseco-see.com.tr/fim/api' : 
            'https://www.muze.com.tr/fim/api';

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
        $config = $this->getAccountConfig();
        
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
        // NestPay taksit bilgileri genellikle banka tarafından sağlanır
        // Bu metod bankaya özel implementasyon gerektirebilir
        return [];
    }

    /**
     * HTML form oluşturur
     */
    private function buildForm(string $url, array $data): string
    {
        $form = '<form id="nestpay_form" method="post" action="' . htmlspecialchars($url) . '">';
        foreach ($data as $key => $value) {
            $form .= '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($value) . '">';
        }
        $form .= '</form>';
        $form .= '<script>document.getElementById("nestpay_form").submit();</script>';
        return $form;
    }
}

