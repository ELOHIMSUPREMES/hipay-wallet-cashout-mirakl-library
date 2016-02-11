<?php

namespace HiPay\Wallet\Mirakl\Api\HiPay\Model\Status;

use HiPay\Wallet\Mirakl\Common\AbstractEnumeration;

/**
 * Constants for the type of the transfer
 *
 * @author    Ivanis Kouamé <ivanis.kouame@smile.fr>
 * @copyright 2015 Smile
 */
class TransferType extends AbstractEnumeration
{
    // HiPay Wallet transfer transaction types.
    const TRANSFER = 'Envoi';
    const OTHER = ' Autre';
}
