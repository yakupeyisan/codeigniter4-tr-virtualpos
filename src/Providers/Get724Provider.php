<?php

namespace Yakupeyisan\CodeIgniter4\VirtualPos\Providers;

use DOMDocument;
use Yakupeyisan\CodeIgniter4\VirtualPos\Base\VirtualPosBase;
use Yakupeyisan\CodeIgniter4\VirtualPos\Exceptions\ConfigurationException;
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

        if ($config['bank'] === 'vakifbank') {
            if ($this->isVakifbankApigateway($config)) {
                return $this->createVakifbankToken($request, $config);
            }

            return $this->registerVakifbankTransaction($request, $config);
        }

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

        $hashData = $config['storeKey'] . $data['clientid'] . $data['oid'] . $data['amount'] .
                   $data['okUrl'] . $data['failUrl'] . $data['islemtipi'] . $data['taksit'] .
                   $data['rnd'] . $data['currency'];
        $data['hash'] = base64_encode(pack('H*', hash('sha1', $hashData)));

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

        $html = $this->buildForm($url, $data);

        return PaymentResponse::pending(
            $request->orderId,
            null,
            $html,
            $data
        );
    }

    /**
     * Vakıfbank Common Payment API - RegisterTransaction (eski cpweb)
     */
    private function registerVakifbankTransaction(PaymentRequest $request, array $config): PaymentResponse
    {
        $postUrl = $this->vakifbankEndpoint('registerTransaction');
        $transactionId = $this->buildVakifbankTransactionId($request->orderId);
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
            'SuccessURL' => $this->vakifbankCallbackUrl('success', $request),
            'FailURL' => $this->vakifbankCallbackUrl('fail', $request),
        ];

        $vakifbankHeaders = [
            'Accept' => 'application/xml',
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept-Language' => 'tr-TR,tr;q=0.9,en;q=0.8',
        ];
        try {
            $response = $this->post($postUrl, $postData, $vakifbankHeaders, ['verify' => $this->sslVerify()]);
            $rawBody = is_string($response) ? $response : (is_array($response) ? json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $response);
            if ($rawBody === '') {
                return PaymentResponse::failed(
                    'Banka yanıtı boş veya işlenemedi',
                    null,
                    $request->orderId,
                    is_array($response) ? $response : ['_raw' => $rawBody]
                );
            }
            $result = $this->readVakifbankRegisterResult($rawBody);
        } catch (\Throwable $e) {
            return PaymentResponse::failed($e->getMessage(), null, $request->orderId);
        }

        return $this->pendingVakifbankRedirect($request, $result, $transactionId, $rawBody ?? '', 'cpweb');
    }

    /**
     * Kılavuz 1.1: JSON CreateTokenCPY (apigateway)
     */
    private function createVakifbankToken(PaymentRequest $request, array $config): PaymentResponse
    {
        $postUrl = $this->vakifbankEndpoint('createToken');
        $transactionId = $this->buildVakifbankTransactionId($request->orderId);
        $amount = $this->formatAmount($request->amount);
        $currencyCode = (int) $this->getAmountCode($config);
        $installment = $request->installment ?? '';
        if ($installment === '0' || $installment === 0) {
            $installment = '';
        }

        $meta = $request->metadata ?? [];
        $transactionSource = (int) ($meta['transactionSource'] ?? 1);
        if ($transactionSource !== 2) {
            $transactionSource = 1;
        }

        $payload = [
            'MerchantNumber' => (string) $config['clientId'],
            'Password' => (string) $config['storeKey'],
            'TerminalNumber' => (string) ($config['clientName'] ?? $config['clientId']),
            'OrderId' => (string) $request->orderId,
            'TransactionId' => $transactionId,
            'TransactionType' => 'Sale',
            'CurrencyCode' => $currencyCode,
            'Amount' => $amount,
            'SecureType' => 1,
            'AllowNotEnrolledCard' => 0,
            'RequestLanguage' => 1,
            'TransactionChannel' => 1,
            'TransactionSource' => $transactionSource,
            'ClientIp' => $request->customerIp ?: $this->getClientIp(),
            'SuccessUrl' => $this->vakifbankCallbackUrl('success', $request),
            'FailUrl' => $this->vakifbankCallbackUrl('fail', $request),
        ];

        if ($request->customerName) {
            $payload['CardHoldersName'] = $request->customerName;
        }
        $phone = $this->normalizeTrPhone((string) ($request->customerPhone ?? ''));
        if ($phone !== '') {
            $payload['CHPhoneNumber'] = $phone;
        }
        if (!empty($request->customerEmail)) {
            $payload['CHEmailAddress'] = $request->customerEmail;
        }
        if ($installment !== '') {
            $payload['InstallmentCount'] = (int) $installment;
        }

        if ($transactionSource === 2) {
            $payload['TokenType'] = (int) ($meta['tokenType'] ?? 1);
            $payload['TokenExpireTime'] = (int) ($meta['tokenExpireTime'] ?? 1);
            $notificationType = (int) ($meta['notificationType'] ?? 0);
            if ($notificationType > 0) {
                $payload['NotificationType'] = $notificationType;
            }
        }

        try {
            $rawBody = $this->postJson($postUrl, $payload);
            $result = $this->readVakifbankRegisterResult($rawBody);
        } catch (\Throwable $e) {
            return PaymentResponse::failed($e->getMessage(), null, $request->orderId);
        }

        return $this->pendingVakifbankRedirect($request, $result, $transactionId, $rawBody ?? '', 'apigateway');
    }

    private function pendingVakifbankRedirect(
        PaymentRequest $request,
        array $result,
        string $transactionId,
        string $rawBody,
        string $gateway
    ): PaymentResponse {
        $errorCode = (string) ($result['ErrorCode'] ?? '');
        if ($errorCode !== '' && $errorCode !== '0000') {
            $errorMsg = $this->getVakifbankErrorDescription($errorCode, $rawBody);
            if ($errorCode === 'INVALID_XML' && !empty($result['_rawSnippet'])) {
                $errorMsg .= ' Yanıt: ' . $result['_rawSnippet'];
            }

            return PaymentResponse::failed($errorMsg, $errorCode, $request->orderId, $result);
        }

        $paymentToken = (string) ($result['PaymentToken'] ?? '');
        if ($paymentToken === '') {
            return PaymentResponse::failed(
                'PaymentToken alınamadı',
                $errorCode !== '' ? $errorCode : 'EMPTY',
                $request->orderId,
                $result + ['_raw' => $rawBody]
            );
        }

        $commonUrl = (string) ($result['CommonPaymentUrl'] ?? '');
        if ($commonUrl === '') {
            $commonUrl = $this->vakifbankEndpoint('securePayment');
        }
        $tokenParam = $gateway === 'apigateway' ? 'PTKN' : 'Ptkn';
        $separator = strpos($commonUrl, '?') !== false ? '&' : '?';
        $redirectUrl = $commonUrl . $separator . $tokenParam . '=' . rawurlencode($paymentToken);

        $shortLink = (string) ($result['ShortLink'] ?? '');
        $rawData = [
            'inputs' => [$tokenParam => $paymentToken],
            'method' => 'GET',
            'url' => $commonUrl,
            'TransactionId' => $transactionId,
            'PaymentToken' => $paymentToken,
            'CommonPaymentUrl' => $commonUrl,
            'ShortLink' => $shortLink,
            'gateway' => $gateway,
        ];
        if ($shortLink !== '') {
            $rawData['redirectUrl'] = $shortLink;
        }

        $response = PaymentResponse::pending(
            $request->orderId,
            $shortLink !== '' ? $shortLink : $redirectUrl,
            null,
            $rawData
        );
        $response->transactionId = $transactionId;

        return $response;
    }

    /**
     * Vakıfbank RegisterTransaction / CreateToken XML veya JSON yanıtını parse eder
     */
    private function readVakifbankRegisterResult(string $body): array
    {
        $result = [
            'CommonPaymentUrl' => '',
            'PaymentToken' => '',
            'ShortLink' => '',
            'ErrorCode' => '',
            'ResponseMessage' => '',
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

        $bom8 = "\xef\xbb\xbf";
        if (str_starts_with($body, $bom8)) {
            $body = substr($body, strlen($bom8));
        }
        $bom16le = "\xff\xfe";
        $bom16be = "\xfe\xff";
        if (str_starts_with($body, $bom16le) || str_starts_with($body, $bom16be)) {
            $body = mb_convert_encoding($body, 'UTF-8', 'UTF-16');
        }

        $trimmed = trim($body);
        if (str_starts_with($trimmed, '{')) {
            $json = json_decode($body, true);
            if (is_array($json)) {
                $result['CommonPaymentUrl'] = (string) ($json['CommonPaymentUrl'] ?? $json['commonPaymentUrl'] ?? '');
                $result['PaymentToken'] = (string) ($json['PaymentToken'] ?? $json['paymentToken'] ?? '');
                $result['ShortLink'] = (string) ($json['ShortLink'] ?? $json['shortLink'] ?? '');
                $result['ErrorCode'] = (string) ($json['ErrorCode'] ?? $json['errorCode'] ?? '');
                $result['ResponseMessage'] = (string) ($json['ResponseMessage'] ?? $json['responseMessage'] ?? '');

                return $result;
            }
        }

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
        $result['ShortLink'] = $get('ShortLink');
        $result['ErrorCode'] = $get('ErrorCode');
        $result['ResponseMessage'] = $get('ResponseMessage');

        return $result;
    }

    private function getAmountCode(array $config): string
    {
        $currency = $config['currency'] ?? '949';
        if (strtoupper((string) $currency) === 'TRY' || strtoupper((string) $currency) === 'TL') {
            return '949';
        }

        return (string) $currency;
    }

    private function getVakifbankErrorDescription(string $errorCode, string $rawBody = ''): string
    {
        if ($errorCode === 'INVALID_XML' && (stripos($rawBody, 'Request Rejected') !== false || stripos($rawBody, 'request was rejected') !== false)) {
            return 'Banka güvenlik duvarı isteği reddetti. Sunucu IP adresinizin Vakıfbank tarafından tanımlı olması veya banka ile iletişime geçmeniz gerekebilir. (Request Rejected)';
        }
        $messages = [
            '0000' => 'Başarılı',
            '0003' => 'ÜYE KODU HATALI/TANIMSIZ',
            '005' => 'Kart bankası işlemi reddetti. CVV, limit veya kart yetkileri kontrol edilmelidir.',
            '007' => 'Kart bankası tarafından reddedildi.',
            '012' => 'Geçersiz işlem. Kart veya güvenlik doğrulaması kontrol edilmeli.',
            '013' => 'Geçersiz işlem tutarı.',
            '020' => 'Kart bankasında 3D Secure aktif değil.',
            '046' => 'Kapalı kart.',
            '051' => 'Limit yetersiz.',
            '054' => 'Son kullanma tarihi geçmiş kart.',
            '057' => 'Kartın işlem yetkisi yok.',
            '058' => 'İşyeri veya kart nedeniyle işlem reddedildi.',
            '061' => 'RED-ÇEKİM LİMİTİ AŞILDI',
            '062' => 'Red-İşlem Onaylanmadı',
            '063' => 'Red-İşlem Onaylanmadı',
            '078' => 'Red-İşlem Onaylanmadı',
            '083' => 'Red-İşlem Onaylanmadı',
            '091' => 'Kart bankasına ulaşılamadı.',
            '093' => 'Kart e-ticaret işlemlerine kapalıdır.',
            '096' => 'Kart bankası işlemi reddetti.',
            '312' => 'CVV hatalı.',
            '359' => 'İşyeri ciro limiti aşıldı.',
            '501' => 'Geçersiz taksit veya işlem tutarı.',
            '542' => 'Günlük iade limiti aşıldı.',
            '570' => 'İşyeri yetkisi bulunmuyor.',
            '571' => 'AMEX işlem yetkisi yok.',
            '574' => 'İşyeri işlem yetkisi yok.',
            '576' => 'Taksit sektör kısıtı.',
            '577' => 'Taksit sınırı aşıldı.',
            '972' => 'Geçersiz para birimi.',
            '978' => 'Taksit uygulanamıyor.',
            '1001' => 'Sistem Hatası',
            '1005' => 'SessionInfo formatı hatalı.',
            '1006' => 'İşlem numarası tekrar kullanıldı veya format hatası.',
            '1007' => 'Referans işlem bulunamadı.',
            '1008' => 'Tutar formatı hatalı.',
            '1096' => 'Client IP eksik.',
            '1115' => '3D işlem eşleştirmesi hatalı.',
            '2005' => 'Kimlik doğrulama başarısız.',
            '2023' => 'VerifyEnrollmentRequestId tekrar kullanıldı.',
            '5001' => 'Kimlik doğrulama başarısız.',
            '5002' => 'İşyeri tanımı eksik veya pasif.',
            '5003' => 'İşlem bulunamadı.',
            '5037' => 'SuccessUrl alanı hatalıdır.',
            '5038' => 'İşlem bulunamadı.',
            '6000' => 'Parametre veya istek mesajı hatalı.',
            '6011' => 'Sunucu IP tanımlı değil.',
            '9039' => 'Ortam veya işyeri tanımı hatalı.',
        ];

        return $messages[$errorCode] ?? ('Hata: ' . $errorCode);
    }

    public function status(string $orderId, ?string $transactionId = null): PaymentResponse
    {
        $config = $this->getAccountConfig();

        if ($config['bank'] === 'vakifbank') {
            return $this->checkVakifbankTransaction($orderId, $transactionId ?? '', $config);
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
     * VakıfBank mütabakat: apigateway GetVposTransaction (PaymentToken) veya cpweb VposTransaction.
     *
     * @param string $lookup PaymentToken (UUID) veya TransactionId
     */
    private function checkVakifbankTransaction(string $orderId, string $lookup, array $config): PaymentResponse
    {
        if ($lookup === '') {
            return PaymentResponse::failed('Banka işlem numarası veya PaymentToken bulunamadı', null, $orderId);
        }

        if ($this->isVakifbankApigateway($config)) {
            $byToken = $this->getVposTransactionByToken($orderId, $lookup, $config);
            if ($byToken->success || $this->looksLikePaymentToken($lookup)) {
                return $byToken;
            }

            return $this->searchVakifbankTransaction($orderId, $lookup, $config);
        }

        return $this->checkCpwebVposTransaction($orderId, $lookup, $config);
    }

    private function getVposTransactionByToken(string $orderId, string $paymentToken, array $config): PaymentResponse
    {
        $postUrl = $this->vakifbankEndpoint('getVposTransaction');
        $payload = [
            'MerchantNumber' => (string) $config['clientId'],
            'Password' => (string) $config['storeKey'],
            'PaymentToken' => $paymentToken,
        ];

        $rawBody = '';
        try {
            $rawBody = $this->postJson($postUrl, $payload);

            return $this->mapVakifbankStatusBody($orderId, $paymentToken, $rawBody);
        } catch (\Throwable $e) {
            return PaymentResponse::failed(
                $e->getMessage(),
                null,
                $orderId,
                $rawBody !== '' ? ['_raw' => $rawBody] : []
            );
        }
    }

    private function checkCpwebVposTransaction(string $orderId, string $transactionId, array $config): PaymentResponse
    {
        $postUrl = $this->vakifbankEndpoint('vposTransaction');
        $postData = [
            'HostMerchantId' => $config['clientId'],
            'Password' => $config['storeKey'],
            'TransactionId' => $transactionId,
        ];

        $rawBody = '';
        try {
            $response = $this->post($postUrl, $postData, ['Accept' => 'application/xml'], ['verify' => $this->sslVerify()]);
            if (is_string($response)) {
                $rawBody = $response;
            } elseif (is_array($response)) {
                $rawBody = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
            } else {
                $rawBody = (string) $response;
            }

            return $this->mapVakifbankStatusBody($orderId, $transactionId, $rawBody);
        } catch (\Throwable $e) {
            return PaymentResponse::failed(
                $e->getMessage(),
                null,
                $orderId,
                $rawBody !== '' ? ['_raw' => $rawBody] : []
            );
        }
    }

    private function mapVakifbankStatusBody(string $orderId, string $fallbackTxn, string $rawBody): PaymentResponse
    {
        $rawPayload = ['_raw' => $rawBody];
        if (trim($rawBody) === '') {
            return PaymentResponse::failed('Banka yanıtı boş', null, $orderId, $rawPayload);
        }

        $parsed = $this->parseVakifbankStatusPayload($rawBody);
        if ($parsed === null) {
            return PaymentResponse::failed('Banka yanıtı işlenemedi', null, $orderId, $rawPayload);
        }

        $rc = (string) ($parsed['Rc'] ?? $parsed['rc'] ?? $parsed['ResultCode'] ?? $parsed['AuthResultCode'] ?? '');
        $authResult = (string) ($parsed['AuthResultCode'] ?? '');
        $ok = $rc === '0000' && ($authResult === '' || $authResult === '0000');
        $parsed['_raw'] = $rawBody;
        $bankTxn = (string) ($parsed['TransactionId'] ?? $parsed['transactionId'] ?? $fallbackTxn);
        if ($ok) {
            $response = PaymentResponse::success(
                $bankTxn,
                $orderId,
                (string) ($parsed['Message'] ?? $parsed['AuthResultDescription'] ?? 'Ödeme başarılı'),
                $parsed
            );
            $response->cardMask = (string) ($parsed['MaskedPan'] ?? $parsed['PanMasked'] ?? '');

            return $response;
        }

        $errMsg = $this->getVakifbankErrorDescription($rc !== '' ? $rc : 'UNKNOWN', $rawBody);

        return PaymentResponse::failed($errMsg, $rc !== '' ? $rc : null, $orderId, $parsed);
    }

    private function parseVakifbankStatusPayload(string $rawBody): ?array
    {
        $trimmed = trim($rawBody);
        $bom8 = "\xef\xbb\xbf";
        if (str_starts_with($trimmed, $bom8)) {
            $trimmed = substr($trimmed, strlen($bom8));
        }

        if (str_starts_with($trimmed, '{')) {
            $json = json_decode($trimmed, true);

            return is_array($json) ? $json : null;
        }

        $xml = @simplexml_load_string($trimmed);
        if ($xml === false) {
            return null;
        }

        $asArray = json_decode(json_encode($xml), true);

        return is_array($asArray) ? $asArray : null;
    }

    /**
     * GetVposTransaction PaymentToken bulamazsa Search (TransactionId).
     */
    private function searchVakifbankTransaction(string $orderId, string $transactionId, array $config): PaymentResponse
    {
        $postUrl = $this->vakifbankEndpoint('search');
        $xml = '<SearchRequest>'
            . '<MerchantCriteria>'
            . '<HostMerchantId>' . $this->xmlEscape((string) $config['clientId']) . '</HostMerchantId>'
            . '<MerchantPassword>' . $this->xmlEscape((string) $config['storeKey']) . '</MerchantPassword>'
            . '</MerchantCriteria>'
            . '<TransactionCriteria>'
            . '<TransactionId>' . $this->xmlEscape($transactionId) . '</TransactionId>'
            . '</TransactionCriteria>'
            . '</SearchRequest>';

        $rawBody = '';
        try {
            $rawBody = $this->postXml($postUrl, $xml);
            $doc = new DOMDocument();
            $useInternalErrors = libxml_use_internal_errors(true);
            $loaded = @$doc->loadXML($rawBody, LIBXML_NOERROR | LIBXML_NOCDATA);
            libxml_use_internal_errors($useInternalErrors);
            if (!$loaded) {
                return PaymentResponse::failed('Banka yanıtı işlenemedi', null, $orderId, ['_raw' => $rawBody]);
            }
            $code = $doc->getElementsByTagName('ResponseCode')->item(0)?->nodeValue ?? '';
            $resultCode = $doc->getElementsByTagName('ResultCode')->item(0)?->nodeValue ?? '';
            $hostResult = $doc->getElementsByTagName('HostResultCode')->item(0)?->nodeValue ?? '';
            $ok = ((string) $code === '0000' || (string) $resultCode === '0000')
                && ($hostResult === '' || $hostResult === '000' || $hostResult === '0000');
            $parsed = json_decode(json_encode(simplexml_load_string($rawBody)), true) ?: ['_raw' => $rawBody];
            $parsed['_raw'] = $rawBody;
            $bankTxn = $doc->getElementsByTagName('TransactionId')->item(0)?->nodeValue ?? $transactionId;
            if ($ok) {
                return PaymentResponse::success((string) $bankTxn, $orderId, 'Ödeme başarılı', $parsed);
            }

            $err = (string) ($resultCode !== '' ? $resultCode : $code);

            return PaymentResponse::failed(
                $this->getVakifbankErrorDescription($err !== '' ? $err : '5038', $rawBody),
                $err !== '' ? $err : null,
                $orderId,
                $parsed
            );
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
        if ($config['bank'] === 'vakifbank') {
            if (!$this->isVakifbankApigateway($config)) {
                return PaymentResponse::failed('Vakıfbank cpweb iptal desteklenmiyor', null, $orderId);
            }

            return $this->vakifbankVposRequest('Cancel', $orderId, $amount, $config);
        }

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
        if ($config['bank'] === 'vakifbank') {
            if (!$this->isVakifbankApigateway($config)) {
                return PaymentResponse::failed('Vakıfbank cpweb iade desteklenmiyor', null, $orderId);
            }
            $reference = $transactionId ?: $orderId;

            return $this->vakifbankVposRequest('Refund', $reference, $amount, $config);
        }

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

    /**
     * virtualPos/Vposreq XML Cancel | Refund.
     * $referenceId = banka TransactionId (UUID).
     */
    private function vakifbankVposRequest(string $type, string $referenceId, ?float $amount, array $config): PaymentResponse
    {
        $postUrl = $this->vakifbankEndpoint('vposreq');
        $xml = '<VposRequest>'
            . '<MerchantId>' . $this->xmlEscape((string) $config['clientId']) . '</MerchantId>'
            . '<Password>' . $this->xmlEscape((string) $config['storeKey']) . '</Password>'
            . '<TerminalNo>' . $this->xmlEscape((string) ($config['clientName'] ?? $config['clientId'])) . '</TerminalNo>'
            . '<TransactionType>' . $this->xmlEscape($type) . '</TransactionType>'
            . '<ReferenceTransactionId>' . $this->xmlEscape($referenceId) . '</ReferenceTransactionId>'
            . '<ClientIp>' . $this->xmlEscape($this->getClientIp()) . '</ClientIp>';
        if ($type === 'Refund' && $amount !== null) {
            $xml .= '<CurrencyAmount>' . $this->xmlEscape($this->formatAmount($amount)) . '</CurrencyAmount>';
        }
        $xml .= '</VposRequest>';

        $rawBody = '';
        try {
            $rawBody = $this->postXml($postUrl, $xml);
            $parsed = $this->parseVakifbankStatusPayload($rawBody);
            if ($parsed === null) {
                return PaymentResponse::failed('Banka yanıtı işlenemedi', null, $referenceId, ['_raw' => $rawBody]);
            }
            $code = (string) ($parsed['ResultCode'] ?? $parsed['Rc'] ?? '');
            $parsed['_raw'] = $rawBody;
            $newTxn = (string) ($parsed['TransactionId'] ?? $referenceId);
            if ($code === '0000') {
                $msg = $type === 'Refund' ? 'İade işlemi başarılı' : 'İptal işlemi başarılı';

                return PaymentResponse::success($newTxn, $referenceId, $msg, $parsed);
            }

            return PaymentResponse::failed(
                $this->getVakifbankErrorDescription($code !== '' ? $code : 'UNKNOWN', $rawBody),
                $code !== '' ? $code : null,
                $referenceId,
                $parsed
            );
        } catch (\Throwable $e) {
            return PaymentResponse::failed(
                $e->getMessage(),
                null,
                $referenceId,
                $rawBody !== '' ? ['_raw' => $rawBody] : []
            );
        }
    }

    public function handleCallback(array $data): PaymentResponse
    {
        $config = $this->getAccountConfig();

        if (($config['bank'] ?? '') === 'vakifbank') {
            $rc = (string) ($data['Rc'] ?? $data['rc'] ?? '');
            $orderId = (string) ($data['oid'] ?? $data['OrderId'] ?? $data['OrderID'] ?? $data['TransactionId'] ?? '');
            if ($this->isVakifbankApigateway($config)) {
                return PaymentResponse::pending($orderId, null, null, $data);
            }
            if ($rc === '0000') {
                return PaymentResponse::success(
                    (string) ($data['TransactionId'] ?? $orderId),
                    $orderId,
                    'Ödeme başarılı',
                    $data
                );
            }

            return PaymentResponse::failed(
                $this->getVakifbankErrorDescription($rc !== '' ? $rc : 'UNKNOWN'),
                $rc !== '' ? $rc : null,
                $orderId,
                $data
            );
        }

        $hashParams = $data['HASHPARAMS'] ?? '';
        $hashParamsVal = $data['HASHPARAMSVAL'] ?? '';
        $hash = $data['HASH'] ?? '';

        if (empty($hashParams) || empty($hashParamsVal) || empty($hash)) {
            return PaymentResponse::failed('Geçersiz callback verisi', null, $data['oid'] ?? null);
        }

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

        if ($mdStatus !== '1' && $mdStatus !== '2' && $mdStatus !== '3' && $mdStatus !== '4') {
            return PaymentResponse::failed('3D Secure doğrulama başarısız', $mdStatus, $orderId, $data);
        }

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
        return [];
    }

    private function getPaymentUrl(string $bank): string
    {
        $isTest = $this->isTestMode();

        if ($bank === 'vakifbank') {
            return $isTest
                ? 'https://test.get724.com.tr/vakifbank/3dgate'
                : 'https://www.get724.com.tr/vakifbank/3dgate';
        }

        return $isTest
            ? 'https://test.get724.com.tr/nestpay/est3Dgate'
            : 'https://www.get724.com.tr/nestpay/est3Dgate';
    }

    private function getApiUrl(string $bank): string
    {
        $isTest = $this->isTestMode();

        if ($bank === 'vakifbank') {
            return $isTest
                ? 'https://test.get724.com.tr/vakifbank/api'
                : 'https://www.get724.com.tr/vakifbank/api';
        }

        return $isTest
            ? 'https://test.get724.com.tr/nestpay/api'
            : 'https://www.get724.com.tr/nestpay/api';
    }

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

    private function buildForm(string $url, array $data): string
    {
        $form = '<form id="get724_form" method="post" action="' . htmlspecialchars($url) . '">';
        foreach ($data as $key => $value) {
            $form .= '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars((string) $value) . '">';
        }
        $form .= '</form>';
        $form .= '<script>document.getElementById("get724_form").submit();</script>';

        return $form;
    }

    private function isVakifbankApigateway(array $config): bool
    {
        $mode = strtolower(trim((string) ($config['vakifbankApi'] ?? $this->config->vakifbank['api'] ?? 'apigateway')));

        return $mode !== 'cpweb' && $mode !== 'legacy';
    }

    private function vakifbankEndpoint(string $key): string
    {
        $envKey = $this->isTestMode() ? 'test' : 'production';
        $urls = $this->config->vakifbank[$envKey] ?? [];
        $url = (string) ($urls[$key] ?? '');
        if ($url === '') {
            throw new ConfigurationException("Vakıfbank endpoint '{$key}' yapılandırılmamış");
        }

        return $url;
    }

    private function buildVakifbankTransactionId(string $orderId): string
    {
        $id = $orderId . '_' . time();
        if (strlen($id) > 40) {
            $id = substr($orderId, 0, 29) . '_' . time();
        }

        return $id;
    }

    private function vakifbankCallbackUrl(string $type, PaymentRequest $request): string
    {
        $url = $this->getCallbackUrl($type);
        $params = [];
        $token = (string) ($request->metadata['verifyToken'] ?? '');
        if ($token !== '') {
            $params['verifyToken'] = $token;
        }
        $oid = preg_replace('/^test_/', '', $request->orderId) ?? $request->orderId;
        if ($oid !== '') {
            $params['oid'] = $oid;
        }

        return $this->appendQuery($url, $params);
    }

    /**
     * @param array<string, scalar> $params
     */
    private function appendQuery(string $url, array $params): string
    {
        if ($params === []) {
            return $url;
        }
        $query = http_build_query($params);
        $separator = strpos($url, '?') !== false ? '&' : '?';

        return $url . $separator . $query;
    }

    private function looksLikePaymentToken(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $value);
    }

    private function normalizeTrPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = '90' . substr($digits, 1);
        } elseif (strlen($digits) === 10) {
            $digits = '90' . $digits;
        }

        return $digits;
    }

    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
