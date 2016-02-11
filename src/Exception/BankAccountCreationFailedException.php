<?php

namespace HiPay\Wallet\Mirakl\Exception;

use Exception;
use HiPay\Wallet\Mirakl\Api\HiPay\Model\Soap\BankInfo;
use HiPay\Wallet\Mirakl\Vendor\Model\VendorInterface;

/**
 * Thrown when the bank account creation failed
 *
 * @author    Ivanis Kouamé <ivanis.kouame@smile.fr>
 * @copyright 2015 Smile
 */
class BankAccountCreationFailedException extends DispatchableException
{
    /** @var  VendorInterface */
    protected $vendor;

    /** @var  BankInfo */
    protected $bankInfo;

    /**
     * InvalidBankInfoException constructor.
     *
     * @param VendorInterface $vendor
     * @param BankInfo        $bankInfo
     * @param string          $message
     * @param int             $code
     * @param Exception       $previous
     */
    public function __construct(
        VendorInterface $vendor,
        BankInfo $bankInfo,
        $message = '',
        $code = 0,
        Exception $previous = null
    ) {
        $this->vendor = $vendor;
        $this->bankInfo = $bankInfo;
        parent::__construct(
            $message ?: "The creation of the bank account for hipay Wallet
            {$vendor->getHiPayId()} failed",
            $code,
            $previous
        );
    }

    /**
     * @return VendorInterface
     */
    public function getVendor()
    {
        return $this->vendor;
    }

    /**
     * @return BankInfo
     */
    public function getBankInfo()
    {
        return $this->bankInfo;
    }

    /**
     * Return the event name.
     *
     * @return string
     */
    public function getEventName()
    {
        return 'bankAccount.creation.failed';
    }
}
