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

class Taxjar_SalesTax_Model_Observer_BackfillTransactions
{
    protected $logger;

    public function __construct()
    {
        $this->logger = Mage::getSingleton('taxjar/logger')->setFilename('transactions.log')->force();
    }

    /**
     * @param  Varien_Event_Observer $observer
     * @return $this
     */
    public function execute(Varien_Event_Observer $observer)
    {
        $apiKey = trim(Mage::getStoreConfig('tax/taxjar/apikey'));

        if (!$apiKey) {
            $this->logger->log('Error: ' . Mage::helper('taxjar')->__('Could not sync transactions with TaxJar. Please make sure you have an API key.'), 'error');
            return;
        }

        $statesToMatch = array('complete', 'closed', 'processing', 'holded');
        $fromDate = Mage::app()->getRequest()->getParam('from_date');
        $toDate = Mage::app()->getRequest()->getParam('to_date');

        $fromDate = $observer->getFromDate() ? $observer->getFromDate() : $fromDate;
        $toDate = $observer->getToDate() ? $observer->getToDate() : $toDate;

        $this->logger->log('Initializing TaxJar transaction sync');

        if (!empty($fromDate)) {
            $fromDate = (new DateTime($fromDate));
        } else {
            $fromDate = (new DateTime());
            $fromDate = $fromDate->sub(new DateInterval('P1D'));
        }

        if (!empty($toDate)) {
            $toDate = (new DateTime($toDate));
        } else {
            $toDate = (new DateTime());
        }

        if ($fromDate > $toDate) {
            $this->logger->log('Error: ' . Mage::helper('taxjar')->__("To date can't be earlier than from date."), 'error');
            return;
        }

        $this->logger->log('Finding ' . implode(', ', $statesToMatch) . ' transactions from ' . $fromDate->format('m/d/Y') . ' - ' . $toDate->format('m/d/Y'));

        $fromDate->setTime(0, 0, 0);
        $toDate->setTime(23, 59, 59);

        $orders = Mage::getModel('sales/order')->getCollection()
            ->addAttributeToFilter('created_at', array('from' => $fromDate->format('Y-m-d H:i:s'), 'to' => $toDate->format('Y-m-d H:i:s')))
            ->addAttributeToFilter('state', array('in' => $statesToMatch))
            ->load();

        $this->logger->log(count($orders) . ' transaction(s) found');

        // This process can take awhile
        @set_time_limit(0);
        @ignore_user_abort(true);

        foreach ($orders as $order) {
            $orderTransaction = Mage::getModel('taxjar/transaction_order');

            if ($orderTransaction->isSyncable($order)) {
                $orderTransaction->build($order);
                $orderTransaction->push();

                /** @var Mage_Sales_Model_Entity_Order_Creditmemo_Collection $creditMemos */
                $creditMemos = Mage::getModel('sales/order_creditmemo')->getCollection();
                $creditMemos->setOrderFilter($order);

                foreach ($creditMemos as $creditMemo) {
                    $refundTransaction = Mage::getModel('taxjar/transaction_refund');
                    $refundTransaction->build($order, $creditMemo);
                    $refundTransaction->push();
                }
            }
        }

        return $this;
    }
}
