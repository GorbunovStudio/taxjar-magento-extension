<?php
/**
 * Taxjar_SalesTax
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @category   Taxjar
 * @package    Taxjar_SalesTax
 * @copyright  Copyright (c) 2019 TaxJar. TaxJar is a trademark of TPS Unlimited, Inc. (http://www.taxjar.com)
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

class Taxjar_SalesTax_Model_Observer_SyncTransaction
{
    /**
     * @param Varien_Event_Observer $observer
     * @return $this
     */
    public function execute(Varien_Event_Observer $observer) {
        $syncEnabled = Mage::getStoreConfig('tax/taxjar/transactions');

        if (!$syncEnabled) {
            return $this;
        }

        if (Mage::registry('taxjar_sync')) {
            return $this;
        }

        if ($observer->getData('order_id')) {
            $order = Mage::getModel('sales/order')->load($observer->getData('order_id'));
        } else {
            $order = $observer->getEvent()->getOrder();
        }

        if (!$order->getState()) {
            return $this;
        }

        $transactionsSyncThreshold = Mage::getStoreConfig('tax/taxjar/transactions_threshold');
        $isSyncOnOrderUpdate = Mage::getStoreConfig('tax/taxjar/transactions_updates');

        if ($transactionsSyncThreshold && strtotime($order->getCreatedAt()) < strtotime($transactionsSyncThreshold)) {
            return $this;
        }

        if ($isSyncOnOrderUpdate && $order->getTjSalestaxSyncDate()) {
            return $this;
        }

        $orderTransaction = Mage::getModel('taxjar/transaction_order');

        if ($orderTransaction->isSyncable($order)) {
            Mage::register('taxjar_sync', true);

            try {
                $orderTransaction->build($order);
                $orderTransaction->push();

                /** @var Mage_Sales_Model_Entity_Order_Creditmemo_Collection $creditmemos */
                $creditmemos = Mage::getModel('sales/order_creditmemo')->getCollection();
                $creditmemos->setOrderFilter($order);

                foreach ($creditmemos as $creditmemo) {
                    $refundTransaction = Mage::getModel('taxjar/transaction_refund');
                    $refundTransaction->build($order, $creditmemo);
                    $refundTransaction->push();
                }

                if ($observer->getData('order_id')) {
                    Mage::getSingleton('adminhtml/session')->addSuccess(Mage::helper('taxjar')->__('Order successfully synced to TaxJar.'));
                }
            } catch(Exception $e) {
                Mage::getSingleton('adminhtml/session')->addError($e->getMessage());
            }
        } else {
            if ($observer->getData('order_id')) {
                Mage::getSingleton('adminhtml/session')->addError(Mage::helper('taxjar')->__('This order was not synced to TaxJar.'));
            }
        }

        return $this;
    }
}
