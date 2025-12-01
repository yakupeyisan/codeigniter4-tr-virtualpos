# CodeIgniter 4 Türkiye Sanal Pos Paketi

Türkiye'deki tüm bankalarla çalışan kapsamlı CodeIgniter 4 sanal pos paketi. NestPay, İyzico, PayTR, Paymes ve BKM Express gibi popüler ödeme sağlayıcılarını destekler.

## Özellikler

- ✅ **NestPay** - İş Bankası, Garanti, Akbank, Yapı Kredi
- ✅ **Get724** - NestPay (EST): İş Bankası, Akbank, Finansbank, Denizbank, Kuveytturk, Halkbank, Anadolubank, ING Bank, Citibank, Cardplus, Ziraat Bankası | Vakıfbank
- ✅ **İyzico** - Popüler ödeme gateway'i
- ✅ **PayTR** - Hızlı ve güvenli ödeme
- ✅ **Paymes** - Modern ödeme çözümü
- ✅ **BKM Express** - BKM altyapısı
- ✅ 3D Secure desteği
- ✅ Taksit seçenekleri
- ✅ İptal ve iade işlemleri
- ✅ .env dosyası ile kolay yapılandırma
- ✅ Test ve production modları

## Kurulum

```bash
composer require yakupeyisan/codeigniter4-tr-virtualpos
```

## Yapılandırma

### 1. Config Dosyasını Kopyalayın

Config dosyasını CodeIgniter 4'ün config klasörüne kopyalayın:

```bash
cp vendor/yakupeyisan/codeigniter4-tr-virtualpos/src/Config/VirtualPos.php app/Config/VirtualPos.php
```

### 2. .env Dosyasına Ayarları Ekleyin

`.env` dosyanıza aşağıdaki ayarları ekleyin:

```env
# Sanal Pos Genel Ayarları
VIRTUALPOS_PROVIDER=nestpay
VIRTUALPOS_TEST_MODE=true
VIRTUALPOS_CURRENCY=TRY
VIRTUALPOS_LANGUAGE=tr
VIRTUALPOS_TIMEOUT=30

# Callback URL'leri
VIRTUALPOS_SUCCESS_URL=https://yoursite.com/payment/success
VIRTUALPOS_FAIL_URL=https://yoursite.com/payment/fail
VIRTUALPOS_CALLBACK_URL=https://yoursite.com/payment/callback

# NestPay Ayarları (İş Bankası, Garanti, Akbank, Yapı Kredi)
NESTPAY_CLIENT_ID=your_client_id
NESTPAY_STORE_KEY=your_store_key
NESTPAY_STORE_TYPE=3d
NESTPAY_BANK=isbank

# İyzico Ayarları
IYZICO_API_KEY=your_api_key
IYZICO_SECRET_KEY=your_secret_key

# PayTR Ayarları
PAYTR_MERCHANT_ID=your_merchant_id
PAYTR_MERCHANT_KEY=your_merchant_key
PAYTR_MERCHANT_SALT=your_merchant_salt

# Paymes Ayarları
PAYMES_API_KEY=your_api_key
PAYMES_SECRET_KEY=your_secret_key
PAYMES_MERCHANT_ID=your_merchant_id

# BKM Express Ayarları
BKM_MERCHANT_ID=your_merchant_id
BKM_API_KEY=your_api_key
BKM_SECRET_KEY=your_secret_key

# Get724 Ayarları
GET724_CLIENT_ID=your_client_id
GET724_STORE_KEY=your_store_key
GET724_STORE_TYPE=3d
GET724_BANK=isbank
# Banka seçenekleri: isbank, akbank, finansbank, denizbank, kuveytturk, 
# halkbank, anadolubank, ingbank, citibank, cardplus, ziraat, vakifbank
```

## Kullanım

### Temel Ödeme İşlemi

```php
use Yakupeyisan\CodeIgniter4\VirtualPos\VirtualPos;
use Yakupeyisan\CodeIgniter4\VirtualPos\Models\PaymentRequest;

// VirtualPos instance oluştur
$virtualPos = new VirtualPos();

// Ödeme isteği oluştur
$request = new PaymentRequest(
    orderId: 'ORDER-' . time(),
    amount: 100.00,
    cardNumber: '5406670000000001',
    cardHolderName: 'John Doe',
    cardExpiryMonth: '12',
    cardExpiryYear: '25',
    cardCvv: '123'
);

// Müşteri bilgileri
$request->setCustomer(
    name: 'John Doe',
    email: 'john@example.com',
    phone: '5551234567'
);

// Fatura adresi
$request->setBillingAddress(
    address: 'Test Mahallesi, Test Sokak No:1',
    city: 'İstanbul',
    country: 'TR',
    zipCode: '34000'
);

// 3D Secure ödeme başlat
$response = $virtualPos->pay3D($request);

if ($response->status === 'pending') {
    // HTML içeriği göster (3D Secure formu)
    echo $response->htmlContent;
}
```

### Callback İşleme (3D Secure Sonrası)

```php
use Yakupeyisan\CodeIgniter4\VirtualPos\VirtualPos;

$virtualPos = new VirtualPos();

// Callback verilerini al
$callbackData = $this->request->getPost();

// Callback'i işle
$response = $virtualPos->handleCallback($callbackData);

if ($response->success) {
    // Ödeme başarılı
    echo "Ödeme başarılı! Transaction ID: " . $response->transactionId;
} else {
    // Ödeme başarısız
    echo "Ödeme başarısız: " . $response->errorMessage;
}
```

### Ödeme Durumu Sorgulama

```php
$response = $virtualPos->status('ORDER-1234567890');

if ($response->success) {
    echo "Ödeme durumu: " . $response->status;
}
```

### İptal İşlemi

```php
$response = $virtualPos->cancel('ORDER-1234567890');

if ($response->success) {
    echo "İptal işlemi başarılı";
}
```

### İade İşlemi

```php
$response = $virtualPos->refund(
    orderId: 'ORDER-1234567890',
    amount: 50.00,
    transactionId: 'TXN-1234567890'
);

if ($response->success) {
    echo "İade işlemi başarılı";
}
```

### Taksit Seçeneklerini Getirme

```php
$installments = $virtualPos->getInstallments(1000.00);

foreach ($installments as $installment) {
    echo "Taksit: " . $installment['installment'] . " - Faiz: " . $installment['interest'];
}
```

## Provider'lar

### NestPay

NestPay, İş Bankası, Garanti, Akbank ve Yapı Kredi için ortak altyapı sağlar.

```env
VIRTUALPOS_PROVIDER=nestpay
NESTPAY_CLIENT_ID=your_client_id
NESTPAY_STORE_KEY=your_store_key
NESTPAY_STORE_TYPE=3d
NESTPAY_BANK=isbank
```

Desteklenen bankalar:
- `isbank` - İş Bankası
- `garanti` - Garanti BBVA
- `akbank` - Akbank
- `yapikredi` - Yapı Kredi

### İyzico

```env
VIRTUALPOS_PROVIDER=iyzico
IYZICO_API_KEY=your_api_key
IYZICO_SECRET_KEY=your_secret_key
```

### PayTR

```env
VIRTUALPOS_PROVIDER=paytr
PAYTR_MERCHANT_ID=your_merchant_id
PAYTR_MERCHANT_KEY=your_merchant_key
PAYTR_MERCHANT_SALT=your_merchant_salt
```

### Paymes

```env
VIRTUALPOS_PROVIDER=paymes
PAYMES_API_KEY=your_api_key
PAYMES_SECRET_KEY=your_secret_key
PAYMES_MERCHANT_ID=your_merchant_id
```

### BKM Express

```env
VIRTUALPOS_PROVIDER=bkm
BKM_MERCHANT_ID=your_merchant_id
BKM_API_KEY=your_api_key
BKM_SECRET_KEY=your_secret_key
```

### Get724

Get724, NestPay (EST) altyapısını kullanan birçok bankayı ve Vakıfbank'ı destekler.

```env
VIRTUALPOS_PROVIDER=get724
GET724_CLIENT_ID=your_client_id
GET724_STORE_KEY=your_store_key
GET724_STORE_TYPE=3d
GET724_BANK=isbank
```

Desteklenen bankalar (NestPay EST):
- `isbank` - İş Bankası
- `akbank` - Akbank
- `finansbank` - QNB Finansbank
- `denizbank` - Denizbank
- `kuveytturk` - Kuveyt Türk
- `halkbank` - Halkbank
- `anadolubank` - Anadolubank
- `ingbank` - ING Bank
- `citibank` - Citibank
- `cardplus` - Cardplus
- `ziraat` - Ziraat Bankası

Desteklenen bankalar (Özel entegrasyon):
- `vakifbank` - Vakıfbank

## Controller Örneği

```php
<?php

namespace App\Controllers;

use Yakupeyisan\CodeIgniter4\VirtualPos\VirtualPos;
use Yakupeyisan\CodeIgniter4\VirtualPos\Models\PaymentRequest;

class Payment extends BaseController
{
    public function pay()
    {
        $virtualPos = new VirtualPos();
        
        $request = new PaymentRequest(
            orderId: 'ORDER-' . time(),
            amount: 100.00,
            cardNumber: $this->request->getPost('card_number'),
            cardHolderName: $this->request->getPost('card_holder_name'),
            cardExpiryMonth: $this->request->getPost('card_expiry_month'),
            cardExpiryYear: $this->request->getPost('card_expiry_year'),
            cardCvv: $this->request->getPost('card_cvv')
        );
        
        $request->setCustomer(
            name: $this->request->getPost('customer_name'),
            email: $this->request->getPost('customer_email'),
            phone: $this->request->getPost('customer_phone')
        );
        
        $response = $virtualPos->pay3D($request);
        
        if ($response->status === 'pending') {
            return view('payment_form', ['htmlContent' => $response->htmlContent]);
        }
        
        return redirect()->back()->with('error', $response->errorMessage);
    }
    
    public function callback()
    {
        $virtualPos = new VirtualPos();
        $response = $virtualPos->handleCallback($this->request->getPost());
        
        if ($response->success) {
            // Ödeme başarılı - veritabanına kaydet
            return redirect()->to('/payment/success');
        }
        
        return redirect()->to('/payment/fail');
    }
}
```

## Hata Yönetimi

```php
use Yakupeyisan\CodeIgniter4\VirtualPos\Exceptions\PaymentException;
use Yakupeyisan\CodeIgniter4\VirtualPos\Exceptions\ConfigurationException;

try {
    $response = $virtualPos->pay3D($request);
} catch (ConfigurationException $e) {
    // Yapılandırma hatası
    log_message('error', 'VirtualPos Config Error: ' . $e->getMessage());
} catch (PaymentException $e) {
    // Ödeme hatası
    log_message('error', 'VirtualPos Payment Error: ' . $e->getMessage());
    log_message('error', 'Error Code: ' . $e->getErrorCode());
}
```

## Test Kartları

### NestPay Test Kartları

- **Kart Numarası**: 5406670000000001
- **CVV**: Herhangi bir 3 haneli sayı
- **Son Kullanma**: Gelecek bir tarih

### İyzico Test Kartları

- **Kart Numarası**: 5528790000000008
- **CVV**: 123
- **Son Kullanma**: 12/25

## Güvenlik

- Tüm ödeme işlemleri HTTPS üzerinden yapılır
- Hash doğrulamaları otomatik olarak yapılır
- Kart bilgileri asla loglanmaz
- 3D Secure desteği tüm provider'larda mevcuttur

## Lisans

MIT License

## Destek

Sorularınız için: yakupeyisan@gmail.com

## Katkıda Bulunma

Pull request'ler memnuniyetle karşılanır. Büyük değişiklikler için önce bir issue açarak neyi değiştirmek istediğinizi tartışın.

## Changelog

### 1.0.0
- İlk sürüm
- NestPay, İyzico, PayTR, Paymes, BKM Express desteği
- 3D Secure desteği
- İptal ve iade işlemleri
- .env yapılandırması

