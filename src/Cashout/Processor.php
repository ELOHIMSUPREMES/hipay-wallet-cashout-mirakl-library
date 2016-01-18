<?php
namespace Hipay\MiraklConnector\Cashout;

use Exception;
use Hipay\MiraklConnector\Api\Hipay\Model\Soap\Transfer;
use Hipay\MiraklConnector\Cashout\Model\Operation\OperationInterface;
use Hipay\MiraklConnector\Cashout\Model\Operation\Status;
use Hipay\MiraklConnector\Common\AbstractProcessor;
use Hipay\MiraklConnector\Api\Mirakl;
use Hipay\MiraklConnector\Api\Mirakl\ConfigurationInterface
    as MiraklConfiguration;
use Hipay\MiraklConnector\Api\Hipay;
use Hipay\MiraklConnector\Api\Hipay\ConfigurationInterface
    as HipayConfiguration;
use Hipay\MiraklConnector\Exception\DispatchableException;
use Hipay\MiraklConnector\Exception\Event\ThrowException;
use Hipay\MiraklConnector\Exception\NoEnoughFundsAvailableException;
use Hipay\MiraklConnector\Exception\NoWalletFoundException;
use Hipay\MiraklConnector\Exception\UnconfirmedBankAccountException;
use Hipay\MiraklConnector\Exception\UnidentifiedWalletException;
use Hipay\MiraklConnector\Vendor\VendorManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Hipay\MiraklConnector\Cashout\Model\Operation\ManagerInterface;
use Hipay\MiraklConnector\Api\Hipay\Constant\BankInfo as BankInfoStatus;
/**
 * File Processor.php
 *
 * @author    Ivanis Kouamé <ivanis.kouame@smile.fr>
 * @copyright 2015 Smile
 */
class Processor extends AbstractProcessor
{
    /** @var  ManagerInterface */
    protected $managerInterface;

    /** @var  VendorManager */
    protected $vendorManager;

    /**
     * Processor constructor.
     * @param MiraklConfiguration $miraklConfig
     * @param HipayConfiguration $hipayConfig
     * @param EventDispatcherInterface $dispatcher
     * @param LoggerInterface $logger
     * @param ManagerInterface $handler
     */
    public function __construct(
        MiraklConfiguration $miraklConfig,
        HipayConfiguration $hipayConfig,
        EventDispatcherInterface $dispatcher,
        LoggerInterface $logger,
        ManagerInterface $handler
    )
    {
        parent::__construct($miraklConfig, $hipayConfig, $dispatcher, $logger);
        $this->managerInterface = $handler;
    }

    /**
     * Main processing function
     *
     * @param $publicLabel
     * @param $privateLabel
     * @param $withdrawLabel
     * @throws NoEnoughFundsAvailableException
     * @throws NoWalletFoundException
     * @throws UnconfirmedBankAccountException
     * @throws UnidentifiedWalletException
     */
    public function process($publicLabel, $privateLabel, $withdrawLabel)
    {
        $nextDay = new \DateTime("+1 day");

        //Transfer
        $toTransfer = $this->managerInterface->find(
            new Status(Status::CREATED)
        );
        $toTransfer = $toTransfer + $this->managerInterface->find(
            new Status(Status::TRANSFER_FAILED), $nextDay
        );

        $transferSuccess = new Status(Status::TRANSFER_SUCCESS);
        $transferFailed = new Status(Status::TRANSFER_FAILED);

        foreach ($toTransfer as $operation) {
            try {
                $operation->setStatus(new Status(Status::TRANSFER_START));
                $this->managerInterface->save($operation);
                $this->transfer($operation, $publicLabel, $privateLabel);
                $operation->setStatus($transferSuccess);
            } catch (DispatchableException $e) {
                $operation->setStatus($transferFailed);
                $this->logger->warning(
                    $e->getMessage()
                );
                $this->dispatcher->dispatch(
                    $e->getEventName(), new ThrowException($e)
                );
            } catch (Exception $e) {
                $operation->setStatus($transferFailed);
                $this->logger->critical(
                    $e->getMessage()
                );
            }
        }

        $this->managerInterface->saveAll($toTransfer);


        //Withdraw
        $toWithdraw = $this->managerInterface->find(
            new Status(Status::TRANSFER_SUCCESS)
        );
        $toWithdraw = $toWithdraw + $this->managerInterface->find(
            new Status(Status::WITHDRAW_FAILED), $nextDay
        );

        $withdrawRequested = new Status(Status::WITHDRAW_REQUESTED);
        $withdrawFailed = new Status(Status::WITHDRAW_FAILED);

        foreach ($toWithdraw as $operation) {
            try {
                $operation->setStatus(new Status(Status::WITHDRAW_START));
                $this->managerInterface->save($operation);
                $this->withdraw($operation, $withdrawLabel);
                $operation->setStatus($withdrawRequested);
            } catch (DispatchableException $e) {
                $operation->setStatus($withdrawFailed);
                $this->logger->warning(
                    $e->getMessage()
                );
                $this->dispatcher->dispatch(
                    $e->getEventName(), new ThrowException($e)
                );
            } catch (Exception $e) {
                $this->logger->critical(
                    $e->getMessage()
                );
            }
        }

        $this->managerInterface->saveAll($toWithdraw);

    }

    /**
     * Transfer money between the technical
     * wallet and the operator|seller wallet
     *
     * @param OperationInterface $operation
     * @param string $publicLabel
     * @param string $privateLabel
     * @return array
     * @throws NoWalletFoundException
     */
    public function transfer(
        OperationInterface $operation,
        $publicLabel,
        $privateLabel
    )
    {
        $vendor = $this->vendorManager->findByHipayId($operation->getHipayId());

        if (!$vendor || $this->hipay->isAvailable($vendor->getEmail())) {
            throw new NoWalletFoundException($vendor);
        }

        $transfer = new Transfer(
            $operation->getAmount(),
            $vendor,
            $publicLabel,
            $privateLabel
        );
        return $this->hipay->direct($transfer);
    }

    /**
     * Put the money into the real bank account of the operator|seller
     *
     * @param OperationInterface $operation
     * @param $label
     * @return array
     * @throws NoEnoughFundsAvailableException
     * @throws UnconfirmedBankAccountException
     * @throws UnidentifiedWalletException
     */
    public function withdraw(OperationInterface $operation, $label)
    {
        if (!$this->hipay->isIdentified($operation->getHipayId())) {
            throw new UnidentifiedWalletException($operation->getHipayId());
        }

        $vendor = $this->vendorManager->findByHipayId($operation->getHipayId());
        $bankInfoStatus = $this->hipay->bankInfosStatus($vendor);
        if ($this->hipay->bankInfosStatus($vendor)
            != BankInfoStatus::VALIDATED
        ) {
            throw new UnconfirmedBankAccountException(
                $vendor,
                new BankInfoStatus($bankInfoStatus)
            );
        }

        //Check account balance
        $amount = $operation->getAmount();
        $balance = $this->hipay->getBalance($vendor);
        if ($balance < $amount) {
            if (!$operation->getMiraklId()) {
                $amount = $balance;
            } else {
                throw new NoEnoughFundsAvailableException(
                    $vendor,
                    $amount,
                    $balance
                );
            }
        }

        //Withdraw
        return $this->hipay->withdraw($vendor, $amount, $label);
    }
}