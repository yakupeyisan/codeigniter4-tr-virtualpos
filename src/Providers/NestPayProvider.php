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
            throw new ConfigurationException('NestPay clientId yapÃ„Â±landÃ„Â±rÃ„Â±lmamÃ„Â±Ã…Å¸');
        }
        
        if (empty($config['storeKey'])) {
            throw new ConfigurationException('NestPay storeKey yapÃ„Â±landÃ„Â±rÃ„Â±lmamÃ„Â±Ã…Å¸');
        }
    }

    /**
     * Aktif hesap yapÃ„Â±landÃ„Â±rmasÃ„Â±nÃ„Â± dÃƒÂ¶ndÃƒÂ¼rÃƒÂ¼r
     */
    protected function getAccountConfig(): array
    {
        $providerConfig = $this->config->nestpay;
        $accounts = $providerConfig['accounts'] ?? [];
        $accountId = $this->accountId ?? $providerConfig['defaultAccount'] ?? 'default';
        
        if (!isset($accounts[$accountId])) {
            throw new ConfigurationException("NestPay account '{$accountId}' bulunamadÃ„Â±");
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
        // Determine store type (usage type) based on bank / config
        $bank = strtolower($config['bank'] ?? '');
        if ($bank === 'halkbank' || $bank === 'ziraat') {
            // Halkbank / Ziraat NestPay: hosting odeme; 3d_pay_hosting
            $storeType = '3d_pay_hosting';
        } else {
            // Fallback to configured storeType or classic 3d
            $storeType = $config['storeType'] ?? '3d';
        }
        $data = [
            'clientid' => $config['clientId'],
            'storetype' => $storeType,
            'amount' => number_format((float) $request->amount, 2, '.', ''),
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

        // HTML form oluÃ…Å¸tur
        $html = $this->buildForm($url, $data);

        // Eski API uyumu: istemci url + inputs ile formu doğrudan oluşturabilir
        $legacyPayload = $data;
        $legacyPayload['url'] = $url;
        $legacyPayload['method'] = 'POST';
        $legacyPayload['inputs'] = $data;

        return PaymentResponse::pending(
            $request->orderId,
            null,
            $html,
            $legacyPayload
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

    public function status(string $orderId, ?string $transactionId = null): PaymentResponse
    {
        $config = $this->getAccountConfig();
        log_message('error', 'NestPayProvider::status called', [
            'orderId' => $orderId,
            'transactionId' => $transactionId,
            'config' => $config,
        ]);
        return $this->checkPaymentStatus($orderId, $config);
    }

    /**
     * Ziraat BankasÃ„Â± iÃƒÂ§in ÃƒÂ¶deme durumu sorgulama (mÃƒÂ¼tabakat)
     * Optimized with better timeout handling and error checking
     * 
     * @param string $orderId SipariÃ…Å¸ ID
     * @param array $config Hesap yapÃ„Â±landÃ„Â±rmasÃ„Â±
     * @return PaymentResponse
     */
    private function checkPaymentStatus(string $orderId, array $config): PaymentResponse
    {
        log_message('debug','NestPayProvider checkPaymentStatus: '.json_encode($config));
        // Get credentials from config (compatible with old Nestpay::CheckPayment)
        $clientName = $config['clientName'] ?? '';
        // Use dedicated password field if provided, otherwise fall back to storeKey
        $password = $config['password'] ?? ($config['storeKey'] ?? '');
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
        log_message('error','NestPayProvider checkPaymentStatus xmlRequest: '.$xmlRequest);
        $requestData = "DATA=" . $xmlRequest;

        // Select status / reconciliation URL based on bank (Ziraat / Halkbank Nestpay)
        $bank = strtolower($config['bank'] ?? 'isbank');
        if ($bank === 'halkbank') {
            // Old system Halkbank status URL
            $url = 'https://sanalpos.halkbank.com.tr/fim/api';
        } else {
            // Default to Ziraat Nestpay status URL (old system compatible)
            $url = 'https://sanalpos2.ziraatbank.com.tr/servlet/cc5ApiServer';
        }

        $allowedUrls = [
            'https://sanalpos.halkbank.com.tr/fim/api',
            'https://sanalpos2.ziraatbank.com.tr/servlet/cc5ApiServer',
        ];
        if (! in_array($url, $allowedUrls, true)) {
            return PaymentResponse::failed('Geçersiz banka API adresi', null, $orderId);
        }

        log_message('error', 'NestPayProvider checkPaymentStatus url: ' . $url);

        // Windows/IIS ortamlarında CA paketi yoksa SSL doğrulama mütabakatı kırar (eski Nestpay::CheckPayment davranışı)
        $verifySsl = filter_var(env('VIRTUALPOS_SSL_VERIFY', 'false'), FILTER_VALIDATE_BOOLEAN);
        $caBundle = env('CURL_CA_BUNDLE', '');
        if ($caBundle !== '' && is_file($caBundle)) {
            $verifySsl = $caBundle;
        }

        $startTime = microtime(true);
        try {
            $httpResponse = \Config\Services::curlrequest()->post($url, [
                'body' => $requestData,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Connection' => 'keep-alive',
                    'Cache-Control' => 'no-cache',
                ],
                'http_errors' => false,
                'verify' => $verifySsl,
                'timeout' => 30,
                'connect_timeout' => 10,
            ]);
            $result = (string) $httpResponse->getBody();
            $httpCode = $httpResponse->getStatusCode();
        } catch (\Throwable $e) {
            $executionTime = microtime(true) - $startTime;
            log_message(
                'error',
                'Nestpay CheckPayment HTTP Error (OrderID: ' . $orderId . '): '
                . $e->getMessage() . ' | Execution time: ' . round($executionTime, 2) . 's'
            );

            return PaymentResponse::failed(
                'Banka API hatası: ' . $e->getMessage(),
                null,
                $orderId
            );
        }
        $executionTime = microtime(true) - $startTime;
        
        // Log raw HTTP response for reconciliation debugging
        log_message('error', 'NestPayProvider checkPaymentStatus raw HTTP response', [
            'orderId' => $orderId,
            'httpCode' => $httpCode,
            'executionTime' => round($executionTime, 2),
            'rawBody' => $result,
        ]);
        
        // Log slow requests (more than 5 seconds)
        if ($executionTime > 5) {
            log_message('warning', "Nestpay CheckPayment slow response (OrderID: $orderId): " . round($executionTime, 2) . "s | HTTP Code: $httpCode");
        }
        
        // Check HTTP response code
        if ($httpCode !== 200) {
            log_message('error', "Nestpay CheckPayment HTTP Error (OrderID: $orderId): HTTP $httpCode | Execution time: " . round($executionTime, 2) . "s");
            return PaymentResponse::failed(
                "Banka API HTTP hatasÃ„Â±: $httpCode",
                (string)$httpCode,
                $orderId
            );
        }
        
        // Parse XML response
        if (empty($result)) {
            log_message('error', "Nestpay CheckPayment empty response (OrderID: $orderId)");
            return PaymentResponse::failed('Banka yanÃ„Â±tÃ„Â± boÃ…Å¸', null, $orderId);
        }
        log_message('error','NestPayProvider checkPaymentStatus result: '.$result);
        try {
            // Suppress XML warnings for invalid characters
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($result);
            
            if ($xml === false) {
                $errors = libxml_get_errors();
                libxml_clear_errors();
                log_message('error', "Nestpay CheckPayment XML parse error (OrderID: $orderId): " . json_encode($errors));
                return PaymentResponse::failed('Banka yanÃ„Â±tÃ„Â± parse edilemedi', null, $orderId);
            }
            // Convert XML to array
            $responseData = json_decode(json_encode($xml), true);
            log_message('error', 'NestPayProvider checkPaymentStatus parsed responseData', [
                'orderId' => $orderId,
                'responseData' => $responseData,
            ]);
            
            // Parse response to determine payment status
            // Nestpay / Halkbank response format: CC5Response -> Response, ProcReturnCode, etc.
            $response = $responseData['Response'] ?? '';
            $procReturnCode = $responseData['ProcReturnCode'] ?? '';
            $transId = $responseData['TransId'] ?? '';
            $orderStatus = $responseData['Extra']['ORDERSTATUS'] ?? '';
            $chargeTypeCd = $responseData['Extra']['CHARGE_TYPE_CD'] ?? '';
            
            // Check if payment is successful
            // Old system: only Response == Approved AND ProcReturnCode == 00 kontrol ediliyordu.
            // CHARGE_TYPE_CD varsa S => baÃ…Å¸arÃ„Â±lÃ„Â±, C => baÃ…Å¸arÃ„Â±sÃ„Â±z olarak ele al.
            if ($response === 'Approved' && $procReturnCode === '00' && ($chargeTypeCd === '' || $chargeTypeCd === 'S')) {
                return PaymentResponse::success(
                    $transId ?: $orderId,
                    $orderId,
                    'Ãƒâ€“deme durumu: OnaylandÃ„Â±',
                    $responseData
                );
            }
            if ($response === 'Approved' && $procReturnCode === '00' && $chargeTypeCd === 'C') {
                return PaymentResponse::failed(
                    'Ãƒâ€“deme durumu: Reddedildi',
                    $procReturnCode,
                    $orderId,
                    $responseData
                );
            }
            
            // Payment failed or pending
            $errorMsg = $responseData['ErrMsg'] ?? ($orderStatus === 'SOR' ? 'Ãƒâ€“deme sorgulama yapÃ„Â±ldÃ„Â±' : 'Ãƒâ€“deme durumu bilinmiyor');
            return PaymentResponse::failed(
                $errorMsg,
                $procReturnCode,
                $orderId,
                $responseData
            );
            
        } catch (\Exception $e) {
            log_message('error', "Nestpay CheckPayment exception (OrderID: $orderId): " . $e->getMessage());
            return PaymentResponse::failed(
                'YanÃ„Â±t iÃ…Å¸lenirken hata oluÃ…Å¸tu: ' . $e->getMessage(),
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
                    'Ã„Â°ptal iÃ…Å¸lemi baÃ…Å¸arÃ„Â±lÃ„Â±',
                    $response
                );
            }

            return PaymentResponse::failed(
                $response['ErrMsg'] ?? 'Ã„Â°ptal iÃ…Å¸lemi baÃ…Å¸arÃ„Â±sÃ„Â±z',
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
                    'Ã„Â°ade iÃ…Å¸lemi baÃ…Å¸arÃ„Â±lÃ„Â±',
                    $response
                );
            }

            return PaymentResponse::failed(
                $response['ErrMsg'] ?? 'Ã„Â°ade iÃ…Å¸lemi baÃ…Å¸arÃ„Â±sÃ„Â±z',
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
        
        // Hash doÃ„Å¸rulama
        $hashParams = $data['HASHPARAMS'] ?? '';
        $hashParamsVal = $data['HASHPARAMSVAL'] ?? '';
        $hash = $data['HASH'] ?? '';
        
        if (empty($hashParams) || empty($hashParamsVal) || empty($hash)) {
            return PaymentResponse::failed('GeÃƒÂ§ersiz callback verisi', null, $data['oid'] ?? null);
        }

        // Hash doÃ„Å¸rulama (Nestpay ver3 algoritmasÃ„Â±: HASHPARAMSVAL + escaped storeKey, SHA512)
        $storeKey = $config['storeKey'] ?? '';
        if ($storeKey === '') {
            return PaymentResponse::failed('StoreKey yapÃ„Â±landÃ„Â±rÃ„Â±lmamÃ„Â±Ã…Å¸', null, $data['oid'] ?? null);
        }

        // Escape storeKey just like in request hash creation
        $escapedStoreKey = str_replace("|", "\\|", str_replace("\\", "\\\\", $storeKey));
        $hashData = $hashParamsVal . $escapedStoreKey;
        $calculatedHashValue = hash('sha512', $hashData);
        $calculatedHash = base64_encode(pack('H*', $calculatedHashValue));
        
        if ($calculatedHash !== $hash) {
            return PaymentResponse::failed('Hash doÃ„Å¸rulama baÃ…Å¸arÃ„Â±sÃ„Â±z', null, $data['oid'] ?? null);
        }

        $orderId = $data['oid'] ?? '';
        $response = $data['Response'] ?? '';
        $procReturnCode = $data['ProcReturnCode'] ?? '';
        $transId = $data['TransId'] ?? '';
        $mdStatus = $data['mdStatus'] ?? '';

        // 3D Secure doÃ„Å¸rulama
        if ($mdStatus !== '1' && $mdStatus !== '2' && $mdStatus !== '3' && $mdStatus !== '4') {
            return PaymentResponse::failed('3D Secure doÃ„Å¸rulama baÃ…Å¸arÃ„Â±sÃ„Â±z', $mdStatus, $orderId, $data);
        }

        // Ãƒâ€“deme durumu
        if ($response === 'Approved' && $procReturnCode === '00') {
            return PaymentResponse::success(
                $transId,
                $orderId,
                'Ãƒâ€“deme baÃ…Å¸arÃ„Â±lÃ„Â±',
                $data
            );
        }

        $errorMsg = $data['ErrMsg'] ?? 'Ãƒâ€“deme baÃ…Å¸arÃ„Â±sÃ„Â±z';
        return PaymentResponse::failed(
            $errorMsg,
            $procReturnCode,
            $orderId,
            $data
        );
    }

    public function getInstallments(float $amount): array
    {
        // NestPay taksit bilgileri genellikle banka tarafÃ„Â±ndan saÃ„Å¸lanÃ„Â±r
        // Bu metod bankaya ÃƒÂ¶zel implementasyon gerektirebilir
        return [];
    }

    /**
     * HTML form oluÃ…Å¸turur
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

