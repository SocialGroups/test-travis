<?php
/**
 *
 * @category   Inovarti
 * @package    Inovarti_Pagarme
 * @author     Suporte <suporte@inovarti.com.br>
 *
 * UPDATED:
 *
 * @copyright   Copyright (C) 2015 Gamuza Technologies (http://www.gamuza.com.br/)
 * @author     Eneias Ramos de Melo <eneias@gamuza.com.br>
 */
class Inovarti_Pagarme_Block_Form_Cc extends Mage_Payment_Block_Form_Cc
{
	const MIN_INSTALLMENT_VALUE = 5;

    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('pagarme/form/cc.phtml');
    }

    public function getCcMonths()
    {
        $months = $this->getData('cc_months');
        if (is_null($months)) {
            $months[0] =  $this->__('Month');
            for ($i=1; $i <= 12; $i++) {
                $months[$i] = str_pad($i, 2, '0', STR_PAD_LEFT);
            }
            $this->setData('cc_months', $months);
        }
        return $months;
    }

    public function getInstallmentsAvailables(){
    	$maxInstallments = (int)Mage::getStoreConfig('payment/pagarme_cc/max_installments');
    	$minInstallmentValue = (float)Mage::getStoreConfig('payment/pagarme_cc/min_installment_value');
        $interestRate = (float)Mage::getStoreConfig('payment/pagarme_cc/interest_rate');
        $freeInstallments = (int)Mage::getStoreConfig('payment/pagarme_cc/free_installments');
    	if ($minInstallmentValue < self::MIN_INSTALLMENT_VALUE) {
    		$minInstallmentValue = self::MIN_INSTALLMENT_VALUE;
    	}

    	$quote = Mage::helper('checkout')->getQuote();
    	$total = Mage::helper('pagarme')->getBaseSubtotalWithDiscount () + Mage::helper ('pagarme')->getShippingAmount ();

    	$n = floor($total / $minInstallmentValue);
    	if ($n > $maxInstallments) {
    		$n = $maxInstallments;
    	} elseif ($n < 1) {
    		$n = 1;
    	}

        $data = new Varien_Object();
        $data->setAmount(Mage::helper('pagarme')->formatAmount($total))
            ->setInterestRate($interestRate)
            ->setMaxInstallments($n)
            ->setFreeInstallments($freeInstallments) // optional
            ;

        $response = Mage::getModel('pagarme/api')->calculateInstallmentsAmount($data);
        $collection = $response->getInstallments();

        $installments = array();
        foreach ($collection as $item) {
            if ($item->getInstallment() == 1) {
                $label = $this->__('Pay in full - %s', $quote->getStore()->formatPrice($item->getInstallmentAmount()/100, false));
            } else {
                $label = $this->__('%sx - %s', $item->getInstallment(), $quote->getStore()->formatPrice($item->getInstallmentAmount()/100, false)) . ' ';
                $label .= $item->getInstallment() > $freeInstallments ? $this->__('monthly interest rate (%s)', $interestRate.'%') : $this->__('interest-free');
            }
            $installments[$item->getInstallment()] = $label;
        }
    	return $installments;
    }
}
