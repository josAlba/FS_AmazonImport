<?php

namespace FacturaScripts\Plugins\AmazonImport\Model;

class AmazonRow
{
    const COL_ORDER_ID = 'order-id';
    const COL_PURCHASE_DATE = 'purchase-date';
    const COL_BUYER_EMAIL = 'buyer-email';
    const COL_BUYER_NAME = 'buyer-name';
    const COL_BUYER_PHONE = 'buyer-phone-number';
    const COL_SKU = 'sku';
    const COL_PRODUCT_NAME = 'product-name';
    const COL_QUANTITY = 'quantity-purchased';
    const COL_ITEM_PRICE = 'item-price';
    const COL_ITEM_TAX = 'item-tax';
    const COL_SHIPPING_PRICE = 'shipping-price';
    const COL_SHIPPING_TAX = 'shipping-tax';
    const COL_GIFTWRAP_PRICE = 'gift-wrap-price';
    const COL_GIFTWRAP_TAX = 'gift-wrap-tax';

    /** @var array */
    protected $data;

    /** @var array */
    protected $colMap;

    public function __construct(array $data, array $colMap)
    {
        $this->data = $data;
        $this->colMap = $colMap;
    }

    public function getOrderId(): string
    {
        return $this->get(self::COL_ORDER_ID, 0);
    }

    public function getPurchaseDate(): string
    {
        return $this->get(self::COL_PURCHASE_DATE, 2);
    }

    public function getBuyerEmail(): string
    {
        return $this->get(self::COL_BUYER_EMAIL, 4);
    }

    public function getBuyerName(): string
    {
        return $this->get(self::COL_BUYER_NAME, 5);
    }

    public function getBuyerPhone(): string
    {
        return $this->get(self::COL_BUYER_PHONE, -1);
    }

    public function getSku(): string
    {
        return $this->get(self::COL_SKU, 7);
    }

    public function getProductName(): string
    {
        return $this->get(self::COL_PRODUCT_NAME, 9);
    }

    public function getQuantity(): float
    {
        return (float)$this->get(self::COL_QUANTITY, 10);
    }

    public function getTotalPrice(): float
    {
        return (float)$this->get(self::COL_ITEM_PRICE, 12);
    }

    public function getTaxAmount(): float
    {
        return (float)$this->get(self::COL_ITEM_TAX, 13);
    }

    public function getNetPriceTotal(): float
    {
        return $this->getTotalPrice() - $this->getTaxAmount();
    }

    public function getUnitPriceNet(): float
    {
        $qty = $this->getQuantity();
        return $this->getNetPriceTotal() / ($qty ?: 1);
    }

    public function getVatPercent(): float
    {
        $net = $this->getNetPriceTotal();
        $tax = $this->getTaxAmount();
        return ($tax > 0 && $net > 0) ? round(($tax / $net) * 100) : 0;
    }

    public function getShippingPrice(): float
    {
        return (float)$this->get(self::COL_SHIPPING_PRICE, -1);
    }

    public function getShippingTax(): float
    {
        return (float)$this->get(self::COL_SHIPPING_TAX, -1);
    }

    public function getGiftWrapPrice(): float
    {
        return (float)$this->get(self::COL_GIFTWRAP_PRICE, -1);
    }

    public function getGiftWrapTax(): float
    {
        return (float)$this->get(self::COL_GIFTWRAP_TAX, -1);
    }

    protected function get(string $key, int $defaultIndex): string
    {
        $index = $this->colMap[$key] ?? $defaultIndex;
        return $this->data[$index] ?? '';
    }
}