<?php

declare(strict_types=1);

namespace Mollie\WooCommerce\Gateway\BankTransfer;

use Mollie\Api\Types\PaymentMethod;
use Mollie\Api\Resources\Payment;
use Mollie\WooCommerce\Gateway\AbstractGateway;
use Mollie\WooCommerce\Gateway\PaymentService;
use Mollie\WooCommerce\Gateway\SurchargeService;
use Mollie\WooCommerce\Notice\NoticeInterface;
use Mollie\WooCommerce\Payment\MollieOrder;
use Mollie\WooCommerce\Payment\MollieOrderService;
use Mollie\WooCommerce\Payment\MolliePayment;
use Mollie\WooCommerce\Plugin;
use Mollie\WooCommerce\SDK\HttpResponse;
use Mollie\WooCommerce\Utils\IconFactory;
use WC_Order;
use Psr\Log\LoggerInterface as Logger;

class Mollie_WC_Gateway_BankTransfer extends AbstractGateway
{
    const EXPIRY_DEFAULT_DAYS = 12;
    const EXPIRY_MIN_DAYS = 5;
    const EXPIRY_MAX_DAYS = 60;
    const EXPIRY_DAYS_OPTION = 'order_dueDate';

    /**
     *
     */
    public function __construct(
        IconFactory $iconFactory,
        PaymentService $paymentService,
        SurchargeService $surchargeService,
        MollieOrderService $mollieOrderService,
        Logger $logger,
        NoticeInterface $notice,
        HttpResponse $httpResponse,
        string $pluginUrl,
        string $pluginPath
    ) {

        $this->supports = [
            'products',
            'refunds',
        ];

        parent::__construct(
            $iconFactory,
            $paymentService,
            $surchargeService,
            $mollieOrderService,
            $logger,
            $notice,
            $httpResponse,
            $pluginUrl,
            $pluginPath
        );
        add_filter('woocommerce_' . $this->id . '_args', [$this, 'addPaymentArguments'], 10, 2);
    }

    /**
     * Initialise Gateway Settings Form Fields
     */
    public function init_form_fields()
    {
        parent::init_form_fields();
        unset($this->form_fields['activate_expiry_days_setting']);
        unset($this->form_fields['order_dueDate']);

        $this->form_fields = array_merge($this->form_fields, [
            'activate_expiry_days_setting' => [
                'title' => __('Activate expiry date setting', 'mollie-payments-for-woocommerce'),
                'label' => __('Enable expiry date for payments', 'mollie-payments-for-woocommerce'),
                'description' => __('Enable this option if you want to be able to set the number of days after the payment will expire. This will turn all transactions into payments instead of orders', 'mollie-payments-for-woocommerce'),
                'type' => 'checkbox',
                'default' => 'no',
            ],
            'order_dueDate' => [
                'title' => __('Expiry date', 'mollie-payments-for-woocommerce'),
                'type' => 'number',
                'description' => sprintf(__('Number of DAYS after the payment will expire. Default <code>%d</code> days', 'mollie-payments-for-woocommerce'), self::EXPIRY_DEFAULT_DAYS),
                'default' => self::EXPIRY_DEFAULT_DAYS,
                'custom_attributes' => [
                    'min' => self::EXPIRY_MIN_DAYS,
                    'max' => self::EXPIRY_MAX_DAYS,
                    'step' => 1,
                ],
            ],
            'skip_mollie_payment_screen' => [
                'title' => __('Skip Mollie payment screen', 'mollie-payments-for-woocommerce'),
                'label' => __('Skip Mollie payment screen when Bank Transfer is selected', 'mollie-payments-for-woocommerce'),
                'description' => __('Enable this option if you want to skip redirecting your user to the Mollie payment screen, instead this will redirect your user directly to the WooCommerce order received page displaying instructions how to complete the Bank Transfer payment.', 'mollie-payments-for-woocommerce'),
                'type' => 'checkbox',
                'default' => 'no',
            ],
        ]);
    }

    /**
     * @param array    $args
     * @param WC_Order $order
     *
     * @return array
     */
    public function addPaymentArguments(array $args, WC_Order $order)
    {
        // Expiry date
        $expiry_days = (int)$this->get_option(
            self::EXPIRY_DAYS_OPTION,
            self::EXPIRY_DEFAULT_DAYS
        );

        if ($expiry_days >= self::EXPIRY_MIN_DAYS && $expiry_days <= self::EXPIRY_MAX_DAYS) {
            $expiry_date = date("Y-m-d", strtotime("+$expiry_days days"));

            // Add dueDate at the correct location
            if (isset($args['payment'])) {
                $args['payment']['dueDate'] = $expiry_date;
            } else {
                $args['dueDate'] = $expiry_date;
            }
            $email = (ctype_space($order->get_billing_email())) ? null
                : $order->get_billing_email();
            if ($email) {
                $args['billingEmail'] = $email;
            }
        }

        return $args;
    }

    /**
     * {@inheritdoc}
     *
     * @param WC_Order                                            $order
     * @param MollieOrder|MolliePayment $payment_object
     *
     * @return string
     */
    public function getProcessPaymentRedirect(WC_Order $order, $payment_object)
    {
        if ($this->get_option('skip_mollie_payment_screen') === 'yes') {
            /*
             * Redirect to order received page
             */
            $redirect_url = $this->get_return_url($order);

            // Add utm_nooverride query string
            $redirect_url = add_query_arg([
                'utm_nooverride' => 1,
            ], $redirect_url);

            return $redirect_url;
        }

        return parent::getProcessPaymentRedirect($order, $payment_object);
    }

    /**
     * @return string
     */
    public function getMollieMethodId()
    {
        return PaymentMethod::BANKTRANSFER;
    }

    /**
     * @return string
     */
    public function getDefaultTitle()
    {
        return __('Bank Transfer', 'mollie-payments-for-woocommerce');
    }

    /**
     * @return string
     */
    protected function getSettingsDescription()
    {
        return '';
    }

    /**
     * @return string
     */
    protected function getDefaultDescription()
    {
        return '';
    }

    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    protected function paymentConfirmationAfterCoupleOfDays()
    {
        return true;
    }

    /**
     * @param WC_Order                  $order
     * @param Payment $payment
     * @param bool                      $admin_instructions
     * @param bool                      $plain_text
     * @return string|null
     */
    protected function getInstructions(WC_Order $order, Payment $payment, $admin_instructions, $plain_text)
    {
        $instructions = '';

        if (!$payment->details) {
            return null;
        }

        $data_helper = Plugin::getDataHelper();

        if ($payment->isPaid()) {
            $instructions .= sprintf(
                /* translators: Placeholder 1: consumer name, placeholder 2: consumer IBAN, placeholder 3: consumer BIC */
                __('Payment completed by <strong>%1$s</strong> (IBAN (last 4 digits): %2$s, BIC: %3$s)', 'mollie-payments-for-woocommerce'),
                $payment->details->consumerName,
                substr($payment->details->consumerAccount, -4),
                $payment->details->consumerBic
            );
        } elseif ($order->has_status('on-hold') || $order->has_status('pending')) {
            if (!$admin_instructions) {
                $instructions .= __('Please complete your payment by transferring the total amount to the following bank account:', 'mollie-payments-for-woocommerce') . "\n\n\n";
            }

            /* translators: Placeholder 1: 'Stichting Mollie Payments' */
            $instructions .= sprintf(__('Beneficiary: %s', 'mollie-payments-for-woocommerce'), $payment->details->bankName) . "\n";
            $instructions .= sprintf(__('IBAN: <strong>%s</strong>', 'mollie-payments-for-woocommerce'), implode(' ', str_split($payment->details->bankAccount, 4))) . "\n";
            $instructions .= sprintf(__('BIC: %s', 'mollie-payments-for-woocommerce'), $payment->details->bankBic) . "\n";

            if ($admin_instructions) {
                /* translators: Placeholder 1: Payment reference e.g. RF49-0000-4716-6216 (SEPA) or +++513/7587/59959+++ (Belgium) */
                $instructions .= sprintf(__('Payment reference: %s', 'mollie-payments-for-woocommerce'), $payment->details->transferReference) . "\n";
            } else {
                /* translators: Placeholder 1: Payment reference e.g. RF49-0000-4716-6216 (SEPA) or +++513/7587/59959+++ (Belgium) */
                $instructions .= sprintf(__('Please provide the payment reference <strong>%s</strong>', 'mollie-payments-for-woocommerce'), $payment->details->transferReference) . "\n";
            }

            if (!empty($payment->expiresAt)) {
                $expiryDate = $payment->expiresAt;
                $this->logger->log(\WC_Log_Levels::DEBUG, "Due date assigned: {$expiryDate}");
                $expiryDate = date_i18n(wc_date_format(), strtotime($expiryDate));

                if ($admin_instructions) {
                    $instructions .= "\n" . sprintf(
                        __('The payment will expire on <strong>%s</strong>.', 'mollie-payments-for-woocommerce'),
                        $expiryDate
                    ) . "\n";
                } else {
                    $instructions .= "\n" . sprintf(
                        __('The payment will expire on <strong>%s</strong>. Please make sure you transfer the total amount before this date.', 'mollie-payments-for-woocommerce'),
                        $expiryDate
                    ) . "\n";
                }
            }
        }

        return $instructions;
    }

    protected function isExpiredDateSettingActivated()
    {
        $expiryDays = $this->get_option(
            'activate_expiry_days_setting',
            'no'
        );
        return mollieWooCommerceStringToBoolOption($expiryDays);
    }
}
