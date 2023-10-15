<?php
/**
 * @license MIT
 */

namespace Mews\Pos\DataMapper\RequestDataMapper;

use DateTimeInterface;
use Mews\Pos\Entity\Account\AbstractPosAccount;
use Mews\Pos\Entity\Account\PayFlexAccount;
use Mews\Pos\Entity\Card\AbstractCreditCard;
use Mews\Pos\Exceptions\NotImplementedException;
use Mews\Pos\PosInterface;

/**
 * Creates request data for PayFlex V4 Gateway requests
 */
class PayFlexV4PosRequestDataMapper extends AbstractRequestDataMapper
{
    /** @var string */
    public const CREDIT_CARD_EXP_DATE_LONG_FORMAT = 'Ym';

    /** @var string */
    public const CREDIT_CARD_EXP_DATE_FORMAT = 'ym';

    /**
     * {@inheritDoc}
     */
    protected $txTypeMappings = [
        PosInterface::TX_PAY      => 'Sale',
        PosInterface::TX_PRE_PAY  => 'Auth',
        PosInterface::TX_POST_PAY => 'Capture',
        PosInterface::TX_CANCEL   => 'Cancel',
        PosInterface::TX_REFUND   => 'Refund',
        PosInterface::TX_STATUS   => 'status',
    ];

    /**
     * {@inheritdoc}
     */
    protected $cardTypeMapping = [
        AbstractCreditCard::CARD_TYPE_VISA       => '100',
        AbstractCreditCard::CARD_TYPE_MASTERCARD => '200',
        AbstractCreditCard::CARD_TYPE_TROY       => '300',
        AbstractCreditCard::CARD_TYPE_AMEX       => '400',
    ];

    /**
     * {@inheritdoc}
     */
    protected $recurringOrderFrequencyMapping = [
        'DAY'   => 'Day',
        'MONTH' => 'Month',
        'YEAR'  => 'Year',
    ];

    /**
     * @param PayFlexAccount                                                      $account
     * @param array{Eci: string, Cavv: string, VerifyEnrollmentRequestId: string} $responseData
     *
     * {@inheritDoc}
     */
    public function create3DPaymentRequestData(AbstractPosAccount $account, array $order, string $txType, array $responseData, ?AbstractCreditCard $card = null): array
    {
        if (null === $card) {
            throw new \LogicException('Ödemeyi tamamlamak için kart bilgiler zorunlu!');
        }
        $order = $this->preparePaymentOrder($order);

        $requestData = $this->getRequestAccountData($account) + [
                'TransactionType'         => $this->mapTxType($txType),
                'TransactionId'           => (string) $order['id'],
                'CurrencyAmount'          => $this->amountFormat($order['amount']),
                'CurrencyCode'            => $this->mapCurrency($order['currency']),
                'ECI'                     => $responseData['Eci'],
                'CAVV'                    => $responseData['Cavv'],
                'MpiTransactionId'        => $responseData['VerifyEnrollmentRequestId'],
                'OrderId'                 => (string) $order['id'],
                'ClientIp'                => (string) $order['ip'],
                'TransactionDeviceSource' => '0', // ECommerce
                'CardHoldersName'         => $card->getHolderName(),
                'Cvv'                     => $card->getCvv(),
                'Pan'                     => $card->getNumber(),
                'Expiry'                  => $card->getExpirationDate(self::CREDIT_CARD_EXP_DATE_LONG_FORMAT),
            ];

        if ($order['installment']) {
            $requestData['NumberOfInstallments'] = $this->mapInstallment($order['installment']);
        }

        return $requestData;
    }

    /**
     * @param PayFlexAccount                       $account
     * @param array<string, int|string|float|null> $order
     * @param AbstractCreditCard                   $card
     *
     * @return array
     */
    public function create3DEnrollmentCheckRequestData(AbstractPosAccount $account, array $order, AbstractCreditCard $card): array
    {
        $order = $this->preparePaymentOrder($order);

        $requestData = [
            'MerchantId'                => $account->getClientId(),
            'MerchantPassword'          => $account->getPassword(),
            'MerchantType'              => $account->getMerchantType(),
            'PurchaseAmount'            => $this->amountFormat($order['amount']),
            'VerifyEnrollmentRequestId' => $order['rand'],
            'Currency'                  => $this->mapCurrency($order['currency']),
            'SuccessUrl'                => $order['success_url'],
            'FailureUrl'                => $order['fail_url'],
            'Pan'                       => $card->getNumber(),
            'ExpiryDate'                => $card->getExpirationDate(self::CREDIT_CARD_EXP_DATE_FORMAT),
            'BrandName'                 => $this->cardTypeMapping[$card->getType()],
            'IsRecurring'               => 'false',
        ];
        if ($order['installment']) {
            $requestData['InstallmentCount'] = $this->mapInstallment($order['installment']);
        }

        if ($account->isSubBranch()) {
            $requestData['SubMerchantId'] = $account->getSubMerchantId();
        }

        if (isset($order['recurringFrequency'])) {
            $requestData = array_merge($requestData, $this->createRecurringData($order));
        }

        return $requestData;
    }

    /**
     * @param PayFlexAccount $account
     * {@inheritDoc}
     */
    public function createNonSecurePaymentRequestData(AbstractPosAccount $account, array $order, string $txType, AbstractCreditCard $card): array
    {
        $order = $this->preparePaymentOrder($order);

        return $this->getRequestAccountData($account) + [
                'TransactionType'         => $this->mapTxType($txType),
                'OrderId'                 => (string) $order['id'],
                'CurrencyAmount'          => $this->amountFormat($order['amount']),
                'CurrencyCode'            => $this->mapCurrency($order['currency']),
                'ClientIp'                => (string) $order['ip'],
                'TransactionDeviceSource' => '0',
                'Pan'                     => $card->getNumber(),
                'Expiry'                  => $card->getExpirationDate(self::CREDIT_CARD_EXP_DATE_LONG_FORMAT),
                'Cvv'                     => $card->getCvv(),
            ];
    }

    /**
     * @param PayFlexAccount       $account
     * @param array<string, mixed> $order
     *
     * @return array{TransactionType: string,
     *     ReferenceTransactionId: string,
     *     CurrencyAmount: string,
     *     CurrencyCode: string,
     *     ClientIp: string,
     *     MerchantId: string,
     *     Password: string,
     *     TerminalNo: string}
     */
    public function createNonSecurePostAuthPaymentRequestData(AbstractPosAccount $account, array $order): array
    {
        $order = $this->preparePostPaymentOrder($order);

        return $this->getRequestAccountData($account) + [
                'TransactionType'        => $this->mapTxType(PosInterface::TX_POST_PAY),
                'ReferenceTransactionId' => (string) $order['id'],
                'CurrencyAmount'         => $this->amountFormat($order['amount']),
                'CurrencyCode'           => $this->mapCurrency($order['currency']),
                'ClientIp'               => (string) $order['ip'],
            ];
    }

    /**
     * {@inheritDoc}
     * @return array{MerchantCriteria: array{HostMerchantId: string, MerchantPassword: string}, TransactionCriteria: array{TransactionId: string, OrderId: string, AuthCode: string}}
     */
    public function createStatusRequestData(AbstractPosAccount $account, array $order): array
    {
        $order = $this->prepareStatusOrder($order);

        return [
            'MerchantCriteria'    => [
                'HostMerchantId'   => $account->getClientId(),
                'MerchantPassword' => $account->getPassword(),
            ],
            'TransactionCriteria' => [
                /**
                 * TransactionId alanına sorgulanmak istenen işlemin TransactionId bilgisi yazılmalıdır.
                 * TransactionId ya da OrderId alanlarının biri zorunludur.
                 * Hem TransactionId hem de OrderId gönderilerek yapılan bir sorgulamada,
                 * TransactionId dikkate alınmaktadır.
                 * OrderID ile sorgulamada bu OrderId ile başarılı işlem varsa başarılı işlem, yoksa son gönderilen işlem raporda görüntülenecektir
                 */
                'TransactionId' => '',
                'OrderId'       => (string) $order['id'],
                'AuthCode'      => '',
            ],
        ];
    }

    /**
     * {@inheritDoc}
     * @return array{MerchantId: string, Password: string, TransactionType: string,
     *     ReferenceTransactionId: string, ClientIp: string}
     */
    public function createCancelRequestData(AbstractPosAccount $account, array $order): array
    {
        $order = $this->prepareCancelOrder($order);

        return [
            'MerchantId'             => $account->getClientId(),
            'Password'               => $account->getPassword(),
            'TransactionType'        => $this->mapTxType(PosInterface::TX_CANCEL),
            'ReferenceTransactionId' => (string) $order['id'],
            'ClientIp'               => (string) $order['ip'],
        ];
    }

    /**
     * {@inheritDoc}
     * @return array{MerchantId: string, Password: string, TransactionType: string, ReferenceTransactionId: string,
     *     ClientIp: string, CurrencyAmount: string}
     */
    public function createRefundRequestData(AbstractPosAccount $account, array $order): array
    {
        $order = $this->prepareRefundOrder($order);

        return [
            'MerchantId'             => $account->getClientId(),
            'Password'               => $account->getPassword(),
            'TransactionType'        => $this->mapTxType(PosInterface::TX_REFUND),
            'ReferenceTransactionId' => (string) $order['id'],
            'ClientIp'               => (string) $order['ip'],
            'CurrencyAmount'         => $this->amountFormat($order['amount']),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function createHistoryRequestData(AbstractPosAccount $account, array $order, array $extraData = []): array
    {
        throw new NotImplementedException();
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string, int|string|float|null>|null                         $order kullanilmiyor
     * @param array{PaReq: string, TermUrl: string, MD: string, ACSUrl: string} $extraData
     *
     * @return array{gateway: string, method: 'POST', inputs: array{PaReq: string, TermUrl: string, MD: string}}
     */
    public function create3DFormData(?AbstractPosAccount $account, ?array $order, ?string $paymentModel, ?string $txType, ?string $gatewayURL, ?AbstractCreditCard $card = null, array $extraData = []): array
    {
        $inputs = [
            'PaReq'   => $extraData['PaReq'],
            'TermUrl' => $extraData['TermUrl'],
            'MD'      => $extraData['MD'],
        ];

        return [
            'gateway' => $extraData['ACSUrl'],
            'method'  => 'POST',
            'inputs'  => $inputs,
        ];
    }

    /**
     * Amount Formatter
     *
     * @param float $amount
     *
     * @return string ex: 10.1 => 10.10
     */
    public function amountFormat(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * 0 => '0'
     * 1 => '0'
     * 2 => '2'
     * @inheritDoc
     */
    public function mapInstallment(int $installment): string
    {
        return $installment > 1 ? (string) $installment : '0';
    }

    /**
     * @inheritDoc
     */
    protected function preparePaymentOrder(array $order): array
    {
        return array_merge($order, [
            'installment' => $order['installment'] ?? 0,
            'currency'    => $order['currency'] ?? PosInterface::CURRENCY_TRY,
            'amount'      => $order['amount'],
        ]);
    }

    /**
     * @inheritDoc
     */
    protected function preparePostPaymentOrder(array $order): array
    {
        return [
            'id'       => $order['id'],
            'amount'   => $order['amount'],
            'currency' => $order['currency'] ?? PosInterface::CURRENCY_TRY,
            'ip'       => $order['ip'],
        ];
    }

    /**
     * @inheritDoc
     */
    protected function prepareStatusOrder(array $order): array
    {
        return [
            'id' => $order['id'],
        ];
    }

    /**
     * TODO
     * @inheritDoc
     */
    protected function prepareHistoryOrder(array $order): array
    {
        return [
            'id' => $order['id'] ?? null,
        ];
    }

    /**
     * @param PayFlexAccount $account
     *
     * @return array{MerchantId: string, Password: string, TerminalNo: string}
     */
    private function getRequestAccountData(AbstractPosAccount $account): array
    {
        return [
            'MerchantId' => $account->getClientId(),
            'Password'   => $account->getPassword(),
            'TerminalNo' => $account->getTerminalId(),
        ];
    }

    /**
     * @param array{recurringFrequency: int, recurringInstallmentCount: int, recurringFrequencyType: string, recurringFrequency: int, recurringEndDate: DateTimeInterface} $order
     *
     * @return array{IsRecurring: 'true', RecurringFrequency: string, RecurringFrequencyType: string, RecurringInstallmentCount: string, RecurringEndDate: string}
     */
    private function createRecurringData(array $order): array
    {
        return [
            'IsRecurring'               => 'true',
            'RecurringFrequency'        => (string) $order['recurringFrequency'], // Periyodik İşlem Frekansı
            'RecurringFrequencyType'    => $this->mapRecurringFrequency($order['recurringFrequencyType']), // Day|Month|Year
            // recurring işlemin toplamda kaç kere tekrar edeceği bilgisini içerir
            'RecurringInstallmentCount' => (string) $order['recurringInstallmentCount'],
            /**
             * Bu alandaki tarih, kartın son kullanma tarihinden büyükse ACS sunucusu işlemi reddeder.
             */
            'RecurringEndDate'          => $order['recurringEndDate']->format('Ymd'),
        ];
    }
}