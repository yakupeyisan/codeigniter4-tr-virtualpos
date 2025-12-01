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
     */
    public array $nestpay = [
        'clientId' => '',
        'storeKey' => '',
        'storeType' => '3d', // 3d veya 3d_pay
        'bank' => 'isbank', // isbank, garanti, akbank, yapikredi
        'testUrl' => 'https://entegrasyon.asseco-see.com.tr/fim/est3Dgate',
        'productionUrl' => 'https://www.muze.com.tr/fim/est3Dgate',
    ];

    /**
     * İyzico Ayarları
     */
    public array $iyzico = [
        'apiKey' => '',
        'secretKey' => '',
        'baseUrl' => 'https://api.iyzipay.com',
    ];

    /**
     * PayTR Ayarları
     */
    public array $paytr = [
        'merchantId' => '',
        'merchantKey' => '',
        'merchantSalt' => '',
        'testUrl' => 'https://www.paytr.com/odeme',
        'productionUrl' => 'https://www.paytr.com/odeme',
    ];

    /**
     * Paymes Ayarları
     */
    public array $paymes = [
        'apiKey' => '',
        'secretKey' => '',
        'merchantId' => '',
        'baseUrl' => 'https://api.paymes.com',
    ];

    /**
     * BKM Express Ayarları
     */
    public array $bkm = [
        'merchantId' => '',
        'apiKey' => '',
        'secretKey' => '',
        'baseUrl' => 'https://www.bkmexpress.com.tr',
    ];

    /**
     * Get724 Ayarları
     * NestPay (EST): İş Bankası, Akbank, Finansbank, Denizbank, Kuveytturk, 
     * Halkbank, Anadolubank, ING Bank, Citibank, Cardplus, Ziraat Bankası
     * Vakıfbank: Özel entegrasyon
     */
    public array $get724 = [
        'clientId' => '',
        'storeKey' => '',
        'storeType' => '3d', // 3d veya 3d_pay
        'bank' => 'isbank', // isbank, akbank, finansbank, denizbank, kuveytturk, 
                           // halkbank, anadolubank, ingbank, citibank, cardplus, 
                           // ziraat, vakifbank
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

        // NestPay
        $this->nestpay['clientId'] = env('NESTPAY_CLIENT_ID', $this->nestpay['clientId']);
        $this->nestpay['storeKey'] = env('NESTPAY_STORE_KEY', $this->nestpay['storeKey']);
        $this->nestpay['storeType'] = env('NESTPAY_STORE_TYPE', $this->nestpay['storeType']);
        $this->nestpay['bank'] = env('NESTPAY_BANK', $this->nestpay['bank']);

        // İyzico
        $this->iyzico['apiKey'] = env('IYZICO_API_KEY', $this->iyzico['apiKey']);
        $this->iyzico['secretKey'] = env('IYZICO_SECRET_KEY', $this->iyzico['secretKey']);

        // PayTR
        $this->paytr['merchantId'] = env('PAYTR_MERCHANT_ID', $this->paytr['merchantId']);
        $this->paytr['merchantKey'] = env('PAYTR_MERCHANT_KEY', $this->paytr['merchantKey']);
        $this->paytr['merchantSalt'] = env('PAYTR_MERCHANT_SALT', $this->paytr['merchantSalt']);

        // Paymes
        $this->paymes['apiKey'] = env('PAYMES_API_KEY', $this->paymes['apiKey']);
        $this->paymes['secretKey'] = env('PAYMES_SECRET_KEY', $this->paymes['secretKey']);
        $this->paymes['merchantId'] = env('PAYMES_MERCHANT_ID', $this->paymes['merchantId']);

        // BKM Express
        $this->bkm['merchantId'] = env('BKM_MERCHANT_ID', $this->bkm['merchantId']);
        $this->bkm['apiKey'] = env('BKM_API_KEY', $this->bkm['apiKey']);
        $this->bkm['secretKey'] = env('BKM_SECRET_KEY', $this->bkm['secretKey']);

        // Get724
        $this->get724['clientId'] = env('GET724_CLIENT_ID', $this->get724['clientId']);
        $this->get724['storeKey'] = env('GET724_STORE_KEY', $this->get724['storeKey']);
        $this->get724['storeType'] = env('GET724_STORE_TYPE', $this->get724['storeType']);
        $this->get724['bank'] = env('GET724_BANK', $this->get724['bank']);

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
