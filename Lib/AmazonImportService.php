<?php

namespace FacturaScripts\Plugins\AmazonImport\Lib;

use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Dinamic\Model\Variante;
use FacturaScripts\Dinamic\Model\Impuesto;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Dinamic\Lib\BusinessDocumentCode;
use FacturaScripts\Plugins\AmazonImport\Model\AmazonRow;

class AmazonImportService
{
    const REF_SHIPPING = 'TRANSPORTE';
    const REF_GIFTWRAP = 'GIFTWRAP';

    /** @var int */
    protected $createdCount = 0;

    /** @var int */
    protected $updatedCount = 0;

    /** @var int */
    protected $skippedCount = 0;

    public function import(string $filePath, string $mode)
    {
        $orders = $this->parseReportFile($filePath);
        if (empty($orders)) {
            return;
        }

        $lastDate = $this->getLastInvoiceDate();
        $this->processOrdersList($orders, $mode, $lastDate);
        $this->reportResults();
    }

    protected function parseReportFile(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            Tools::log()->error('error-opening-file');
            return [];
        }

        $headers = fgetcsv($handle, 0, "\t");
        if (false === $headers) {
            fclose($handle);
            Tools::log()->error('invalid-empty-file');
            return [];
        }

        $headers = array_map('trim', $headers);
        $colMap = array_flip($headers);

        $orders = [];
        while (($data = fgetcsv($handle, 0, "\t")) !== false) {
            $data = array_map('trim', $data);
            if (count($data) < 5) {
                continue;
            }

            $row = new AmazonRow($data, $colMap);
            $orderId = $row->getOrderId();
            if (empty($orderId)) {
                continue;
            }

            if (!isset($orders[$orderId])) {
                $orders[$orderId] = [];
            }
            $orders[$orderId][] = $row;
        }
        fclose($handle);

        return $orders;
    }

    protected function getLastInvoiceDate(): ?string
    {
        $fc = new FacturaCliente();
        $lastInvoice = $fc->all([], ['fecha' => 'DESC', 'idfactura' => 'DESC'], 0, 1);
        if ($lastInvoice) {
            // Asegurarse de que la fecha tenga hora
            $fecha = $lastInvoice[0]->fecha;
            if (strlen($fecha) <= 10) {
                // Solo tiene fecha, añadir hora
                $fecha .= ' 12:00:00';
            }
            return $fecha;
        }
        return null;
    }

    protected function processOrdersList(array $orders, string $mode, ?string $lastDate)
    {
        $this->createdCount = 0;
        $this->updatedCount = 0;
        $this->skippedCount = 0;

        // Ordenar pedidos por fecha de compra para mantener secuencia temporal
        uasort($orders, function($a, $b) {
            $dateA = strtotime($a[0]->getPurchaseDate());
            $dateB = strtotime($b[0]->getPurchaseDate());
            return $dateA <=> $dateB;
        });

        // Usar la última fecha como referencia y actualizarla con cada factura creada
        $currentDate = $lastDate;

        foreach ($orders as $orderId => $rows) {
            // Pasar currentDate por referencia para que pueda ser actualizada
            $result = $this->processSingleOrder($orderId, $rows, $mode, $currentDate);
        }
    }

    protected function processSingleOrder(string $orderId, array $rows, string $mode, ?string &$lastDate): bool
    {
        $mainRow = $rows[0];
        $existingInvoice = $this->findExistingInvoice($orderId);

        if ($mode === 'add' && !empty($existingInvoice)) {
            $this->skippedCount++;
            return false;
        }
        if ($mode === 'update' && empty($existingInvoice)) {
            $this->skippedCount++;
            return false;
        }

        $isNew = empty($existingInvoice);
        $inv = $isNew ? new FacturaCliente() : $existingInvoice[0];
        
        if ($isNew) {
            $this->initInvoiceHeader($inv, $mainRow, $lastDate);
        } else {
            $this->clearInvoiceLines($inv);
        }

        if (!$inv->save()) {
            $this->skippedCount++;
            return false;
        }

        foreach ($rows as $row) {
            if (false === $this->createInvoiceLineFromRow($inv, $row)) {
                $this->skippedCount++;
                return false;
            }
        }
        if (false === $this->addExtraShippingCosts($inv, $rows)) {
            $this->skippedCount++;
            return false;
        }
        
        if ($this->finalizeInvoice($inv)) {
            $isNew ? $this->createdCount++ : $this->updatedCount++;
            return true;
        }

        $this->skippedCount++;
        return false;
    }

    protected function findExistingInvoice(string $orderId): array
    {
        $invoice = new FacturaCliente();
        $where = [new DataBaseWhere('codigo', $orderId)];
        return $invoice->all($where);
    }

    protected function initInvoiceHeader(FacturaCliente $inv, AmazonRow $row, ?string &$lastDate)
    {
        $orderId = $row->getOrderId();
        $inv->observaciones = "Amazon Order: " . $orderId;
        
        // Usar ID de Amazon como código de factura para poder identificar pedidos existentes
        $inv->codigo = $orderId;
        
        $purchaseDateRaw = $row->getPurchaseDate();
        
        // Convertir fecha de compra a formato completo con hora
        $purchaseDateTime = strtotime($purchaseDateRaw ?: 'now');
        
        // Determinar la fecha de la factura
        if (empty($lastDate)) {
            // Primera factura del lote: usar fecha del pedido al mediodía
            $inv->fecha = date('Y-m-d 12:00:00', $purchaseDateTime);
        } else {
            // Para mantener secuencia con FacturaScripts, siempre usar última fecha + 1 minuto
            // Esto asegura que las fechas sean secuenciales independientemente de las fechas del pedido
            $inv->fecha = date('Y-m-d H:i:s', strtotime($lastDate . ' +1 minute'));
            
            // Pero si la fecha del pedido es significativamente más reciente (más de 1 día),
            // podemos usar una fecha intermedia
            $purchaseDateOnly = date('Y-m-d', $purchaseDateTime);
            $lastDateOnly = date('Y-m-d', strtotime($lastDate));
            
            if ($purchaseDateOnly > $lastDateOnly) {
                // La fecha del pedido es al menos 1 día después de la última fecha
                // Usar fecha del pedido (pero asegurarse de que sea después de lastDate)
                $inv->fecha = date('Y-m-d 12:00:00', $purchaseDateTime);
            }
        }
        
        // Set exercise code from date (required for invoice numbering)
        $inv->codejercicio = date('Y', strtotime($inv->fecha));
        
        // Actualizar lastDate con la fecha usada para la siguiente factura
        $lastDate = $inv->fecha;

        $email = $row->getBuyerEmail();
        $name = $row->getBuyerName();
        $phone = $row->getBuyerPhone();
        $inv->codcliente = $this->findOrCreateCustomer($email, $name, $phone);
        
        // Update customer data to populate cifnif and nombrecliente fields
        if (!$inv->updateSubject()) {
            // Fallback: manually set required fields if updateSubject fails
            $cliente = new Cliente();
            if ($cliente->load($inv->codcliente)) {
                $inv->cifnif = $cliente->cifnif ?? 'VARIOUS';
                $inv->nombrecliente = $cliente->razonsocial ?? $cliente->nombre ?? $name;
            } else {
                $inv->cifnif = 'VARIOUS';
                $inv->nombrecliente = $name;
            }
        }
        
        // Generar número secuencial para la factura
        BusinessDocumentCode::setNewNumber($inv);
        
        // Sobrescribir código con ID de Amazon para poder identificar pedidos existentes
        $inv->codigo = $orderId;
    }

    protected function clearInvoiceLines(FacturaCliente $inv)
    {
        foreach ($inv->getLines() as $line) {
            $line->delete();
        }
    }

    protected function createInvoiceLineFromRow(FacturaCliente $inv, AmazonRow $row): bool
    {
        $sku = $row->getSku();
        $qty = $row->getQuantity();
        $pvpUnitario = $row->getUnitPriceNet();
        $ivaPercent = $row->getVatPercent();

        $product = $this->getOrCreateProductWithStock($sku, $row->getProductName(), $qty, $pvpUnitario, $ivaPercent);

        $line = $inv->getNewLine();
        $line->actualizastock = 0;
        $line->idproducto = null;
        $line->referencia = $sku ?: $product->referencia;
        $line->descripcion = $row->getProductName() ?: ($product->descripcion ?: $sku);
        $line->cantidad = $qty;
        $line->pvpunitario = $pvpUnitario;
        $line->iva = $ivaPercent;
        $line->codimpuesto = $this->getTaxCodeFromPercentage($ivaPercent);
        return $line->save();
    }

    protected function getOrCreateProductWithStock(string $sku, string $name, float $qty, float $netPrice, float $ivaPercent): Producto
    {
        $product = new Producto();
        if (empty($sku)) {
            return $product;
        }

        $variant = new Variante();
        if ($variant->loadWhereEq('referencia', $sku)) {
            $product->load($variant->idproducto);
            return $product;
        }

        if (!$product->loadWhereEq('referencia', $sku)) {
            $product->referencia = $sku;
            $product->descripcion = $name;
            $product->precio = $netPrice;
            $product->stockfis = $qty;
            $product->codimpuesto = $this->getTaxCodeFromPercentage($ivaPercent);
            $product->save();
        }
        return $product;
    }

    protected function addExtraShippingCosts(FacturaCliente $inv, array $rows): bool
    {
        $shipping = ['price' => 0, 'tax' => 0];
        $gift = ['price' => 0, 'tax' => 0];

        foreach ($rows as $row) {
            $shipping['price'] += $row->getShippingPrice();
            $shipping['tax'] += $row->getShippingTax();
            $gift['price'] += $row->getGiftWrapPrice();
            $gift['tax'] += $row->getGiftWrapTax();
        }

        if ($shipping['price'] > 0 && false === $this->createExtraLine($inv, self::REF_SHIPPING, 'Transporte', $shipping)) {
            return false;
        }

        if ($gift['price'] > 0 && false === $this->createExtraLine($inv, self::REF_GIFTWRAP, 'Envoltorio para regalo', $gift)) {
            return false;
        }

        return true;
    }

    protected function createExtraLine(FacturaCliente $inv, string $ref, string $desc, array $costData): bool
    {
        $price = $costData['price'];
        $tax = $costData['tax'];
        
        // Calcular precio neto
        $net = ($tax > 0) ? $price - $tax : $price / 1.21;
        
        // Calcular porcentaje de IVA
        $ivaPercent = ($tax > 0 && $net > 0) ? round(($tax / $net) * 100) : 21.0;
        
        $line = $inv->getNewLine();
        $line->actualizastock = 0;
        $line->referencia = $ref;
        $line->descripcion = $desc;
        $line->cantidad = 1;
        $line->pvpunitario = $net;
        $line->iva = $ivaPercent;
        $line->codimpuesto = $this->getTaxCodeFromPercentage($ivaPercent);
        return $line->save();
    }

    protected function finalizeInvoice(FacturaCliente $inv): bool
    {
        // Recalcular totales usando Calculator
        $lines = $inv->getLines();
        return \FacturaScripts\Core\Lib\Calculator::calculate($inv, $lines, true);
    }

    protected function findOrCreateCustomer($email, $name, $phone): string
    {
        $cliente = new Cliente();
        $where = [new DataBaseWhere('email', $email)];
        $existing = $cliente->all($where);
        
        if (!empty($existing)) {
            return $existing[0]->codcliente;
        }

        $cliente->nombre = $name;
        $cliente->razonsocial = $name; // Required for invoice nombrecliente field
        $cliente->email = $email;
        $cliente->telefono = $phone;
        $cliente->cifnif = 'VARIOUS';
        $cliente->save();
        return $cliente->codcliente;
    }

    protected function getTaxCodeFromPercentage(float $ivaPercent): string
    {
        $impuesto = new Impuesto();
        $where = [new DataBaseWhere('iva', $ivaPercent)];
        $taxes = $impuesto->all($where, ['codimpuesto' => 'ASC'], 0, 1);
        
        if (!empty($taxes)) {
            return $taxes[0]->codimpuesto;
        }
        
        // Default tax codes based on common IVA percentages
        $defaultTaxes = [
            21.0 => 'IVA21',
            10.0 => 'IVA10',
            4.0 => 'IVA4',
            0.0 => 'IVA0'
        ];
        
        // Find closest match
        $closestCode = 'IVA21'; // Default
        $closestDiff = PHP_FLOAT_MAX;
        
        foreach ($defaultTaxes as $percentage => $code) {
            $diff = abs($percentage - $ivaPercent);
            if ($diff < $closestDiff) {
                $closestDiff = $diff;
                $closestCode = $code;
            }
        }
        
        return $closestCode;
    }

    protected function reportResults()
    {
        if ($this->createdCount > 0) {
            Tools::log()->notice('🚀 ' . $this->createdCount . ' facturas creadas correctamente.');
        }
        if ($this->updatedCount > 0) {
            Tools::log()->notice('🔄 ' . $this->updatedCount . ' facturas actualizadas.');
        }
        if ($this->skippedCount > 0) {
            Tools::log()->warning('⏭️ ' . $this->skippedCount . ' facturas omitidas (ya existen o modo no compatible).');
        }

        if ($this->createdCount === 0 && $this->updatedCount === 0 && $this->skippedCount === 0) {
            Tools::log()->warning('🔍 No se han encontrado facturas en el informe.');
        }
    }
}
