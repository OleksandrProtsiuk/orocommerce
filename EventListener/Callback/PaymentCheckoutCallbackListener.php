<?php

namespace Mollie\Bundle\PaymentBundle\EventListener\Callback;

use Mollie\Bundle\PaymentBundle\IntegrationCore\BusinessLogic\Configuration;
use Mollie\Bundle\PaymentBundle\IntegrationCore\BusinessLogic\Notifications\NotificationHub;
use Mollie\Bundle\PaymentBundle\IntegrationCore\BusinessLogic\Notifications\NotificationText;
use Mollie\Bundle\PaymentBundle\IntegrationCore\BusinessLogic\WebHook\WebHookContext;
use Mollie\Bundle\PaymentBundle\IntegrationCore\BusinessLogic\WebHook\WebHookTransformer;
use Mollie\Bundle\PaymentBundle\IntegrationCore\Infrastructure\Logger\Logger;
use Mollie\Bundle\PaymentBundle\IntegrationCore\Infrastructure\ServiceRegister;
use Mollie\Bundle\PaymentBundle\PaymentMethod\MolliePayment;
use Mollie\Bundle\PaymentBundle\PaymentMethod\Provider\MolliePaymentProvider;
use Oro\Bundle\EntityBundle\ORM\DoctrineHelper;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\PaymentBundle\Entity\PaymentTransaction;
use Oro\Bundle\PaymentBundle\Event\AbstractCallbackEvent;
use Symfony\Component\HttpFoundation\RequestStack;

class PaymentCheckoutCallbackListener
{
    /**
     * @var RequestStack
     */
    private $request;
    /**
     * @var MolliePaymentProvider
     */
    protected $paymentMethodProvider;
    /**
     * @var DoctrineHelper
     */
    private $doctrineHelper;

    /**
     * @param MolliePaymentProvider $paymentMethodProvider
     */
    public function __construct(
        RequestStack $request,
        MolliePaymentProvider $paymentMethodProvider,
        DoctrineHelper $doctrineHelper
    ) {
        $this->request = $request;
        $this->paymentMethodProvider = $paymentMethodProvider;
        $this->doctrineHelper = $doctrineHelper;
    }

    public function onNotify(AbstractCallbackEvent $event)
    {
        WebHookContext::start();
        $this->handleEvent($event);
        WebHookContext::stop();
    }

    protected function handleEvent(AbstractCallbackEvent $event)
    {
        try {
            Logger::logDebug(
                'Web hook detected. Web hook event listener fired.',
                'Integration',
                [
                    'eventName' => $event->getEventName(),
                    'eventData' => $event->getData(),
                ]
            );

            $paymentTransaction = $event->getPaymentTransaction();
            if (!$paymentTransaction) {
                Logger::logWarning(
                    'Web hook without payment transaction detected.',
                    'Integration',
                    [
                        'eventName' => $event->getEventName(),
                        'eventData' => $event->getData(),
                    ]
                );
                return;
            }

            if (!$this->request->getMasterRequest()) {
                Logger::logWarning(
                    'Web hook without master HTTP request detected.',
                    'Integration',
                    [
                        'eventName' => $event->getEventName(),
                        'eventData' => $event->getData(),
                    ]
                );
                return;
            }

            $paymentMethodId = $paymentTransaction->getPaymentMethod();
            if (false === $this->paymentMethodProvider->hasPaymentMethod($paymentMethodId)) {
                Logger::logWarning(
                    'Web hook without payment method detected.',
                    'Integration',
                    [
                        'eventName' => $event->getEventName(),
                        'eventData' => $event->getData(),
                        'paymentMethodId' => $paymentMethodId,
                    ]
                );
                return;
            }

            /** @var Configuration $configuration */
            $configuration = ServiceRegister::getService(Configuration::CLASS_NAME);
            /** @var MolliePayment $paymentMethod */
            $paymentMethod = $this->paymentMethodProvider->getPaymentMethod($paymentMethodId);
            $webHookPayload = $this->request->getMasterRequest()->getContent();

            /** @var Order $order */
            $order = $this->doctrineHelper->getEntity(
                $paymentTransaction->getEntityClass(),
                $paymentTransaction->getEntityIdentifier()
            );

            if (!$order) {
                $this->handleMissingOrder($event, $paymentTransaction);
                return;
            }

            $configuration->doWithContext(
                (string)$paymentMethod->getConfig()->getChannelId(),
                function () use ($webHookPayload) {
                    /** @var WebHookTransformer $webHookTransformer */
                    $webHookTransformer = ServiceRegister::getService(WebHookTransformer::CLASS_NAME);
                    $webHookTransformer->handle($webHookPayload);
                }
            );

            if ($this->doctrineHelper->getEntityManager($order)) {
                $this->doctrineHelper->getEntityManager($order)->flush($order);
            }

            if ($this->doctrineHelper->getEntityManager($paymentTransaction)) {
                $this->doctrineHelper->getEntityManager($paymentTransaction)->flush($paymentTransaction);
            }

            $event->markSuccessful();
        } catch (\Exception $e) {
            $paymentTransaction = $event->getPaymentTransaction();
            $paymentTransaction->setSuccessful(false);
            Logger::logError(
                'Web hook processing failed.',
                'Integration',
                [
                    'ExceptionMessage' => $e->getMessage(),
                    'ExceptionTrace' => $e->getTraceAsString(),
                ]
            );
        }
    }

    private function handleMissingOrder(AbstractCallbackEvent $event, PaymentTransaction $paymentTransaction)
    {
        Logger::logWarning(
            'Web hook without order detected. Order does not exist in the system anymore.',
            'Integration',
            [
                'eventName' => $event->getEventName(),
                'eventData' => $event->getData(),
                'orderId' => $paymentTransaction->getEntityIdentifier(),
            ]
        );

        $event->stopPropagation();
        $event->markSuccessful();

        /** @var MolliePayment $paymentMethod */
        $paymentMethod = $this->paymentMethodProvider->getPaymentMethod($paymentTransaction->getPaymentMethod());

        /** @var Configuration $configuration */
        $configuration = ServiceRegister::getService(Configuration::CLASS_NAME);
        $configuration->doWithContext(
            (string)$paymentMethod->getConfig()->getChannelId(),
            function () use ($paymentTransaction) {
                NotificationHub::pushError(
                    new NotificationText('mollie.payment.webhook.notification.invalid_shop_order.title'),
                    new NotificationText('mollie.payment.webhook.notification.invalid_shop_order.description'),
                    $paymentTransaction->getEntityIdentifier()
                );
            }
        );
    }
}