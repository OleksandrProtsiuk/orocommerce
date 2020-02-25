<?php

namespace Mollie\Bundle\PaymentBundle\IntegrationCore\BusinessLogic\Http;

use Mollie\Bundle\PaymentBundle\IntegrationCore\BusinessLogic\Http\DTO\Orders\Order;
use Mollie\Bundle\PaymentBundle\IntegrationCore\BusinessLogic\Http\DTO\Orders\OrderLine;
use Mollie\Bundle\PaymentBundle\IntegrationCore\BusinessLogic\Http\DTO\Orders\Shipment;
use Mollie\Bundle\PaymentBundle\IntegrationCore\BusinessLogic\Http\DTO\Payment;
use Mollie\Bundle\PaymentBundle\IntegrationCore\BusinessLogic\Http\DTO\Refunds\Refund;
use Mollie\Bundle\PaymentBundle\IntegrationCore\BusinessLogic\PaymentMethod\PaymentMethods;

/**
 * Class ProxyTransformer
 *
 * @package Mollie\Bundle\PaymentBundle\IntegrationCore\BusinessLogic\Http
 */
class ProxyTransformer
{
    const CLASS_NAME = __CLASS__;

    public function transformPayment(Payment $payment)
    {
        $result = array(
            'profileId' => $payment->getProfileId(),
            'description' => $payment->getDescription(),
            'amount' => $payment->getAmount()->toArray(),
            'redirectUrl' => $payment->getRedirectUrl(),
            'webhookUrl' => $payment->getWebhookUrl(),
            'locale' => $payment->getLocale(),
            'method' => $payment->getMethod(),
            'metadata' => $payment->getMetadata(),
        );

        $shippingAddress = $payment->getShippingAddress();
        if ($shippingAddress && $payment->getMethod() === PaymentMethods::PayPal) {
            $result['shippingAddress'] = array(
                'streetAndNumber' => $shippingAddress->getStreetAndNumber(),
                'streetAdditional' => $shippingAddress->getStreetAdditional(),
                'city' => $shippingAddress->getCity(),
                'region' => $shippingAddress->getRegion(),
                'postalCode' => $shippingAddress->getPostalCode(),
                'country' => $shippingAddress->getCountry(),
            );
        }

        return $result;
    }

    public function transformOrder(Order $order)
    {
        $orderLines = $order->getLines();
        $totalAdjustment = $this->getOrderAdjustment($order);
        if ($totalAdjustment) {
            $orderLines[] = $totalAdjustment;
        }

        $orderData = array(
            'profileId' => $order->getProfileId(),
            'amount' => $order->getAmount()->toArray(),
            'orderNumber' => $order->getOrderNumber(),
            'billingAddress' => $order->getBillingAddress()->toArray(),
            'redirectUrl' => $order->getRedirectUrl(),
            'webhookUrl' => $order->getWebhookUrl(),
            'payment' => array(
                'webhookUrl' => $order->getWebhookUrl(),
            ),
            'locale' => $order->getLocale(),
            'method' => $order->getMethod(),
            'metadata' => $order->getMetadata(),
            'lines' => $this->transformOrderLines($orderLines),
        );

        if ($shippingAddress = $order->getShippingAddress()) {
            $orderData['shippingAddress'] = $shippingAddress->toArray();
        }

        if ($consumerDateOfBirth = $order->getConsumerDateOfBirth()) {
            $orderData['consumerDateOfBirth'] = $consumerDateOfBirth->format(Order::MOLLIE_DATE_FORMAT);
        }

        return $orderData;
    }

    /**
     *
     * @param Order $order
     *
     * @return array
     */
    public function transformOrderForUpdate(Order $order)
    {
        $result = array();
        if ($order->getBillingAddress() !== null) {
            $result['billingAddress'] = $order->getBillingAddress()->toArray();
        }

        if ($order->getShippingAddress() !== null) {
            $result['shippingAddress'] = $order->getShippingAddress()->toArray();
        }

        return $result;
    }

    /**
     * @param OrderLine[] $orderLines
     *
     * @return array Order lines data as an array
     */
    public function transformOrderLines(array $orderLines)
    {
        $result = array();
        foreach ($orderLines as $orderLine) {
            $orderLineData = array(
                'name' => $orderLine->getName(),
                'quantity' => $orderLine->getQuantity(),
                'unitPrice' => $orderLine->getUnitPrice()->toArray(),
                'totalAmount' => $orderLine->getTotalAmount()->toArray(),
                'vatRate' => $orderLine->getVatRate(),
                'vatAmount' => $orderLine->getVatAmount()->toArray(),
                'sku' => $orderLine->getSku(),
                'metadata' => $orderLine->getMetadata(),
            );

            $type = $orderLine->getType();
            if (!empty($type)) {
                $orderLineData['type'] = $type;
            }

            if ($discountAmount = $orderLine->getDiscountAmount()) {
                $orderLineData['discountAmount'] = $discountAmount->toArray();
            }

            $result[] = $orderLineData;
        }

        return $result;
    }

    /**
     * Transform order lines for cancellation
     *
     * @param OrderLine $orderLine
     *
     * @return array
     */
    public function transformOrderLinesForUpdate(OrderLine $orderLine)
    {
        $result = array();
        if ($orderLine->getName() !== null) {
            $result['name'] = $orderLine->getName();
        }

        if ($orderLine->getQuantity() !== null) {
            $result['quantity'] = $orderLine->getQuantity();
        }

        if ($orderLine->getUnitPrice() !== null) {
            $result['unitPrice'] = $orderLine->getUnitPrice()->toArray();
        }

        if ($orderLine->getDiscountAmount() !== null) {
            $result['discountAmount'] = $orderLine->getDiscountAmount()->toArray();
        }

        if ($orderLine->getTotalAmount() !== null) {
            $result['totalAmount'] = $orderLine->getTotalAmount()->toArray();
        }

        if ($orderLine->getVatAmount() !== null) {
            $result['vatAmount'] = $orderLine->getVatAmount()->toArray();
        }

        if ($orderLine->getVatRate() !== null) {
            $result['vatRate'] = $orderLine->getVatRate();
        }

        return $result;
    }

    /**
     * Transforms Refund DTO to payload body for create refund on the payments API
     *
     * @param Refund $refund DTO
     *
     * @return array
     */
    public function transformPaymentRefund(Refund $refund)
    {
        return array(
            'amount' => $refund->getAmount()->toArray(),
            'description' => $refund->getDescription(),
            'metadata' => $refund->getMetadata(),
        );
    }

    /**
     * Transforms Refund DTO to payload body for create refund on the orders API
     *
     * @param Refund $refund
     *
     * @return array
     */
    public function transformOrderLinesRefund(Refund $refund)
    {
        $refundLines = array();
        foreach ($refund->getLines() as $orderLine) {
            $quantity = $orderLine->getQuantity();
            if ($quantity < 1) {
                continue;
            }

            $refundLine = array();
            $refundLine['id'] = $orderLine->getId();
            $refundLine['quantity'] = $quantity;

            $refundLines[] = $refundLine;
        }

        return array(
            'lines' => $refundLines,
            'metadata' => $refund->getMetadata(),
        );
    }

    /**
     * Calculates additional discount or surcharge based on total amounts set on order lines and total amount set o order
     *
     * @param Order $order
     *
     * @return OrderLine|null
     */
    protected function getOrderAdjustment(Order $order)
    {
        $lineItemsTotal = array_reduce($order->getLines(), function ($carry, OrderLine $line) {
            $carry += (float)$line->getTotalAmount()->getAmountValue();
            return $carry;
        }, 0);

        $orderTotal = (float)$order->getAmount()->getAmountValue();
        $totalDiff = $orderTotal - $lineItemsTotal;
        if (abs($totalDiff) >= 0.001) {
            return OrderLine::fromArray(array(
                'name' => 'Adjustment',
                'type' => ($totalDiff > 0) ? 'surcharge' : 'discount',
                'quantity' => 1,
                'unitPrice' => array(
                    'value' => (string)$totalDiff,
                    'currency' => $order->getAmount()->getCurrency()
                ),
                'totalAmount' => array(
                    'value' => (string)$totalDiff,
                    'currency' => $order->getAmount()->getCurrency()
                ),
                'vatRate' => '0.00',
                'vatAmount' => array(
                    'value' => '0.00',
                    'currency' => $order->getAmount()->getCurrency()
                ),
            ));
        }

        return null;
    }

    public function transformShipment(Shipment $shipment)
    {
        $lines = array();
        foreach ($shipment->getLines() as $line) {
            $lineData = array('id' => $line->getId());
            if ($line->getQuantity() > 0) {
                $lineData['quantity'] = $line->getQuantity();
            }

            $lines[] = $lineData;
        }

        $result = array('lines' => $lines);
        if ($shipment->getTracking()) {
            $result['tracking'] = $shipment->getTracking()->toArray();
        }

        return $result;
    }
}
