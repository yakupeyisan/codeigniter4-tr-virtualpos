# Çoklu Hesap Desteği

Bu paket, aynı banka için birden fazla hesap tanımlamanıza ve kullanmanıza olanak sağlar. Örneğin, Ziraat Bankası A hesabı ve Ziraat Bankası B hesabı gibi.

## Yapılandırma

### Config Dosyasında Hesap Tanımlama

`app/Config/VirtualPos.php` dosyasında birden fazla hesap tanımlayabilirsiniz:

```php
public array $get724 = [
    'defaultAccount' => 'ziraat_a', // Varsayılan hesap
    'accounts' => [
        'ziraat_a' => [
            'clientId' => 'ziraat_a_client_id',
            'storeKey' => 'ziraat_a_store_key',
            'storeType' => '3d',
            'bank' => 'ziraat',
        ],
        'ziraat_b' => [
            'clientId' => 'ziraat_b_client_id',
            'storeKey' => 'ziraat_b_store_key',
            'storeType' => '3d',
            'bank' => 'ziraat',
        ],
        'isbank_main' => [
            'clientId' => 'isbank_main_client_id',
            'storeKey' => 'isbank_main_store_key',
            'storeType' => '3d',
            'bank' => 'isbank',
        ],
    ],
];
```

## Kullanım

### Varsayılan Hesap ile Ödeme

```php
use Yakupeyisan\CodeIgniter4\VirtualPos\VirtualPos;
use Yakupeyisan\CodeIgniter4\VirtualPos\Models\PaymentRequest;

// Varsayılan hesap ile (ziraat_a)
$virtualPos = new VirtualPos();
$request = new PaymentRequest(...);
$response = $virtualPos->pay3D($request);
```

### Belirli Bir Hesap ile Ödeme

```php
// Ziraat B hesabı ile ödeme
$virtualPos = new VirtualPos(null, 'ziraat_b');
$request = new PaymentRequest(...);
$response = $virtualPos->pay3D($request);
```

### withAccount Metodu ile

```php
// Daha okunabilir bir yöntem
$virtualPos = new VirtualPos();
$virtualPosZiraatB = $virtualPos->withAccount('ziraat_b');
$response = $virtualPosZiraatB->pay3D($request);
```

## Örnek Senaryolar

### Senaryo 1: Farklı Mağazalar için Farklı Hesaplar

```php
// Mağaza A için Ziraat A hesabı
$storeA = new VirtualPos(null, 'ziraat_a');
$responseA = $storeA->pay3D($requestA);

// Mağaza B için Ziraat B hesabı
$storeB = new VirtualPos(null, 'ziraat_b');
$responseB = $storeB->pay3D($requestB);
```

### Senaryo 2: Tutara Göre Hesap Seçimi

```php
$virtualPos = new VirtualPos();

// 1000 TL'den küçük ödemeler için Ziraat A
if ($amount < 1000) {
    $virtualPos = $virtualPos->withAccount('ziraat_a');
} else {
    // 1000 TL ve üzeri için Ziraat B
    $virtualPos = $virtualPos->withAccount('ziraat_b');
}

$response = $virtualPos->pay3D($request);
```

### Senaryo 3: Controller'da Dinamik Hesap Seçimi

```php
class PaymentController extends BaseController
{
    public function process()
    {
        // Kullanıcıdan veya veritabanından hesap seçimi
        $accountId = $this->request->getPost('account_id') ?? 'default';
        
        $virtualPos = new VirtualPos(null, $accountId);
        $request = new PaymentRequest(...);
        $response = $virtualPos->pay3D($request);
        
        // ...
    }
}
```

## Tüm Provider'lar için Destek

Çoklu hesap desteği tüm provider'lar için mevcuttur:

- ✅ NestPay
- ✅ Get724
- ✅ İyzico
- ✅ PayTR
- ✅ Paymes
- ✅ BKM Express

## Notlar

1. **Default Account**: Eğer `accountId` belirtilmezse, `defaultAccount` kullanılır.
2. **Account ID**: Account ID'ler benzersiz olmalıdır ve config dosyasında tanımlı olmalıdır.
3. **Hata Yönetimi**: Tanımlı olmayan bir account ID kullanılırsa `ConfigurationException` fırlatılır.

## Örnek Config Dosyası

```php
public array $get724 = [
    'defaultAccount' => 'ziraat_a',
    'accounts' => [
        'ziraat_a' => [
            'clientId' => 'ziraat_a_client_id',
            'storeKey' => 'ziraat_a_store_key',
            'storeType' => '3d',
            'bank' => 'ziraat',
        ],
        'ziraat_b' => [
            'clientId' => 'ziraat_b_client_id',
            'storeKey' => 'ziraat_b_store_key',
            'storeType' => '3d',
            'bank' => 'ziraat',
        ],
        'isbank_main' => [
            'clientId' => 'isbank_main_client_id',
            'storeKey' => 'isbank_main_store_key',
            'storeType' => '3d',
            'bank' => 'isbank',
        ],
        'akbank_secondary' => [
            'clientId' => 'akbank_secondary_client_id',
            'storeKey' => 'akbank_secondary_store_key',
            'storeType' => '3d',
            'bank' => 'akbank',
        ],
    ],
];
```

Bu yapılandırma ile:
- Varsayılan olarak `ziraat_a` hesabı kullanılır
- İstediğiniz zaman `ziraat_b`, `isbank_main` veya `akbank_secondary` hesaplarını kullanabilirsiniz

