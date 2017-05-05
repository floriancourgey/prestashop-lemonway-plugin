<?php

/**
 * 2017 Lemon way
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to contact@lemonway.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this addon to newer
 * versions in the future. If you wish to customize this addon for your
 * needs please contact us for more information.
 *
 * @author Kassim Belghait <kassim@sirateck.com>, PHAM Quoc Dat <dpham@lemonway.com>
 * @copyright  2017 Lemon way
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class LemonwayRedirectModuleFrontController extends ModuleFrontController
{
    protected $supportedLangs = array(
        'da' => 'da',
        'de' => 'ge',
        'en' => 'en',
        'es' => 'sp',
        'fi' => 'fi',
        'fr' => 'fr',
        'it' => 'it',
        'ko' => 'ko',
        'no' => 'no',
        'pt' => 'po',
        'sv' => 'sw'
    );

    protected $defaultLang = 'en';

    public $errors = array();

    public function __construct()
    {
        parent::__construct();
        require_once _PS_MODULE_DIR_ . $this->module->name . '/services/LemonWayKit.php';
    }

    /**
     * Do whatever you have to before redirecting the customer on the website of your payment processor.
     */
    public function postProcess()
    {
        $cart = $this->context->cart;
        /* @var $customer CustomerCore */
        $customer = $this->context->customer;

        $secure_key = $this->context->customer->secure_key;
        $kit = new LemonWayKit();

        /**
         * Generate a new wkToken for this cart ID
         * It' is necessary to send a new wkToken for each requests
         */
        $wkToken = $this->module->saveWkToken($cart->id);
        $comment = Configuration::get('PS_SHOP_NAME') . " - " . $cart->id . " - " .
            $customer->lastname . " " . $customer->firstname . " - " . $customer->email;

        /**
         * Check if module mkt is installed, in this case, we don't send amount commission
         * Because we need this funds for credit vendors
         *
         */
        // $amountComRaw = !$this->module->moduleMktIsEnabled() ? (float)$cart->getOrderTotal(true, 3) : 0;
        $amountComRaw = 0;
        $amountCom = number_format($amountComRaw, 2, '.', '');

        $amountTotRaw = $cart->getOrderTotal(true, 3);
        $amountTot = number_format((float) $amountTotRaw, 2, '.', '');

        $methodCode = Tools::getValue('method_code');

        try {
            /* @var $methodInstance Method */
            $methodInstance = $this->module->methodFactory($methodCode);
        } catch (Exception $e) {
            $this->addError($this->l('Payment method is not allowed'));
            return $this->displayError();
        }

        if (!$methodInstance->isAllowed()) {
            $this->addError($this->l('Payment method is not allowed'));
            return $this->displayError();
        }

        $baseCallbackParams = array(
            'secure_key' => $secure_key,
            'payment_method' => $methodCode,
        );

        //If is X times method, we split the payment
        if ($methodInstance->isSplitPayment() && ($splitPaypentProfileId = Tools::getValue('splitpayment_profile_id'))) {
            $profile = new SplitpaymentProfile($splitPaypentProfileId);

            if ($profile) {
                $splitpayments = $profile->splitPaymentAmount($amountTotRaw);
                $firstSplit = $splitpayments[0];
                $amountTot = number_format((float) $firstSplit['amountToPay'], 2, '.', '');

                //Add prodile Id to base callbackparamters
                $baseCallbackParams['splitpayment_profile_id'] = $splitPaypentProfileId;
            } else {
                $this->addError($this->l('Split payment profile not found!'));
                return $this->displayError();
            }
        }

        $returnlCallbackParams = array_merge($baseCallbackParams, array(
            'register_card' => (int) $this->registerCard(),
            'action' => 'return'
        ));

        $cancelCallbackParams = array_merge($baseCallbackParams, array(
            'action' => 'cancel'
        ));

        $errorCallbackParams = array_merge($baseCallbackParams, array(
            'action' => 'error'
        ));


        if (!$this->useCard()) {
            //call directkit to get Webkit Token
            $params = array(
                'wkToken' => $wkToken,
                'wallet' => LemonWayConfig::getWalletMerchantId(),
                'amountTot' => $amountTot,
                'amountCom' => $amountCom, //because money is transfered in merchant wallet
                'comment' => $comment,
                'returnUrl' => urlencode($this->context->link->getModuleLink('lemonway', 'validation', $returnlCallbackParams, true)),
                'cancelUrl' => urlencode($this->context->link->getModuleLink('lemonway', 'validation', $cancelCallbackParams, true)),
                'errorUrl' => urlencode($this->context->link->getModuleLink('lemonway', 'validation', $errorCallbackParams, true)),
                'autoCommission' => 0,
                'registerCard' => $this->registerCard(), //For Atos
                'useRegisteredCard' => $this->registerCard(), //For payline
            );

            try {
                $res = $kit->moneyInWebInit($params);

                /**
                 * Oops, an error occured.
                 */
                if (isset($res->lwError)) {
                    throw new Exception((string) $res->lwError->MSG, (int) $res->lwError->CODE);
                }

                if ($customer->id && isset($res->lwXml->MONEYINWEB->CARD) && $this->registerCard()) {
                    $card = $this->module->getCustomerCard($customer->id);

                    if (!$card) {
                        $card = array();
                    }

                    $card['id_customer'] = $customer->id;
                    $card['id_card'] = (string) $res->lwXml->MONEYINWEB->CARD->ID;

                    $this->module->insertOrUpdateCard($customer->id, $card);
                }
            } catch (Exception $e) {
                $this->addError($e->getMessage());
                return $this->displayError();
            }

            $moneyInToken = (string) $res->lwXml->MONEYINWEB->TOKEN;

            $language = $this->getLang();

            $lwUrl = LemonWayConfig::getWebkitUrl() . '?moneyintoken=' . $moneyInToken . '&p='
                . urlencode(LemonWayConfig::getCssUrl()) . '&lang=' . $language;

            //Get selected card type
            if (!empty($ccType = Tools::getValue('cc_type', ''))) {
                $allowedCcType = array('CB', 'VISA', 'MASTERCARD');

                if (in_array($ccType, $allowedCcType)) {
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $lwUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !Configuration::get('LEMONWAY_IS_TEST_MODE', false));

                    $response = curl_exec($ch);
                    //echo( 'Response: ' . $response);
                    //Parse response to get action url and data field
                    $matches = array();
                    $patternFormActionAndData = '/(action="|name=data value=")([^"]*)"/i';

                    if (preg_match_all($patternFormActionAndData, $response, $matches)) {
                        if (isset($matches[2])) {
                            list($actionUrl, $data) = $matches[2];

                            $postFields = array(
                                'DATA' => $data,
                                $ccType => 1
                            );

                            $html = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">' . "\n";
                            $html .= '<html>' . "\n";
                            $html .= '    <head>' . "\n";
                            $html .= '    </head>';
                            $html .= '    <body>';
                            $html .= '        <div align="center"><br />' . $this->l('You will be redirected to payment page in a few seconds.') . '</div>' . "\n";
                            $html .= '        <div id="buttons" style="display: none;">' . "\n";
                            $html .= '            <form id="lemonway_payment_redirect" action="' . $actionUrl . '" method="post">' . "\n";
                            $html .= '                <input type="hidden" name="' . $ccType . '_x" value="1" />' . "\n";
                            $html .= '                <input type="hidden" name="' . $ccType . '_y" value="1" />' . "\n";
                            $html .= '                <input type="hidden" name="DATA" value="' . $data . '" />' . "\n";
                            $html .= '            </form>' . "\n";
                            $html .= '        </div>' . "\n";
                            $html .= '        <script type="text/javascript">document.getElementById("lemonway_payment_redirect").submit();</script>' . "\n";
                            $html .= '    </body>' . "\n";
                            $html .= '</html>';

                            die($html);
                        }
                    }
                }
            }

            Tools::redirect($lwUrl);
        } else {
            if (($card = $this->module->getCustomerCard($customer->id)) && $customer->isLogged()) {
                //Call directkit for MoneyInWithCardId
                $params = array(
                    'wkToken' => $wkToken,
                    'wallet' => LemonWayConfig::getWalletMerchantId(),
                    'amountTot' => $amountTot,
                    'amountCom' => $amountCom,
                    'comment' => $comment . " (Money In with Card Id)",
                    'autoCommission' => 0,
                    'cardId' => $card['id_card']
                );

                try {
                    $res = $kit->moneyInWithCardId($params);
                } catch (Exception $e) {
                    $this->addError($e->getMessage());
                    return $this->displayError();
                }

                if (isset($res->lwError)) {
                    $this->addError(
                        'An error occurred while trying to pay with your registered card',
                        "Error code: " . $res->lwError->CODE . " Message: " . $res->lwError->MSG
                    );
                    return $this->displayError();
                }

                /* @var $op Operation */
                foreach ($res->operations as $op) {
                    if ($op->STATUS == "3") {
                        $id_order_state = Configuration::get('PS_OS_PAYMENT');

                        if ($methodInstance->isSplitPayment()) {
                            $id_order_state = Configuration::get(Lemonway::LEMONWAY_SPLIT_PAYMENT_OS);
                        }

                        $message = Tools::getValue('response_msg');

                        $currency_id = (int) $this->context->currency->id;
                        //$amount = number_format((float)$cart->getOrderTotal(true, 3), 2, '.', '');
                        //$amount = number_format(((float)$op->CRED + (float)$op->COM), 2, '.', '');

                        $this->module->validateOrder($cart->id, $id_order_state, $amountTot, $methodInstance->getTitle(), $message, array(), $currency_id, false, $secure_key);

                        $order_id = Order::getOrderByCartId((int) $cart->id);

                        if ($order_id) {
                            $module_id = $this->module->id;
                            return Tools::redirect(
                                'index.php?controller=order-confirmation&id_cart=' . $cart->id
                                . '&id_module=' . $module_id . '&id_order=' . $order_id . '&key=' . $secure_key
                            );
                        } else {
                            $this->addError("Error while saving order!");
                            return $this->displayError();
                        }

                        break;
                    } else {
                        $this->addError($op->MSG);
                        return $this->displayError();
                    }
                }
            } else {
                $this->addError('Customer not logged or card not found!');
                return $this->displayError();
            }
        }
    }

    protected function registerCard()
    {
        return Tools::getValue('lw_oneclic') === 'register_card' || is_numeric(Tools::getValue('splitpayment_profile_id'));
    }

    protected function useCard()
    {
        return Tools::getValue('lw_oneclic') === 'use_card';
    }

    /**
     * Return current lang code
     *
     * @return string
     */
    protected function getLang()
    {
        if (array_key_exists($this->context->language->iso_code, $this->supportedLangs)) {
            return $this->supportedLangs[$this->context->language->iso_code];
        }

        return $this->defaultLang;
    }

    protected function addError($message, $description = false)
    {
        /**
         * Set error message and description for the template.
         */
        array_push($this->errors, $this->module->l($message), $description);
    }

    protected function displayError()
    {
        /**
         * Create the breadcrumb for your ModuleFrontController.
         */
        $path = '<a href="' . $this->context->link->getPageLink('order', null, null, 'step=3') . '">'
            . $this->module->l('Payment')
            . '</a><span class="navigation-pipe">&gt;</span>' . $this->module->l('Error');
        $this->context->smarty->assign(
            array('path' => $path,
                'errors' => $this->errors
            )
        );

        $template = 'error.tpl';

        if ($this->module->isVersion17()) {
            $template = 'module:' . $this->module->name . '/views/templates/front/error.tpl';
        }

        return $this->setTemplate($template);
    }

    protected function methodIsAllowed($methodCode)
    {
        $methodCode = strtoupper($methodCode);

        if (!Configuration::get('LEMONWAY_' . $methodCode . '_ENABLED')) {
            return false;
        }

        switch ($methodCode) {
            case "creditcard_xtimes":
                if (!in_array(Tools::getValue('splitpayment_profile_id'), $this->module->getSplitpaymentProfiles())) {
                    return false;
                } else {
                    return true;
                }
                break;

            default:
                return true;
        }
    }
}
