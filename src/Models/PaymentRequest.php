<?php

namespace Yakupeyisan\CodeIgniter4\VirtualPos\Models;

class PaymentRequest
{
    public string $orderId;
    public float $amount;
    public string $currency = 'TRY';
    public string $language = 'tr';
    public string $cardNumber;
    public string $cardHolderName;
    public string $cardExpiryMonth;
    public string $cardExpiryYear;
    public string $cardCvv;
    public ?string $installment = null;
    public ?string $customerName = null;
    public ?string $customerEmail = null;
    public ?string $customerPhone = null;
    public ?string $customerIp = null;
    public ?string $billingAddress = null;
    public ?string $billingCity = null;
    public ?string $billingCountry = null;
    public ?string $billingZipCode = null;
    public ?string $shippingAddress = null;
    public ?string $shippingCity = null;
    public ?string $shippingCountry = null;
    public ?string $shippingZipCode = null;
    public array $items = [];
    public ?string $description = null;
    public array $metadata = [];

    public function __construct(
        string $orderId,
        float $amount,
        string $cardNumber = '',
        string $cardHolderName = '',
        string $cardExpiryMonth = '',
        string $cardExpiryYear = '',
        string $cardCvv = ''
    ) {
        $this->orderId = $orderId;
        $this->amount = $amount;
        $this->cardNumber = $cardNumber;
        $this->cardHolderName = $cardHolderName;
        $this->cardExpiryMonth = $cardExpiryMonth;
        $this->cardExpiryYear = $cardExpiryYear;
        $this->cardCvv = $cardCvv;
    }

    public function addItem(string $name, float $price, int $quantity = 1, ?string $code = null): self
    {
        $this->items[] = [
            'name' => $name,
            'price' => $price,
            'quantity' => $quantity,
            'code' => $code,
        ];
        return $this;
    }

    public function setCustomer(string $name, string $email, ?string $phone = null, ?string $ip = null): self
    {
        $this->customerName = $name;
        $this->customerEmail = $email;
        $this->customerPhone = $phone;
        $this->customerIp = $ip ?? $_SERVER['REMOTE_ADDR'] ?? '';
        return $this;
    }

    public function setBillingAddress(string $address, string $city, string $country = 'TR', ?string $zipCode = null): self
    {
        $this->billingAddress = $address;
        $this->billingCity = $city;
        $this->billingCountry = $country;
        $this->billingZipCode = $zipCode;
        return $this;
    }

    public function setShippingAddress(string $address, string $city, string $country = 'TR', ?string $zipCode = null): self
    {
        $this->shippingAddress = $address;
        $this->shippingCity = $city;
        $this->shippingCountry = $country;
        $this->shippingZipCode = $zipCode;
        return $this;
    }
}

