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
class Inovarti_Pagarme_Model_Cc extends Mage_Payment_Model_Method_Abstract
{
	const REQUEST_TYPE_AUTH_CAPTURE = 'AUTH_CAPTURE';
    const REQUEST_TYPE_AUTH_ONLY    = 'AUTH_ONLY';
    const REQUEST_TYPE_CAPTURE_ONLY = 'CAPTURE_ONLY';

    protected $_code = 'pagarme_cc';

    protected $_formBlockType = 'pagarme/form_cc';
    protected $_infoBlockType = 'pagarme/info_cc';

	protected $_isGateway                   = true;
	protected $_canAuthorize                = true;
	protected $_canCapture                  = true;
	protected $_canRefund                   = true;
	protected $_canUseForMultishipping 		= false;
	protected $_canManageRecurringProfiles  = false;

	public function assignData($data)
    {
        if (!($data instanceof Varien_Object)) {
            $data = new Varien_Object($data);
        }
        $info = $this->getInfoInstance();
        $info->setInstallments($data->getInstallments())
            ->setInstallmentDescription($data->getInstallmentDescription())
            ->setPagarmeCardHash($data->getPagarmeCardHash())
            ;
        return $this;
    }

	public function authorize(Varien_Object $payment, $amount)
    {
    	$this->_place($payment, $payment->getBaseAmountOrdered (), self::REQUEST_TYPE_AUTH_ONLY);
        return $this;
    }

	public function capture(Varien_Object $payment, $amount)
	{
		if ($payment->getPagarmeTransactionId()) {
			$this->_place($payment, $payment->getBaseAmountAuthorized (), self::REQUEST_TYPE_CAPTURE_ONLY);
		} else {
			$this->_place($payment, $payment->getBaseAmountAuthorized (), self::REQUEST_TYPE_AUTH_CAPTURE);
		}
        return $this;
	}

	public function refund(Varien_Object $payment, $amount)
	{
		$pagarme = Mage::getModel('pagarme/api');

		$transaction = $pagarme->refund($payment->getPagarmeTransactionId());
		if ($transaction->getErrors()) {
			$messages = array();
			foreach ($transaction->getErrors() as $error) {
				$messages[] = $error->getMessage() . '.';
			}
			Mage::throwException(implode("\n", $messages));
		}

		$payment->setTransactionId($payment->getPagarmeTransactionId() . '-' . Mage_Sales_Model_Order_Payment_Transaction::TYPE_REFUND)
            ->setParentTransactionId($payment->getParentTransactionId())
			->setIsTransactionClosed(1)
            ->setShouldCloseParentTransaction(1)
			->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS, array('status' => $transaction->getStatus()));

		return $this;
	}

	protected function _place($payment, $amount, $requestType)
    {
    	$pagarme = Mage::getModel('pagarme/api');

		switch ($requestType) {
			case self::REQUEST_TYPE_AUTH_ONLY:
			case self::REQUEST_TYPE_AUTH_CAPTURE:
                $customer = Mage::helper('pagarme')->getCustomerInfoFromOrder($payment->getOrder());
				$data = new Varien_Object();
				$data->setPaymentMethod(Inovarti_Pagarme_Model_Api::PAYMENT_METHOD_CREDITCARD)
					->setAmount(Mage::helper('pagarme')->formatAmount($amount))
                    ->setCardHash($payment->getPagarmeCardHash())
					->setInstallments($payment->getInstallments())
					->setCapture($requestType == self::REQUEST_TYPE_AUTH_CAPTURE)
					->setCustomer($customer);

				$transaction = $pagarme->charge($data);
				break;
			case self::REQUEST_TYPE_CAPTURE_ONLY:
				$transaction = $pagarme->capture($payment->getPagarmeTransactionId());
				break;
		}

		if ($transaction->getErrors()) {
			$messages = array();
			foreach ($transaction->getErrors() as $error) {
				$messages[] = $error->getMessage() . '.';
			}
			Mage::throwException(implode("\n", $messages));
		}

        if ($transaction->getStatus() == 'refused') {
            Mage::throwException($this->_wrapGatewayError($transaction->getStatusReason()));
        }

		if ($payment->getPagarmeTransactionId()) {
            $payment->setTransactionId($payment->getPagarmeTransactionId() . '-' . Mage_Sales_Model_Order_Payment_Transaction::TYPE_CAPTURE)
                ->setParentTransactionId($payment->getParentTransactionId())
                ->setIsTransactionClosed(0);
        } else {
			$payment->setCcOwner($transaction->getCardHolderName())
                ->setCcLast4($transaction->getCardLastDigits())
                ->setCcType(Mage::getSingleton('pagarme/source_cctype')->getTypeByBrand($transaction->getCardBrand()))
                ->setPagarmeTransactionId($transaction->getId())
				->setPagarmeAntifraudScore($transaction->getAntifraudScore())
                ->setTransactionId($transaction->getId())
                ->setIsTransactionClosed(0);
		}

		$payment->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,array('status' => $transaction->getStatus()));

		return $this;
    }

    protected function _formatCardDate($year, $month)
    {
    	$formated = sprintf('%02d', $month) . substr($year, -2);
    	return $formated;
    }

    protected function _wrapGatewayError($code)
    {
        switch ($code)
        {
        case 'acquirer': { $result = 'Transaction refused by the card company.'; break; }
        case 'antifraud': { $result = 'Transação recusada pelo antifraude.'; break; }
        case 'internal_error': { $result = 'Ocorreu um erro interno ao processar a transação.'; break; }
        case 'no_acquirer': { $result = 'Sem adquirente configurado para realizar essa transação.'; break; }
        case 'acquirer_timeout': { $result = 'Transação não processada pela operadora de cartão.'; break; }
        }

        return Mage::helper('pagarme')->__('Transaction failed, please try again or contact the card issuing bank.') . PHP_EOL
               . Mage::helper('pagarme')->__($result);
    }
}
