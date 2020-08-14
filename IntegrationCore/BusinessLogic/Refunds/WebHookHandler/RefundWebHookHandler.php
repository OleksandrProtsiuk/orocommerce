<?php

namespace Mollie\Bundle\PaymentBundle\IntegrationCore\BusinessLogic\Refunds\WebHookHandler;

use Mollie\Bundle\PaymentBundle\IntegrationCore\BusinessLogic\Http\DTO\BaseDto;
use Mollie\Bundle\PaymentBundle\IntegrationCore\BusinessLogic\Http\DTO\Refunds\Refund;

/**
 * Class RefundWebHookHandler
 *
 * @package Mollie\Bundle\PaymentBundle\IntegrationCore\BusinessLogic\Refunds\WebHookHandler
 */
abstract class RefundWebHookHandler
{
    /**
     * @param array $currentRefundsMap
     * @param Refund $newRefund
     *
     * @return bool
     */
    protected function isRefundStatusChanged(array $currentRefundsMap, Refund $newRefund)
    {
        return !array_key_exists($newRefund->getId(), $currentRefundsMap) ||
            $currentRefundsMap[$newRefund->getId()]->getStatus() !== $newRefund->getStatus();
    }

    /**
     * @param BaseDto[] $sourceArray
     *
     * @return array
     */
    protected function createMapFromSource(array $sourceArray)
    {
        $map = array();
        /** @var BaseDto $item */
        foreach ($sourceArray as $item) {
            $map[$item->getId()] = $item;
        }

        return $map;
    }
}
