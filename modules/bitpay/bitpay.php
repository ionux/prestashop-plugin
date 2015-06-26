<?php

/**
 * The MIT License (MIT)
 *
 * Copyright (c) 2011-2015 BitPay
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * Originally written by Kris, 2012
 * Updated to work with Prestashop 1.6 by Rich Morgan, rich@bitpay.com
 */


if (!defined('_PS_VERSION_')) {
    exit();
}

/**
 * Writes any log messages to the web server's error log by
 * default, see: http://php.net/manual/en/function.error-log.php
 *
 * @param  mixed   $contents  The information to log.
 * @return boolean
 */
function bplog($contents) {
    if (isset($contents)) {
        if (is_resource($contents)) {
            return error_log(serialize($contents));
        } else {
            return error_log(var_export($contents, true));
        }
    } else {
        return false;
    }
}

/**
 * Primary payment module class for the bitpay payment method.
 */
class bitpay extends PaymentModule
{

    /**
     * @var string;
     */
    private $_html = '';

    /**
     * @var array;
     */
    private $_postErrors = array();

    /**
     * @var string;
     */
    private $key = '';

    /**
     * Public class constructor to initialize important values.
     */
    public function __construct()
    {
        include(dirname(__FILE__).'/config.php');

        $this->name            = 'bitpay';
        $this->version         = '0.4';
        $this->author          = 'BitPay';
        $this->className       = 'bitpay';
        $this->currencies      = true;
        $this->currencies_mode = 'checkbox';
        $this->tab             = 'payments_gateways';
        $this->bitpayurl       = $bitpayurl;
        $this->apiurl          = $apiurl;
        $this->sslport         = $sslport;
        $this->verifypeer      = $verifypeer;
        $this->verifyhost      = $verifyhost;

        if (_PS_VERSION_ > '1.5') {
            $this->controllers = array('payment', 'validation');
        }

        parent::__construct();

        $this->page = basename(__FILE__, '.php');

        $this->displayName      = $this->l('bitpay');
        $this->description      = $this->l('Accepts Bitcoin payments via BitPay.');
        $this->confirmUninstall = $this->l('Are you sure you want to delete your details?');

        // Backward compatibility
        require(_PS_MODULE_DIR_ . 'bitpay/backward_compatibility/backward.php');

        $this->context->smarty->assign('base_dir', __PS_BASE_URI__);
    }

    public function install()
    {
        if (function_exists('curl_version') === false) {
            $this->_errors[] = $this->l('Sorry, the BitPay payment plugin requires the cURL PHP extension but it is not enabled on your server.  Please ask your web hosting provider for assistance.');
            return false;
        }

        if (!parent::install()              ||
            !$this->registerHook('invoice') ||
            !$this->registerHook('payment') ||
            !$this->registerHook('paymentReturn'))
        {
            return false;
        }

        $db = Db::getInstance();

        $query = "CREATE TABLE `" . _DB_PREFIX_ . "order_bitcoin` (
                 `id_payment` int(11) NOT NULL AUTO_INCREMENT,
                 `id_order` int(11) NOT NULL,
                 `cart_id` int(11) NOT NULL,
                 `invoice_id` varchar(255) NOT NULL,
                 `status` varchar(255) NOT NULL,
                 PRIMARY KEY (`id_payment`),
                 UNIQUE KEY `invoice_id` (`invoice_id`)
                 ) ENGINE=" . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8';

        // Creates table for payment method.
        $db->Execute($query);

        $query = 'INSERT IGNORE INTO `ps_configuration` ' .
                     '(`name`, `value`, `date_add`, `date_upd`) ' .
                 'VALUES ' .
                     '("PS_OS_BITPAY", "13", NOW(), NOW());';

        // Adds configuration to store db.
        $db->Execute($query);

        return true;
    }

    public function uninstall()
    {
        Configuration::deleteByName('bitpay_APIKEY');

        return parent::uninstall();
    }

    public function getContent()
    {
        $this->_html .= '<h2>' . $this->l('bitpay') . '</h2>';

        $this->_postProcess();
        $this->_setbitpaySubscription();
        $this->_setConfigurationForm();

        return $this->_html;
    }

    public function hookPayment($params)
    {
        global $smarty;

        $smarty->assign(array(
                              'this_path'     => $this->_path,
                              'this_path_ssl' => $this->getModulesURL(),
                             )
                        );

        return $this->display(__FILE__, 'payment.tpl');
    }

    private function _setbitpaySubscription()
    {
        $this->_html .= '<div style="float: right; width: 440px; height: 150px; border: dashed 1px #666; padding: 8px; margin-left: 12px;">' .
                        '<h2>' . $this->l('Opening your BitPay account') . '</h2>' .
                        '<div style="clear: both;"></div>' .
                        '<p>' . $this->l('When opening your BitPay account by clicking on the following image, you are helping us significantly to improve the BitPay solution:') . '</p>' .
                        '<p style="text-align: center;"><a href="https://bitpay.com/"><img src="../modules/bitpay/prestashop_bitpay.png" alt="PrestaShop & BitPay" style="margin-top: 12px;" /></a></p>' .
                        '<div style="clear: right;"></div></div>' .
                        '<img src="../modules/bitpay/bitcoin.png" alt="PrestaShop & BitPay" style="float:left; margin-right:15px;" />' .
                        '<b>' . $this->l('This module allows you to accept payments by BitPay.') . '</b><br>' .
                        '<br>' . $this->l('If the client chooses this payment mode, your BitPay account will be automatically credited.') .
                        '<br>' . $this->l('You need to configure your BitPay account before using this module.') . 
                        '<div style="clear:both;">&nbsp;</div>';
    }

    private function _setConfigurationForm()
    {
        $this->_html .= '<form method="post" action="' . htmlentities($_SERVER['REQUEST_URI']) . '">' .
                        '<script type="text/javascript">' .
                        'var pos_select = ' . (($tab = (int)Tools::getValue('tabs')) ? $tab : '0') . ';' .
                        '</script>';

        $this->_html .= $this->getVersionSpecificConfigHTML();

        $this->_html .= '<input type="hidden" name="tabs" id="tabs" value="0" />' . "\n" . 
                        '<div class="tab-pane" id="tab-pane-1" style="width:100%;">' . "\n" . 
                        '<div class="tab-page" id="step1">' . "\n" . 
                        '<h4 class="tab">' . $this->l('Settings') . "\n" . 
                        '</h4>' . $this->_getSettingsTabHtml() . "\n" . 
                        '</div></div>' . "\n" . 
                        '<div class="clear"></div>' . "\n" . 
                        '<script type="text/javascript">' . "\n" . 
                        '    function loadTab(id){}' . "\n" . 
                        '    setupAllTabs();' . "\n" . 
                        '</script>' . "\n" . 
                        '</form>' . "\n";
    }

    private function _getSettingsTabHtml()
    {
        global $cookie;

        $lowSelected    = '';
        $mediumSelected = '';
        $highSelected   = '';

        // Remember which speed has been selected and display that
        // upon reaching the settings page; defaults to low speed.
        switch ($this->getTxSpeed()) {
            case 'high':
                $highSelected   = ' selected="selected"';
                break;
            case 'medium':
                $mediumSelected = ' selected="selected"';
                break;
            case 'low':
            default:
                $lowSelected    = ' selected="selected"';
        }

        $apikey = htmlentities(Tools::getValue('apikey', $this->getAPIKey()), ENT_COMPAT, 'UTF-8');

        return '<h2>' . $this->l('Settings') . '</h2>' .
               '<h3 style="clear:both;">' . $this->l('API Key') . '</h3>' . 
               '<div class="margin-form">' . 
               '<input type="text" name="apikey_bitpay" value="' . $apikey . '" />' . 
               '</div>' .
               '<h3 style="clear:both;">' . $this->l('Transaction Speed') . '</h3>' .
               '<div class="margin-form">' .
               '<select name="txspeed_bitpay">' .
               '<option value="low"' . $lowSelected . '>Low</option>' .
               '<option value="medium"' . $mediumSelected . '>Medium</option>' .
               '<option value="high"' . $highSelected . '>High</option>' .
               '</select>' .
               '</div>' .
               '<p class="center">' .
               '<input class="button" type="submit" name="submitbitpay" value="' . $this->l('Save settings') . '" />' .
               '</p>';
    }

    private function _postProcess()
    {
        global $currentIndex, $cookie;

        if (Tools::isSubmit('submitbitpay')) {
            $template_available = array('A', 'B', 'C');
            $this->_errors      = array();

            if (Tools::getValue('apikey_bitpay') == NULL) {
                $this->_errors[]  = $this->l('Missing BitPay API Key!');
            }

            if (count($this->_errors) > 0) {
                $error_msg = '';

                foreach ($this->_errors AS $error) {
                    $error_msg .= $error . '<br />';
                }

                $this->_html = $this->displayError($error_msg);

            } else {
                Configuration::updateValue('bitpay_APIKEY', trim(Tools::getValue('apikey_bitpay')));
                Configuration::updateValue('bitpay_TXSPEED', trim(Tools::getValue('txspeed_bitpay')));

                $this->_html = $this->displayConfirmation($this->l('Settings updated'));
            }
        }
    }

    public function execPayment($cart)
    {
        // Create invoice
        $currency                     = Currency::getCurrencyInstance((int)$cart->id_currency);
        $options                      = $_POST;
        $options['transactionSpeed']  = $this->getTxSpeed();
        $options['currency']          = $currency->iso_code;
        $total                        = $cart->getOrderTotal(true);
        $options['notificationURL']   = $this->getBaseURL() . 'modules/' . $this->name . '/ipn.php';
        $options['redirectURL']       = $this->getVersionSpecificRedirectURL($cart->id);
        $options['posData']           = '{"cart_id": "' . $cart->id . '"';
        $options['posData']          .= ', "hash": "' . crypt($cart->id, $this->getAPIKey()) . '"';
        $this->key                    = $this->context->customer->secure_key;
        $options['posData']          .= ', "key": "' . $this->key . '"}';
        $options['orderID']           = $cart->id;
        $options['price']             = $total;
        $options['fullNotifications'] = true;

        $postOptions                  = array(
                                              'orderID', 'itemDesc', 'itemCode', 
                                              'notificationEmail', 'notificationURL',
                                              'redirectURL',  'posData', 'price',
                                              'currency', 'physical', 'fullNotifications',
                                              'transactionSpeed', 'buyerName', 'buyerAddress1', 
                                              'buyerAddress2', 'buyerCity', 'buyerState', 'buyerZip', 
                                              'buyerEmail', 'buyerPhone',
                                             );

        foreach($postOptions as $o) {
            if (array_key_exists($o, $options)) {
                $post[$o] = $options[$o];
            }
        }

        $post = json_encode($post);

        // Call BitPay
        $curl   = curl_init($this->apiurl . '/api/invoice/');

        $responseString = $this->makeCurlCall($post, $curl);

        if (!$responseString) {
            $response = curl_error($curl);
            $this->dieFatal("Error: no data returned from BitPay API server!");
        } else {
          $response = json_decode($responseString, true);
        }

        curl_close($curl);

        if (isset($response['error'])) {
            $this->dieFatal("Error occurred! (" . $response['error']['type'] . " - " . $response['error']['message'] . ")");
        }

        if (!$response['url']) {
            $this->dieFatal("Error: Response from BitPay did not include invoice url!");
        } else {
            header('Location:  ' . $response['url']);
            exit();
        }
    }

    public function writeDetails($id_order, $cart_id, $invoice_id, $status)
    {
        $invoice_id = stripslashes(str_replace("'", '', $invoice_id));
        $status     = stripslashes(str_replace("'", '', $status));

        // Let's add the details of
        // the order to our database.
        $db = Db::getInstance();

        $result = $db->Execute(
                               'INSERT INTO `' . _DB_PREFIX_ . 'order_bitcoin` ' .
                                   '(`id_order`, `cart_id`, `invoice_id`, `status`) ' . 
                               'VALUES ' . 
                                   '(' .
                                         intval($id_order) . ', ' .
                                         intval($cart_id) . ', "' .
                                         $invoice_id . '", "'
                                         . $status .
                                    '") ' . 
                               'on duplicate key update `status`="' . $status . '"'
                              );
    }

    public function readBitcoinpaymentdetails($id_order)
    {
        // Retrieve payment details from
        // our database for an order.
        $db = Db::getInstance();

        $result = $db->ExecuteS(
                                'SELECT * FROM `' . _DB_PREFIX_ . 'order_bitcoin` ' .
                                'WHERE ' .
                                    '`id_order` = ' . intval($id_order) . 
                                'LIMIT 1;'
                                );

        return (isset($result[0])) ? $result[0] : null;
    }

    public function hookInvoice($params)
    {
        global $smarty;

        $id_order = $params['id_order'];
        $bitcoinpaymentdetails = $this->readBitcoinpaymentdetails($id_order);

        $smarty->assign(array(
                              'bitpayurl'     => $this->bitpayurl,
                              'invoice_id'    => $bitcoinpaymentdetails['invoice_id'],
                              'status'        => $bitcoinpaymentdetails['status'],
                              'id_order'      => $id_order,
                              'this_page'     => $_SERVER['REQUEST_URI'],
                              'this_path'     => $this->_path,
                              'this_path_ssl' => $this->getModulesURL(),
                           ));

        return $this->display(__FILE__, 'invoice_block.tpl');
    }

    public function hookPaymentReturn($params)
    {
        global $smarty;

        $order = $params['objOrder'];

        $smarty->assign(array(
                              'state'         => $order->current_state,
                              'this_path'     => $this->_path,
                              'this_path_ssl' => $this->getModulesURL(),
                             ));

        return $this->display(__FILE__, 'payment_return.tpl');
    }

    private function makeCurlCall($post, $curl)
    {
        $length = 0;

        if ($post) {
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $post);

            $length = strlen($post);
        }

        $uname = base64_encode($this->getAPIKey());

        $header = array(
                        'Content-Type: application/json',
                        'Content-Length: ' . $length,
                        'Authorization: Basic ' . $uname,
                        'X-BitPay-Plugin-Info: prestashop' . $this->version,
                       );

        curl_setopt($curl, CURLINFO_HEADER_OUT, true);
        curl_setopt($curl, CURLOPT_PORT, $this->sslport);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10);
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC) ;
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, $this->verifypeer); // verify certificate (1)
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, $this->verifyhost); // check existence of CN and verify that it matches hostname (2)
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_FORBID_REUSE, 1);
        curl_setopt($curl, CURLOPT_FRESH_CONNECT, 1);

        return curl_exec($curl);
    }

    private function getAPIKey()
    {
        return Configuration::get('bitpay_APIKEY');
    }

    private function getModulesURL()
    {
        return Configuration::get('PS_FO_PROTOCOL') . $_SERVER['HTTP_HOST'] . __PS_BASE_URI__ . 'modules/' . $this->name . '/';
    }

    private function getBaseURL()
    {
        return (Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://') . htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8') . __PS_BASE_URI__;
    }

    private function getVersionSpecificRedirectURL($cart_id)
    {
        if (_PS_VERSION_ <= '1.5') {
            return $this->getBaseURL() . 'order-confirmation.php?id_cart=' . $cart_id . '&id_module=' . $this->id . '&id_order=' . $this->currentOrder;
        }

        return Context::getContext()->link->getModuleLink('bitpay', 'validation');

    }

    private function getVersionSpecificConfigHTML()
    {
        if (_PS_VERSION_ <= '1.5') {
            return '<script type="text/javascript" src="' . _PS_BASE_URL_ . _PS_JS_DIR_ . 'tabpane.js"></script>' .
                   '<link type="text/css" rel="stylesheet" href="' . _PS_BASE_URL_ . _PS_CSS_DIR_ . 'tabpane.css" />';
        }

            return '<script type="text/javascript" src="' . _PS_BASE_URL_ . _PS_JS_DIR_ . 'jquery/plugins/tabpane/jquery.tabpane.js"></script>' .
                   '<link type="text/css" rel="stylesheet" href="' . _PS_BASE_URL_ . _PS_JS_DIR_ . 'jquery/plugins/tabpane/jquery.tabpane.css" />';

    }

    private function getTxSpeed()
    {
        return Configuration::get('bitpay_TXSPEED');
    }
    
    private function dieFatal($message)
    {
        bplog($message);

        die(Tools::displayError($message));
    }

    private function dbOp($type = 'select', $table, $clause)
    {
        $db = Db::getInstance();

        $result = $db->ExecuteS

        switch (strtolower(trim($type))) {
            case 'select':
                $statement = 'SELECT * FROM `' . _DB_PREFIX_ . $table . '`';
                if (trim($clause) != '') {
                    $statement .= ' WHERE ' . $clause;
                }
                $statement .= ' LIMIT 1;'
                break;
        }

        return $db->Execute($statement);
    }
}
