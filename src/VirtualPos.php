<?php

namespace Yakupeyisan\CodeIgniter4\VirtualPos;

use Yakupeyisan\CodeIgniter4\VirtualPos\Config\VirtualPos as VirtualPosConfig;
use Yakupeyisan\CodeIgniter4\VirtualPos\Contracts\VirtualPosInterface;
use Yakupeyisan\CodeIgniter4\VirtualPos\Exceptions\ConfigurationException;
use Yakupeyisan\CodeIgniter4\VirtualPos\Providers\BKMExpressProvider;
use Yakupeyisan\CodeIgniter4\VirtualPos\Providers\Get724Provider;
use Yakupeyisan\CodeIgniter4\VirtualPos\Providers\IyzicoProvider;
use Yakupeyisan\CodeIgniter4\VirtualPos\Providers\NestPayProvider;
use Yakupeyisan\CodeIgniter4\VirtualPos\Providers\PaymesProvider;
use Yakupeyisan\CodeIgniter4\VirtualPos\Providers\PayTRProvider;

class VirtualPos
{
    protected VirtualPosConfig $config;
    protected ?VirtualPosInterface $provider = null;
    protected ?string $accountId = null;

    public function __construct(?VirtualPosConfig $config = null, ?string $accountId = null)
    {
        $this->config = $config ?? config('VirtualPos');
        $this->accountId = $accountId;
        $this->loadProvider();
    }

    /**
     * Provider'ı yükler
     */
    protected function loadProvider(): void
    {
        $providerName = $this->config->provider;

        switch ($providerName) {
            case 'nestpay':
                $this->provider = new NestPayProvider($this->config, $this->accountId);
                break;
            case 'iyzico':
                $this->provider = new IyzicoProvider($this->config, $this->accountId);
                break;
            case 'paytr':
                $this->provider = new PayTRProvider($this->config, $this->accountId);
                break;
            case 'paymes':
                $this->provider = new PaymesProvider($this->config, $this->accountId);
                break;
            case 'bkm':
                $this->provider = new BKMExpressProvider($this->config, $this->accountId);
                break;
            case 'get724':
                $this->provider = new Get724Provider($this->config, $this->accountId);
                break;
            default:
                throw new ConfigurationException("Bilinmeyen provider: {$providerName}");
        }
    }

    /**
     * Farklı bir hesap ile yeni bir instance oluşturur
     */
    public function withAccount(string $accountId): self
    {
        return new self($this->config, $accountId);
    }

    /**
     * Ödeme işlemi başlatır
     */
    public function pay($request)
    {
        return $this->provider->pay($request);
    }

    /**
     * 3D Secure ödeme işlemi başlatır
     */
    public function pay3D($request)
    {
        return $this->provider->pay3D($request);
    }

    /**
     * Ödeme durumunu sorgular
     */
    public function status(string $orderId)
    {
        return $this->provider->status($orderId);
    }

    /**
     * Ödeme iptal eder
     */
    public function cancel(string $orderId, ?float $amount = null)
    {
        return $this->provider->cancel($orderId, $amount);
    }

    /**
     * Ödeme iade eder
     */
    public function refund(string $orderId, float $amount, ?string $transactionId = null)
    {
        return $this->provider->refund($orderId, $amount, $transactionId);
    }

    /**
     * Callback'i işler
     */
    public function handleCallback(array $data)
    {
        return $this->provider->handleCallback($data);
    }

    /**
     * Taksit seçeneklerini getirir
     */
    public function getInstallments(float $amount): array
    {
        return $this->provider->getInstallments($amount);
    }

    /**
     * Provider instance'ını döndürür
     */
    public function getProvider(): VirtualPosInterface
    {
        return $this->provider;
    }
}

