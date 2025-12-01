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

    public function __construct(?VirtualPosConfig $config = null)
    {
        $this->config = $config ?? config('VirtualPos');
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
                $this->provider = new NestPayProvider($this->config);
                break;
            case 'iyzico':
                $this->provider = new IyzicoProvider($this->config);
                break;
            case 'paytr':
                $this->provider = new PayTRProvider($this->config);
                break;
            case 'paymes':
                $this->provider = new PaymesProvider($this->config);
                break;
            case 'bkm':
                $this->provider = new BKMExpressProvider($this->config);
                break;
            case 'get724':
                $this->provider = new Get724Provider($this->config);
                break;
            default:
                throw new ConfigurationException("Bilinmeyen provider: {$providerName}");
        }
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

