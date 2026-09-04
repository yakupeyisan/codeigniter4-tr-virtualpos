<?php

namespace Yakupeyisan\CodeIgniter4\VirtualPos\Providers;

use DOMDocument;
use Yakupeyisan\CodeIgniter4\VirtualPos\Base\VirtualPosBase;
use Yakupeyisan\CodeIgniter4\VirtualPos\Exceptions\ConfigurationException;
use Yakupeyisan\CodeIgniter4\VirtualPos\Exceptions\PaymentException;
use Yakupeyisan\CodeIgniter4\VirtualPos\Models\PaymentRequest;
use Yakupeyisan\CodeIgniter4\VirtualPos\Models\PaymentResponse;

class Get724Provider extends VirtualPosBase
{
    protected function validateConfiguration(): void
    {
        $config = $this->getAccountConfig();
        
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

    /**
     * Aktif hesap yapılandırmasını döndürür
     */
    protected function getAccountConfig(): array
    {
        $providerConfig = $this->config->get724;
        $accounts = $providerConfig['accounts'] ?? [];
        $accountId = $this->accountId ?? $providerConfig['defaultAccount'] ?? 'default';
        
        if (!isset($accounts[$accountId])) {
            throw new ConfigurationException("Get724 account '{$accountId}' bulunamadı");
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

        // Vakıfbank: Common Payment API (RegisterTransaction) - eski sistem ile uyumlu
        if ($config['bank'] === 'vakifbank') {
            return $this->registerVakifbankTransaction($request, $config);
        }

        // NestPay/EST ve diğer bankalar: form ile 3D gateway
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
        // NestPay/EST ödeme ağ geçidi hashAlgorithm=ver3 için SHA-1 zorunludur (sağlayıcı sözleşmesi).
        $data['hash'] = base64_encode(pack('H*', hash('sha1', $hashData)));

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

        // HTML form oluştur
        $html = $this->buildForm($url, $data);

        return PaymentResponse::pending(
            $request->orderId,
            null,
            $html,
            $data
        );
    }

    /**
     * Vakıfbank Common Payment API - RegisterTransaction (eski sistem CreateForm ile uyumlu)
     * https://cpweb.vakifbank.com.tr/CommonPayment/api/RegisterTransaction
     */
    private function registerVakifbankTransaction(PaymentRequest $request, array $config): PaymentResponse
    {
        $postUrl = 'https://cpweb.vakifbank.com.tr/CommonPayment/api/RegisterTransaction';
        $transactionId = $request->orderId . '_' . time();
        $amountCode = $this->getAmountCode($config);
        $amount = $this->formatAmount($request->amount);
        $installment = $request->installment ?? '';
        if ($installment === '0' || $installment === 0) {
            $installment = '';
        }

        $postData = [
            'HostMerchantId' => $config['clientId'],
            'AmountCode' => $amountCode,
            'Amount' => $amount,
            'MerchantPassword' => $config['storeKey'],
            'TransactionId' => $transactionId,
            'OrderID' => $request->orderId,
            'InstallmentCount' => $installment,
            'TransactionType' => 'Sale',
            'IsSecure' => 'true',
            'AllowNotEnrolledCard' => 'false',
            'HostTerminalId' => $config['clientName'] ?? $config['clientId'],
            'SuccessURL' => $this->getCallbackUrl('success'),
            'FailURL' => $this->getCallbackUrl('fail'),
        ];

        $vakifbankHeaders = [
            'Accept' => 'application/xml',
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept-Language' => 'tr-TR,tr;q=0.9,en;q=0.8',
        ];
        try {
            $response = $this->post($postUrl, $postData, $vakifbankHeaders, ['verify' => false]);
            $rawBody = is_string($response) ? $response : (is_array($response) ? '' : (string) $response);
            if ($rawBody === '' && is_array($response)) {
                return PaymentResponse::failed(
                    'Banka yanıtı boş veya işlenemedi',
                    null,
                    $request->orderId,
                    $response
                );
            }
            $result = $this->readVakifbankRegisterResult($rawBody);
        } catch (\Throwable $e) {
            return PaymentResponse::failed($e->getMessage(), null, $request->orderId);
        }

        if (!empty($result['ErrorCode'])) {
            $errorMsg = $this->getVakifbankErrorDescription($result['ErrorCode'], $rawBody ?? '');
            if ($result['ErrorCode'] === 'INVALID_XML' && !empty($result['_rawSnippet'])) {
                $errorMsg .= ' Yanıt: ' . $result['_rawSnippet'];
            }
            return PaymentResponse::failed($errorMsg, $result['ErrorCode'], $request->orderId, $result);
        }

        $redirectUrl = $result['CommonPaymentUrl'];
        if (!empty($result['PaymentToken'])) {
            $separator = strpos($redirectUrl, '?') !== false ? '&' : '?';
            $redirectUrl .= $separator . 'Ptkn=' . rawurlencode($result['PaymentToken']);
        }

        $response = PaymentResponse::pending(
            $request->orderId,
            $redirectUrl,
            null,
            [
                'inputs' => ['Ptkn' => $result['PaymentToken']],
                'method' => 'GET',
                'url' => $result['CommonPaymentUrl'],
                'TransactionId' => $transactionId,
            ]
        );
        $response->transactionId = $transactionId;
        return $response;
    }

    /**
     * Vakıfbank RegisterTransaction XML veya JSON yanıtını parse eder
     */
    private function readVakifbankRegisterResult(string $body): array
    {
        $result = [
            'CommonPaymentUrl' => '',
            'PaymentToken' => '',
            'ErrorCode' => '',
        ];
        $snippet = function (string $s, int $len = 200) {
            $s = preg_replace('/\s+/', ' ', trim($s));
            return mb_substr($s, 0, $len) . (mb_strlen($s) > $len ? '…' : '');
        };

        $body = trim($body);
        if ($body === '') {
            $result['ErrorCode'] = 'EMPTY';
            return $result;
        }

        // BOM ve encoding: UTF-8 BOM kaldır; UTF-16 ise UTF-8'e çevir
        $bom8 = "\xef\xbb\xbf";
        if (str_starts_with($body, $bom8)) {
            $body = substr($body, strlen($bom8));
        }
        $bom16le = "\xff\xfe";
        $bom16be = "\xfe\xff";
        if (str_starts_with($body, $bom16le) || str_starts_with($body, $bom16be)) {
            $body = mb_convert_encoding($body, 'UTF-8', 'UTF-16');
        }

        // JSON yanıt (bazı ortamlarda API JSON dönebilir)
        $trimmed = trim($body);
        if (str_starts_with($trimmed, '{')) {
            $json = json_decode($body, true);
            if (is_array($json)) {
                $result['CommonPaymentUrl'] = $json['CommonPaymentUrl'] ?? $json['commonPaymentUrl'] ?? '';
                $result['PaymentToken'] = $json['PaymentToken'] ?? $json['paymentToken'] ?? '';
                $result['ErrorCode'] = (string) ($json['ErrorCode'] ?? $json['errorCode'] ?? '');
                return $result;
            }
        }

        // XML parse dene
        $doc = new DOMDocument();
        $useInternalErrors = libxml_use_internal_errors(true);
        $loaded = @$doc->loadXML($body, LIBXML_NOERROR | LIBXML_NOCDATA);
        libxml_use_internal_errors($useInternalErrors);

        if (!$loaded) {
            $result['ErrorCode'] = 'INVALID_XML';
            $result['_rawSnippet'] = $snippet($body);
            return $result;
        }

        $get = function (string $tagName) use ($doc): string {
            $node = $doc->getElementsByTagName($tagName)->item(0);
            return $node !== null ? trim($node->nodeValue ?? '') : '';
        };
        $result['CommonPaymentUrl'] = $get('CommonPaymentUrl');
        $result['PaymentToken'] = $get('PaymentToken');
        $result['ErrorCode'] = $get('ErrorCode');
        return $result;
    }

    /**
     * AmountCode için para birimi kodu (Vakıfbank: 949=TRY)
     */
    private function getAmountCode(array $config): string
    {
        $currency = $config['currency'] ?? '949';
        if (strtoupper($currency) === 'TRY') {
            return '949';
        }
        return $currency;
    }

    /**
     * Vakıfbank hata kodları (eski sistem ErrorDescription ile uyumlu)
     * INVALID_XML + "Request Rejected" = WAF/güvenlik duvarı isteği reddetti
     */
    private function getVakifbankErrorDescription(string $errorCode, string $rawBody = ''): string
    {
        if ($errorCode === 'INVALID_XML' && (stripos($rawBody, 'Request Rejected') !== false || stripos($rawBody, 'request was rejected') !== false)) {
            return 'Banka güvenlik duvarı isteği reddetti. Sunucu IP adresinizin Vakıfbank tarafından tanımlı olması veya banka ile iletişime geçmeniz gerekebilir. (Request Rejected)';
        }
        $messages = [
            '0000' => 'Başarılı',
            '0003' => 'ÜYE KODU HATALI/TANIMSIZ',
            '5001' => 'İş yeri şifresi yanlış.',
            '5002' => 'İş yeri aktif değil.',
            '1006' => 'Bu TransactionId ile daha önce başarılı bir işlem gerçekleştirilmiş',
            '5037' => 'SuccessUrl alanı hatalıdır.',
            '5038' => 'İşlem bulunamadı.',
        ];
        return $messages[$errorCode] ?? ('Hata: ' . $errorCode);
    }

    public function status(string $orderId, ?string $transactionId = null): PaymentResponse
    {
        $config = $this->getAccountConfig();

        // VakıfBank: Common Payment API VposTransaction (mütabakat) - www.get724.com.tr kullanılmaz
        if ($config['bank'] === 'vakifbank' && $transactionId !== null && $transactionId !== '') {
            return $this->checkVakifbankTransaction($orderId, $transactionId, $config);
        }

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

    /**
     * VakıfBank Common Payment API - VposTransaction (mütabakat / CheckPayment)
     * https://cpweb.vakifbank.com.tr/CommonPayment/api/VposTransaction
     * Eski sistem Get724::CheckPayment ile uyumlu; www.get724.com.tr kullanılmaz.
     */
    private function checkVakifbankTransaction(string $orderId, string $transactionId, array $config): PaymentResponse
    {
        $postUrl = 'https://cpweb.vakifbank.com.tr/CommonPayment/api/VposTransaction';
        $postData = [
            'HostMerchantId' => $config['clientId'],
            'Password' => $config['storeKey'],
            'TransactionId' => $transactionId,
        ];

        $headers = [
            'Accept' => 'application/xml',
        ];

        $rawBody = '';
        try {
            $response = $this->post($postUrl, $postData, $headers, ['verify' => false]);
            if (is_string($response)) {
                $rawBody = $response;
            } elseif (is_array($response)) {
                $rawBody = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
            } else {
                $rawBody = (string) $response;
            }
            $rawPayload = ['_raw' => $rawBody];
            if ($rawBody === '') {
                return PaymentResponse::failed('Banka yanıtı boş', null, $orderId, $rawPayload);
            }

            $xml = @simplexml_load_string($rawBody);
            if ($xml === false) {
                return PaymentResponse::failed('Banka yanıtı işlenemedi', null, $orderId, $rawPayload);
            }

            $rc = (string) ($xml->Rc ?? $xml->rc ?? '');
            if ($rc === '0000') {
                return PaymentResponse::success(
                    $transactionId,
                    $orderId,
                    'Ödeme başarılı',
                    json_decode(json_encode($xml), true) ?: []
                );
            }

            $errMsg = $this->getVakifbankErrorDescription($rc, $rawBody);
            $failedRaw = json_decode(json_encode($xml), true) ?: [];
            $failedRaw['_raw'] = $rawBody;

            return PaymentResponse::failed($errMsg, $rc, $orderId, $failedRaw);
        } catch (\Throwable $e) {
            return PaymentResponse::failed(
                $e->getMessage(),
                null,
                $orderId,
                $rawBody !== '' ? ['_raw' => $rawBody] : []
            );
        }
    }

    public function cancel(string $orderId, ?float $amount = null): PaymentResponse
    {
        $config = $this->getAccountConfig();
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
        $config = $this->getAccountConfig();
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
        $calculatedHash = base64_encode(pack('H*', hash('sha1', $hashData)));
        
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
        $config = $this->getAccountConfig();
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

