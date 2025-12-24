<?php

namespace Yakupeyisan\CodeIgniter4\VirtualPos\Config;

use CodeIgniter\Config\BaseConfig;

class VirtualPos extends BaseConfig
{
    /**
     * Aktif sanal pos sağlayıcısı
     * Seçenekler: nestpay, iyzico, paytr, paymes, bkm, get724
     */
    public string $provider = 'nestpay';

    /**
     * Test modu
     */
    public bool $testMode = true;

    /**
     * NestPay Ayarları (İş Bankası, Garanti, Akbank, Yapı Kredi)
     * Birden fazla hesap tanımlanabilir
     */
    public array $nestpay = [
        'defaultAccount' => 'default',
        'accounts' => [
            'default' => [
                'clientId' => '',
                'storeKey' => '',
                'storeType' => '3D_PAY_HOSTING', // 3d veya 3d_pay
                'bank' => 'isbank', // isbank, garanti, akbank, yapikredi
                'testUrl' => 'https://entegrasyon.asseco-see.com.tr/fim/est3Dgate',
                'productionUrl' => 'https://www.muze.com.tr/fim/est3Dgate',
            ],
        ],
    ];

    /**
     * İyzico Ayarları
     * Birden fazla hesap tanımlanabilir
     */
    public array $iyzico = [
        'defaultAccount' => 'default',
        'accounts' => [
            'default' => [
                'apiKey' => '',
                'secretKey' => '',
                'baseUrl' => 'https://api.iyzipay.com',
            ],
        ],
    ];

    /**
     * PayTR Ayarları
     * Birden fazla hesap tanımlanabilir
     */
    public array $paytr = [
        'defaultAccount' => 'default',
        'accounts' => [
            'default' => [
                'merchantId' => '',
                'merchantKey' => '',
                'merchantSalt' => '',
                'testUrl' => 'https://www.paytr.com/odeme',
                'productionUrl' => 'https://www.paytr.com/odeme',
            ],
        ],
    ];

    /**
     * Paymes Ayarları
     * Birden fazla hesap tanımlanabilir
     */
    public array $paymes = [
        'defaultAccount' => 'default',
        'accounts' => [
            'default' => [
                'apiKey' => '',
                'secretKey' => '',
                'merchantId' => '',
                'baseUrl' => 'https://api.paymes.com',
            ],
        ],
    ];

    /**
     * BKM Express Ayarları
     * Birden fazla hesap tanımlanabilir
     */
    public array $bkm = [
        'defaultAccount' => 'default',
        'accounts' => [
            'default' => [
                'merchantId' => '',
                'apiKey' => '',
                'secretKey' => '',
                'baseUrl' => 'https://www.bkmexpress.com.tr',
            ],
        ],
    ];

    /**
     * Get724 Ayarları
     * NestPay (EST): İş Bankası, Akbank, Finansbank, Denizbank, Kuveytturk, 
     * Halkbank, Anadolubank, ING Bank, Citibank, Cardplus, Ziraat Bankası
     * Vakıfbank: Özel entegrasyon
     * Birden fazla hesap tanımlanabilir
     */
    public array $get724 = [
        'defaultAccount' => 'default',
        'accounts' => [
            'default' => [
                'clientId' => '',
                'storeKey' => '',
                'storeType' => '3d', // 3d veya 3d_pay
                'bank' => 'isbank', // isbank, akbank, finansbank, denizbank, kuveytturk, 
                                   // halkbank, anadolubank, ingbank, citibank, cardplus, 
                                   // ziraat, vakifbank
            ],
        ],
    ];

    /**
     * Varsayılan para birimi
     */
    public string $currency = 'TRY';

    /**
     * Varsayılan dil
     */
    public string $language = 'tr';

    /**
     * Callback URL'leri
     */
    public array $callbackUrls = [
        'success' => '',
        'fail' => '',
        'callback' => '',
    ];

    /**
     * Timeout süresi (saniye)
     */
    public int $timeout = 30;

    /**
     * Constructor - .env dosyasından değerleri yükler
     */
    public function __construct()
    {
        parent::__construct();

        // Provider
        $this->provider = env('VIRTUALPOS_PROVIDER', $this->provider);
        $this->testMode = env('VIRTUALPOS_TEST_MODE', $this->testMode);

        // NestPay - Default account
        if (isset($this->nestpay['accounts']['default'])) {
            $this->nestpay['accounts']['default']['clientId'] = env('NESTPAY_CLIENT_ID', $this->nestpay['accounts']['default']['clientId']);
            $this->nestpay['accounts']['default']['storeKey'] = env('NESTPAY_STORE_KEY', $this->nestpay['accounts']['default']['storeKey']);
            $this->nestpay['accounts']['default']['storeType'] = env('NESTPAY_STORE_TYPE', $this->nestpay['accounts']['default']['storeType']);
            $this->nestpay['accounts']['default']['bank'] = env('NESTPAY_BANK', $this->nestpay['accounts']['default']['bank']);
        }

        // İyzico - Default account
        if (isset($this->iyzico['accounts']['default'])) {
            $this->iyzico['accounts']['default']['apiKey'] = env('IYZICO_API_KEY', $this->iyzico['accounts']['default']['apiKey']);
            $this->iyzico['accounts']['default']['secretKey'] = env('IYZICO_SECRET_KEY', $this->iyzico['accounts']['default']['secretKey']);
        }

        // PayTR - Default account
        if (isset($this->paytr['accounts']['default'])) {
            $this->paytr['accounts']['default']['merchantId'] = env('PAYTR_MERCHANT_ID', $this->paytr['accounts']['default']['merchantId']);
            $this->paytr['accounts']['default']['merchantKey'] = env('PAYTR_MERCHANT_KEY', $this->paytr['accounts']['default']['merchantKey']);
            $this->paytr['accounts']['default']['merchantSalt'] = env('PAYTR_MERCHANT_SALT', $this->paytr['accounts']['default']['merchantSalt']);
        }

        // Paymes - Default account
        if (isset($this->paymes['accounts']['default'])) {
            $this->paymes['accounts']['default']['apiKey'] = env('PAYMES_API_KEY', $this->paymes['accounts']['default']['apiKey']);
            $this->paymes['accounts']['default']['secretKey'] = env('PAYMES_SECRET_KEY', $this->paymes['accounts']['default']['secretKey']);
            $this->paymes['accounts']['default']['merchantId'] = env('PAYMES_MERCHANT_ID', $this->paymes['accounts']['default']['merchantId']);
        }

        // BKM Express - Default account
        if (isset($this->bkm['accounts']['default'])) {
            $this->bkm['accounts']['default']['merchantId'] = env('BKM_MERCHANT_ID', $this->bkm['accounts']['default']['merchantId']);
            $this->bkm['accounts']['default']['apiKey'] = env('BKM_API_KEY', $this->bkm['accounts']['default']['apiKey']);
            $this->bkm['accounts']['default']['secretKey'] = env('BKM_SECRET_KEY', $this->bkm['accounts']['default']['secretKey']);
        }

        // Get724 - Default account
        if (isset($this->get724['accounts']['default'])) {
            $this->get724['accounts']['default']['clientId'] = env('GET724_CLIENT_ID', $this->get724['accounts']['default']['clientId']);
            $this->get724['accounts']['default']['storeKey'] = env('GET724_STORE_KEY', $this->get724['accounts']['default']['storeKey']);
            $this->get724['accounts']['default']['storeType'] = env('GET724_STORE_TYPE', $this->get724['accounts']['default']['storeType']);
            $this->get724['accounts']['default']['bank'] = env('GET724_BANK', $this->get724['accounts']['default']['bank']);
        }

        // Callback URLs
        $this->callbackUrls['success'] = env('VIRTUALPOS_SUCCESS_URL', $this->callbackUrls['success']);
        $this->callbackUrls['fail'] = env('VIRTUALPOS_FAIL_URL', $this->callbackUrls['fail']);
        $this->callbackUrls['callback'] = env('VIRTUALPOS_CALLBACK_URL', $this->callbackUrls['callback']);

        // Diğer ayarlar
        $this->currency = env('VIRTUALPOS_CURRENCY', $this->currency);
        $this->language = env('VIRTUALPOS_LANGUAGE', $this->language);
        $this->timeout = (int)env('VIRTUALPOS_TIMEOUT', $this->timeout);
    }
}
