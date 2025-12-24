<?php

namespace Yakupeyisan\CodeIgniter4\VirtualPos\Base;

use CodeIgniter\HTTP\CURLRequest;
use Yakupeyisan\CodeIgniter4\VirtualPos\Config\VirtualPos;
use Yakupeyisan\CodeIgniter4\VirtualPos\Contracts\VirtualPosInterface;
use Yakupeyisan\CodeIgniter4\VirtualPos\Exceptions\ConfigurationException;
use Yakupeyisan\CodeIgniter4\VirtualPos\Models\PaymentRequest;
use Yakupeyisan\CodeIgniter4\VirtualPos\Models\PaymentResponse;

abstract class VirtualPosBase implements VirtualPosInterface
{
    protected VirtualPos $config;
    protected CURLRequest $client;
    protected bool $testMode;
    protected ?string $accountId = null;

    public function __construct(?VirtualPos $config = null, ?string $accountId = null)
    {
        $this->config = $config ?? config('VirtualPos');
        $this->accountId = $accountId;
        $this->testMode = $this->config->testMode;
        $this->client = \Config\Services::curlrequest();
        $this->validateConfiguration();
    }

    /**
     * Yapılandırma doğrulaması
     */
    abstract protected function validateConfiguration(): void;

    /**
     * Test modunda mı?
     */
    protected function isTestMode(): bool
    {
        return $this->testMode;
    }

    /**
     * HTTP POST isteği gönderir
     */
    protected function post(string $url, array $data, array $headers = []): array|string
    {
        try {
            $response = $this->client->request('POST', $url, [
                'form_params' => $data,
                'headers' => array_merge([
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ], $headers),
                'timeout' => $this->config->timeout,
                'verify' => true,
            ]);

            $body = $response->getBody();
            $decoded = json_decode($body, true);
            
            // JSON decode başarısız olursa string olarak döndür
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $body;
            }
            
            return $decoded ?? [];
        } catch (\Exception $e) {
            throw new \RuntimeException('HTTP isteği başarısız: ' . $e->getMessage());
        }
    }

    /**
     * HTTP GET isteği gönderir
     */
    protected function get(string $url, array $params = [], array $headers = []): array
    {
        try {
            $response = $this->client->request('GET', $url, [
                'query' => $params,
                'headers' => $headers,
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
     * Kart numarasını temizler
     */
    protected function cleanCardNumber(string $cardNumber): string
    {
        return preg_replace('/\s+/', '', $cardNumber);
    }

    /**
     * IP adresini alır
     */
    protected function getClientIp(): string
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        }
    }

    /**
     * Callback URL'lerini alır
     */
    protected function getCallbackUrl(string $type = 'success'): string
    {
        $url = $this->config->callbackUrls[$type] ?? '';
        if (empty($url)) {
            $baseUrl = base_url();
            return $baseUrl . '/payment/' . $type;
        }
        return $url;
    }

    /**
     * Account ID'yi alır
     */
    public function getAccountId(): ?string
    {
        return $this->accountId;
    }

    /**
     * Account ID'yi ayarlar
     */
    public function setAccountId(?string $accountId): void
    {
        $this->accountId = $accountId;
    }
}

