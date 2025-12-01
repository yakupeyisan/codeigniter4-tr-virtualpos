# Kullanım Örnekleri

## Controller Örneği

```php
<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use Yakupeyisan\CodeIgniter4\VirtualPos\VirtualPos;
use Yakupeyisan\CodeIgniter4\VirtualPos\Models\PaymentRequest;
use Yakupeyisan\CodeIgniter4\VirtualPos\Exceptions\PaymentException;
use Yakupeyisan\CodeIgniter4\VirtualPos\Exceptions\ConfigurationException;

class PaymentController extends Controller
{
    /**
     * Ödeme formunu göster
     */
    public function index()
    {
        return view('payment_form');
    }

    /**
     * Ödeme işlemini başlat
     */
    public function process()
    {
        $validation = \Config\Services::validation();
        
        $rules = [
            'amount' => 'required|numeric|greater_than[0]',
            'card_number' => 'required|min_length[16]|max_length[19]',
            'card_holder_name' => 'required|min_length[3]',
            'card_expiry_month' => 'required|numeric|exact_length[2]',
            'card_expiry_year' => 'required|numeric|exact_length[2]',
            'card_cvv' => 'required|numeric|exact_length[3]',
            'customer_name' => 'required',
            'customer_email' => 'required|valid_email',
            'customer_phone' => 'required',
        ];

        if (!$this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $validation->getErrors());
        }

        try {
            $virtualPos = new VirtualPos();
            
            // Ödeme isteği oluştur
            $request = new PaymentRequest(
                orderId: 'ORDER-' . time() . '-' . rand(1000, 9999),
                amount: (float)$this->request->getPost('amount'),
                cardNumber: $this->request->getPost('card_number'),
                cardHolderName: $this->request->getPost('card_holder_name'),
                cardExpiryMonth: $this->request->getPost('card_expiry_month'),
                cardExpiryYear: $this->request->getPost('card_expiry_year'),
                cardCvv: $this->request->getPost('card_cvv')
            );

            // Müşteri bilgileri
            $request->setCustomer(
                name: $this->request->getPost('customer_name'),
                email: $this->request->getPost('customer_email'),
                phone: $this->request->getPost('customer_phone')
            );

            // Fatura adresi
            if ($this->request->getPost('billing_address')) {
                $request->setBillingAddress(
                    address: $this->request->getPost('billing_address'),
                    city: $this->request->getPost('billing_city') ?? 'İstanbul',
                    country: $this->request->getPost('billing_country') ?? 'TR',
                    zipCode: $this->request->getPost('billing_zip_code')
                );
            }

            // Taksit seçeneği
            if ($this->request->getPost('installment')) {
                $request->installment = $this->request->getPost('installment');
            }

            // 3D Secure ödeme başlat
            $response = $virtualPos->pay3D($request);

            if ($response->status === 'pending') {
                // 3D Secure formunu göster
                return view('payment_3d', [
                    'htmlContent' => $response->htmlContent,
                    'redirectUrl' => $response->redirectUrl,
                    'orderId' => $request->orderId
                ]);
            }

            return redirect()->back()->with('error', $response->errorMessage ?? 'Ödeme başlatılamadı');
            
        } catch (ConfigurationException $e) {
            log_message('error', 'VirtualPos Config Error: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Ödeme sistemi yapılandırma hatası');
        } catch (PaymentException $e) {
            log_message('error', 'VirtualPos Payment Error: ' . $e->getMessage());
            return redirect()->back()->with('error', $e->getMessage());
        } catch (\Exception $e) {
            log_message('error', 'VirtualPos Error: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Bir hata oluştu');
        }
    }

    /**
     * 3D Secure callback işlemi
     */
    public function callback()
    {
        try {
            $virtualPos = new VirtualPos();
            
            // Callback verilerini al
            $callbackData = $this->request->getPost();
            
            // Provider'a göre farklı veri formatları olabilir
            if (empty($callbackData)) {
                $callbackData = $this->request->getGet();
            }

            // Callback'i işle
            $response = $virtualPos->handleCallback($callbackData);

            if ($response->success) {
                // Ödeme başarılı - veritabanına kaydet
                $this->savePayment($response);
                
                return redirect()->to('/payment/success')
                    ->with('transactionId', $response->transactionId)
                    ->with('orderId', $response->orderId);
            }

            return redirect()->to('/payment/fail')
                ->with('error', $response->errorMessage)
                ->with('orderId', $response->orderId);
            
        } catch (\Exception $e) {
            log_message('error', 'Callback Error: ' . $e->getMessage());
            return redirect()->to('/payment/fail')->with('error', 'Callback işleme hatası');
        }
    }

    /**
     * Başarılı ödeme sayfası
     */
    public function success()
    {
        $transactionId = session()->get('transactionId');
        $orderId = session()->get('orderId');

        return view('payment_success', [
            'transactionId' => $transactionId,
            'orderId' => $orderId
        ]);
    }

    /**
     * Başarısız ödeme sayfası
     */
    public function fail()
    {
        $error = session()->get('error');
        $orderId = session()->get('orderId');

        return view('payment_fail', [
            'error' => $error,
            'orderId' => $orderId
        ]);
    }

    /**
     * Ödeme durumu sorgulama
     */
    public function status($orderId)
    {
        try {
            $virtualPos = new VirtualPos();
            $response = $virtualPos->status($orderId);

            return $this->response->setJSON([
                'success' => $response->success,
                'status' => $response->status,
                'transactionId' => $response->transactionId,
                'message' => $response->message ?? $response->errorMessage
            ]);
        } catch (\Exception $e) {
            return $this->response->setJSON([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * İptal işlemi
     */
    public function cancel($orderId)
    {
        try {
            $virtualPos = new VirtualPos();
            $response = $virtualPos->cancel($orderId);

            if ($response->success) {
                return $this->response->setJSON([
                    'success' => true,
                    'message' => 'İptal işlemi başarılı'
                ]);
            }

            return $this->response->setJSON([
                'success' => false,
                'error' => $response->errorMessage
            ]);
        } catch (\Exception $e) {
            return $this->response->setJSON([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * İade işlemi
     */
    public function refund()
    {
        $validation = \Config\Services::validation();
        
        $rules = [
            'order_id' => 'required',
            'amount' => 'required|numeric|greater_than[0]',
        ];

        if (!$this->validate($rules)) {
            return $this->response->setJSON([
                'success' => false,
                'errors' => $validation->getErrors()
            ]);
        }

        try {
            $virtualPos = new VirtualPos();
            $response = $virtualPos->refund(
                orderId: $this->request->getPost('order_id'),
                amount: (float)$this->request->getPost('amount'),
                transactionId: $this->request->getPost('transaction_id')
            );

            if ($response->success) {
                return $this->response->setJSON([
                    'success' => true,
                    'message' => 'İade işlemi başarılı',
                    'transactionId' => $response->transactionId
                ]);
            }

            return $this->response->setJSON([
                'success' => false,
                'error' => $response->errorMessage
            ]);
        } catch (\Exception $e) {
            return $this->response->setJSON([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Taksit seçeneklerini getir
     */
    public function getInstallments()
    {
        $amount = (float)$this->request->getGet('amount');
        
        if ($amount <= 0) {
            return $this->response->setJSON([
                'success' => false,
                'error' => 'Geçersiz tutar'
            ]);
        }

        try {
            $virtualPos = new VirtualPos();
            $installments = $virtualPos->getInstallments($amount);

            return $this->response->setJSON([
                'success' => true,
                'installments' => $installments
            ]);
        } catch (\Exception $e) {
            return $this->response->setJSON([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Ödeme bilgisini veritabanına kaydet
     */
    private function savePayment($response)
    {
        // Burada ödeme bilgisini veritabanına kaydedebilirsiniz
        // Örnek:
        /*
        $db = \Config\Database::connect();
        $db->table('payments')->insert([
            'order_id' => $response->orderId,
            'transaction_id' => $response->transactionId,
            'amount' => $response->amount,
            'currency' => $response->currency,
            'status' => $response->status,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        */
    }
}
```

## Routes Örneği

```php
// app/Config/Routes.php

$routes->group('payment', function($routes) {
    $routes->get('/', 'PaymentController::index');
    $routes->post('process', 'PaymentController::process');
    $routes->post('callback', 'PaymentController::callback');
    $routes->get('success', 'PaymentController::success');
    $routes->get('fail', 'PaymentController::fail');
    $routes->get('status/(:segment)', 'PaymentController::status/$1');
    $routes->post('cancel/(:segment)', 'PaymentController::cancel/$1');
    $routes->post('refund', 'PaymentController::refund');
    $routes->get('installments', 'PaymentController::getInstallments');
});
```

## View Örnekleri

### payment_form.php

```php
<!DOCTYPE html>
<html>
<head>
    <title>Ödeme Formu</title>
</head>
<body>
    <h1>Ödeme Formu</h1>
    
    <?php if (session()->getFlashdata('error')): ?>
        <div class="error"><?= session()->getFlashdata('error') ?></div>
    <?php endif; ?>

    <form method="post" action="<?= base_url('payment/process') ?>">
        <div>
            <label>Tutar:</label>
            <input type="number" name="amount" step="0.01" required>
        </div>

        <div>
            <label>Kart Numarası:</label>
            <input type="text" name="card_number" maxlength="19" required>
        </div>

        <div>
            <label>Kart Sahibi:</label>
            <input type="text" name="card_holder_name" required>
        </div>

        <div>
            <label>Son Kullanma Ay:</label>
            <input type="text" name="card_expiry_month" maxlength="2" required>
        </div>

        <div>
            <label>Son Kullanma Yıl:</label>
            <input type="text" name="card_expiry_year" maxlength="2" required>
        </div>

        <div>
            <label>CVV:</label>
            <input type="text" name="card_cvv" maxlength="3" required>
        </div>

        <div>
            <label>Müşteri Adı:</label>
            <input type="text" name="customer_name" required>
        </div>

        <div>
            <label>E-posta:</label>
            <input type="email" name="customer_email" required>
        </div>

        <div>
            <label>Telefon:</label>
            <input type="text" name="customer_phone" required>
        </div>

        <div>
            <label>Fatura Adresi:</label>
            <input type="text" name="billing_address">
        </div>

        <div>
            <label>Fatura Şehir:</label>
            <input type="text" name="billing_city">
        </div>

        <div>
            <label>Taksit:</label>
            <select name="installment">
                <option value="">Tek Çekim</option>
                <option value="2">2 Taksit</option>
                <option value="3">3 Taksit</option>
                <option value="6">6 Taksit</option>
                <option value="9">9 Taksit</option>
                <option value="12">12 Taksit</option>
            </select>
        </div>

        <button type="submit">Ödeme Yap</button>
    </form>
</body>
</html>
```

### payment_3d.php

```php
<!DOCTYPE html>
<html>
<head>
    <title>3D Secure Ödeme</title>
</head>
<body>
    <h1>3D Secure Doğrulama</h1>
    <p>Lütfen bekleyin, ödeme sayfasına yönlendiriliyorsunuz...</p>
    
    <?php if (!empty($htmlContent)): ?>
        <?= $htmlContent ?>
    <?php elseif (!empty($redirectUrl)): ?>
        <script>
            window.location.href = '<?= $redirectUrl ?>';
        </script>
    <?php endif; ?>
</body>
</html>
```

### payment_success.php

```php
<!DOCTYPE html>
<html>
<head>
    <title>Ödeme Başarılı</title>
</head>
<body>
    <h1>Ödeme Başarılı!</h1>
    <p>Sipariş No: <?= $orderId ?></p>
    <p>İşlem No: <?= $transactionId ?></p>
    <a href="<?= base_url() ?>">Ana Sayfaya Dön</a>
</body>
</html>
```

### payment_fail.php

```php
<!DOCTYPE html>
<html>
<head>
    <title>Ödeme Başarısız</title>
</head>
<body>
    <h1>Ödeme Başarısız</h1>
    <p>Sipariş No: <?= $orderId ?></p>
    <p>Hata: <?= $error ?></p>
    <a href="<?= base_url('payment') ?>">Tekrar Dene</a>
</body>
</html>
```

