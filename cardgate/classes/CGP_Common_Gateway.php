<?php
if (! defined('ABSPATH'))
    exit();

// Exit if accessed directly

/*
 * Title: WooCommerce CGP Common Gateway
 * Description: Gateway class
 * Copyright: Copyright (c) 2005 - 2017
 * Company: Cardgate
 * @author CardGate
 * @version 1.0.0
 */
class CGP_Common_Gateway extends WC_Payment_Gateway {

    var $bankOption;

    // ////////////////////////////////////////////////
    function __construct() {}

    /**
     * Show the description if set, and show the bank options.
     */
    function payment_fields() {
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }
        if ($this->has_fields) {
            $this->generate_bank_html();
        }
    }

    // ////////////////////////////////////////////////
    
    /**
     * Generate the bank options
     */
    function generate_bank_html() {
        $aIssuers = $this->getBankOptions();
        
        $html = '<fieldset>
            <p class="form-row form-row-first ">
                <label for="cc-expire-month">' . __('Bank Option', 'cardgate') . '<span class="required">*</span></label>';
        $html .= '<select name="cgp_bank_options" id="cgp_bank_options" class="woocommerce-select">';
        $html .= '<option value="0">Kies uw bank</option>';
        foreach ($aIssuers as $id => $name) {
            $html .= '<option value="' . $id;
            if (isset($this->bankOption) && $id == $this->bankOption) {
                $html .= ' selected="selected" ';
            }
            $html .= '">' . $name . '</option>';
        }
        $html .= '</select>
            </p> 
        </fieldset>';
        echo $html;
    }

    // ////////////////////////////////////////////////
    
    /**
     * Fetch bank options from Card Gate
     */
    private function getBankOptions() {
        try {
            
            require_once WP_PLUGIN_DIR . '/cardgate/cardgate-clientlib-php/init.php';
            
            $iMerchantId = (get_option('cgp_merchant_id') ? get_option('cgp_merchant_id') : 0);
            $sMerchantApiKey = (get_option('cgp_merchant_api_key') ? get_option('cgp_merchant_api_key') : 0);
            $bIsTest = (get_option('cgp_mode') == 1 ? true : false);
            
            $oCardGate = new cardgate\api\Client((int) $iMerchantId, $sMerchantApiKey, $bIsTest);
            $oCardGate->setIp($_SERVER['REMOTE_ADDR']);
            
            $aIssuers = $oCardGate->methods()
                ->get(cardgate\api\Method::IDEAL)
                ->getIssuers();
        } catch (cardgate\api\Exception $oException_) {
            $aIssuers[0] = [
                'id' => 0,
                'name' => htmlspecialchars($oException_->getMessage())
            ];
        }
        
        $options = array();
        
        foreach ($aIssuers as $aIssuer) {
            $options[$aIssuer['id']] = $aIssuer['name'];
        }
        return $options;
    }

    // //////////////////////////////////////////////
    
    /**
     * Initialise Gateway Settings Form Fields
     */
    function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'cardgate'),
                'type' => 'checkbox',
                'label' => __('Enable ' . $this->payment_name, 'cardgate'),
                'default' => 'no'
            ),
            'title' => array(
                'title' => __('Title', 'cardgate'),
                'type' => 'text',
                'description' => __('Payment method description that the customer will see on your checkout.', 'cardgate'),
                'default' => $this->payment_name,
                'desc_tip' => true
            ),
            'description' => array(
                'title' => __('Description', 'cardgate'),
                'type' => 'textarea',
                'description' => __('Payment method description that the customer will see on your website.', 'cardgate'),
                'default' => __('Pay with ', 'cardgate') . $this->payment_name,
                'desc_tip' => true
            )
        );
    }

    // ////////////////////////////////////////////////
    
    /**
     * Admin Panel Options
     * - Options for bits like 'title' and availability on a country-by-country basis
     *
     * @since 1.0.0
     */
    public function admin_options() {
        ?>
<h3>
            <?php _e( $this->admin_title, $this->id ); ?>
        </h3>
<table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>
<!--/.form-table-->
<?php
    }

    // ////////////////////////////////////////////////
    
    /**
     * Process the payment and return the result
     *
     * @param integer $iOrderId            
     */
    function process_payment($iOrderId) {
        global $woocommerce;
        require_once WP_PLUGIN_DIR . '/cardgate/cardgate-clientlib-php/init.php';
        
        try {
            
            $this->savePaymentData($iOrderId);
            
            $iMerchantId = (get_option('cgp_merchant_id') ? get_option('cgp_merchant_id') : 0);
            $sMerchantApiKey = (get_option('cgp_merchant_api_key') ? get_option('cgp_merchant_api_key') : 0);
            $bIsTest = (get_option('cgp_mode') == 1 ? true : false);
            $sLanguage = substr(get_locale(), 0, 2);
            $oOrder = new WC_Order($iOrderId);
            
            $sVersion = ($this->get_woocommerce_version() == '' ? 'unkown' : $this->get_woocommerce_version());
            
            $oCardGate = new cardgate\api\Client((int) $iMerchantId, $sMerchantApiKey, $bIsTest);
            
            $oCardGate->setIp($_SERVER['REMOTE_ADDR']);
            $oCardGate->setLanguage($sLanguage);
            $oCardGate->version()->setPlatformName('Woocommerce');
            $oCardGate->version()->setPlatformVersion($sVersion);
            $oCardGate->version()->setPluginName('CardGate');
            $oCardGate->version()->setPluginVersion(get_option('cardgate_version'));
            
            $iSiteId = (int) get_option('cgp_siteid');
            $amount = (int) round($oOrder->get_total() * 100);
            $currency = get_woocommerce_currency();
            
            $oTransaction = $oCardGate->transactions()->create($iSiteId, $amount, $currency);
            
            // Configure payment option.
            $oTransaction->setPaymentMethod($this->payment_method);
            if ($this->payment_method == 'idealpro') {
                $oTransaction->setIssuer($this->bankOption);
            }
            method_exists($oOrder, 'get_billing_email') ? $billing_email = $oOrder->get_billing_email() : $billing_email = $oOrder->billing_email;
            method_exists($oOrder, 'get_billing_phone') ? $billing_phone = $oOrder->get_billing_phone() : $billing_phone = $oOrder->billing_phone;
            method_exists($oOrder, 'get_billing_first_name') ? $billing_first_name = $oOrder->get_billing_first_name() : $billing_first_name = $oOrder->billing_first_name;
            method_exists($oOrder, 'get_billing_last_name') ? $billing_last_name = $oOrder->get_billing_last_name() : $billing_last_name = $oOrder->billing_last_name;
            method_exists($oOrder, 'get_billing_last_name') ? $billing_last_name = $oOrder->get_billing_last_name() : $billing_last_name = $oOrder->billing_last_name;
            method_exists($oOrder, 'get_billing_address_1') ? $billing_address_1 = $oOrder->get_billing_address_1() : $billing_address_1 = $oOrder->billing_address_1;
            method_exists($oOrder, 'get_billing_address_2') ? $billing_address_2 = $oOrder->get_billing_address_2() : $billing_address_2 = $oOrder->billing_address_2;
            method_exists($oOrder, 'get_billing_postcode') ? $billing_postcode = $oOrder->get_billing_postcode() : $billing_postcode = $oOrder->billing_postcode;
            method_exists($oOrder, 'get_billing_state') ? $billing_state = $oOrder->get_billing_state() : $billing_state = $oOrder->billing_state;
            method_exists($oOrder, 'get_billing_city') ? $billing_city = $oOrder->get_billing_city() : $billing_city = $oOrder->billing_city;
            method_exists($oOrder, 'get_billing_country') ? $billing_country = $oOrder->get_billing_country() : $billing_country = $oOrder->billing_country;
            
            // Configure customer.
            $oConsumer = $oTransaction->getConsumer();
            if ($billing_email != '') {
                $oConsumer->setEmail($billing_email);
            }
            if ($billing_phone != '') {
                $oConsumer->setPhone($billing_phone);
            }
            if ($billing_first_name != '') {
                $oConsumer->address()->setFirstName($billing_first_name);
            }
            if ($billing_last_name != '') {
                $oConsumer->address()->setLastName($billing_last_name);
            }
            $billing_address = trim($billing_address_1 . ' ' . $billing_address_2);
            if ($billing_address != '') {
                $oConsumer->address()->setAddress(trim($billing_address_1 . ' ' . $billing_address_2));
            }
            if ($billing_postcode != '') {
                $oConsumer->address()->setZipCode($billing_postcode);
            }
            if ($billing_city != '') {
                $oConsumer->address()->setCity($billing_city);
            }
            if ($billing_state != '') {
                $oConsumer->address()->setState($billing_state);
            }
            if ($billing_country != '') {
                $oConsumer->address()->setCountry($billing_country);
            }
            
            method_exists($oOrder, 'get_shipping_first_name') ? $shipping_first_name = $oOrder->get_shipping_first_name() : $shipping_first_name = $oOrder->shipping_first_name;
            method_exists($oOrder, 'get_shipping_last_name') ? $shipping_last_name = $oOrder->get_shipping_last_name() : $shipping_last_name = $oOrder->shipping_last_name;
            method_exists($oOrder, 'get_shipping_last_name') ? $shipping_last_name = $oOrder->get_shipping_last_name() : $shipping_last_name = $oOrder->shipping_last_name;
            method_exists($oOrder, 'get_shipping_address_1') ? $shipping_address_1 = $oOrder->get_shipping_address_1() : $shipping_address_1 = $oOrder->shipping_address_1;
            method_exists($oOrder, 'get_shipping_address_2') ? $shipping_address_2 = $oOrder->get_shipping_address_2() : $shipping_address_2 = $oOrder->shipping_address_2;
            method_exists($oOrder, 'get_shipping_postcode') ? $shipping_postcode = $oOrder->get_shipping_postcode() : $shipping_postcode = $oOrder->shipping_postcode;
            method_exists($oOrder, 'get_shipping_state') ? $shipping_state = $oOrder->get_shipping_state() : $shipping_state = $oOrder->shipping_state;
            method_exists($oOrder, 'get_shipping_city') ? $shipping_city = $oOrder->get_shipping_city() : $shipping_city = $oOrder->shipping_city;
            method_exists($oOrder, 'get_shipping_country') ? $shipping_country = $oOrder->get_shipping_country() : $shipping_country = $oOrder->shipping_country;
            
            if ($shipping_first_name != '') {
                $oConsumer->shippingAddress()->setFirstName($shipping_first_name);
            }
            if ($shipping_last_name != '') {
                $oConsumer->shippingAddress()->setLastName($shipping_last_name);
            }
            $shipping_address = trim($shipping_address_1 . ' ' . $shipping_address_2);
            if ($shipping_address != '') {
                $oConsumer->shippingAddress()->setAddress(trim($shipping_address_1 . ' ' . $shipping_address_2));
            }
            if ($shipping_postcode != '') {
                $oConsumer->shippingAddress()->setZipCode($shipping_postcode);
            }
            if ($shipping_city != '') {
                $oConsumer->shippingAddress()->setCity($shipping_city);
            }
            if ($shipping_state != '') {
                $oConsumer->shippingAddress()->setState($shipping_state);
            }
            if ($shipping_country != '') {
                $oConsumer->shippingAddress()->setCountry($shipping_country);
            }
            
            $oCart = $oTransaction->getCart();
            $aCartItems = $this->getCartItems($iOrderId);
            
            foreach ($aCartItems as $item) {
                
                switch ($item['type']) {
                    case 'product':
                        $iItemType = \cardgate\api\Item::TYPE_PRODUCT;
                        break;
                    case 'shipping':
                        $iItemType = \cardgate\api\Item::TYPE_SHIPPING;
                        break;
                    case 'paymentfee':
                        $iItemType = \cardgate\api\Item::TYPE_HANDLING;
                        break;
                    case 'discount':
                        $iItemType = \cardgate\api\Item::TYPE_DISCOUNT;
                        break;
                    case 'correction':
                        $iItemType = \cardgate\api\Item::TYPE_CORRECTION;
                        break;
                    case 'vatcorrection':
                        $iItemType = \cardgate\api\Item::TYPE_VAT_CORRECTION;
                        break;
                }
                
                $oItem = $oCart->addItem($iItemType, $item['model'], $item['name'], (int) $item['quantity'], (int) $item['price_wt']);
                $oItem->setVat($item['vat']);
                $oItem->setVatAmount($item['vat_amount']);
                $oItem->setVatIncluded(0);
            }
            if (method_exists($oOrder, 'get_cancel_order_url_raw')) {
                $sCanceUrl = $oOrder->get_cancel_order_url_raw();
            } else {
                
                $sCanceUrl = $oOrder->get_cancel_order_url();
            }
            
            $oTransaction->setCallbackUrl(site_url() . '/index.php?cgp_notify=true');
            $oTransaction->setSuccessUrl($this->get_return_url($oOrder));
            $oTransaction->setFailureUrl($sCanceUrl);
            
            $oTransaction->setReference('O' . time() . $iOrderId);
            $oTransaction->setDescription('Order ' . $this->swap_order_number($iOrderId));
            
            $oTransaction->register();
            
            $sActionUrl = $oTransaction->getActionUrl();
            
            if (NULL !== $sActionUrl) {
                return array(
                    'result' => 'success',
                    'redirect' => trim($sActionUrl)
                );
            } else {
                $sErrorMessage = 'CardGate error: ' . htmlspecialchars($oException_->getMessage());
                wc_add_notice($sErrorMessage, 'error');
                return array(
                    'result' => 'success',
                    'redirect' => $woocommerce->cart->get_checkout_url()
                );
            }
        } catch (cardgate\api\Exception $oException_) {
            $sErrorMessage = 'CardGate error: ' . htmlspecialchars($oException_->getMessage());
            wc_add_notice($sErrorMessage, 'error');
            return array(
                'result' => 'success',
                'redirect' => $woocommerce->cart->get_checkout_url()
            );
        }
    }

    // ////////////////////////////////////////////////
    
    /**
     * Save the payment data in the database
     *
     * @param integer $iOrderId            
     */
    private function savePaymentData($iOrderId, $sParent_ID = false) {
        global $wpdb, $woocommerce;
        
        $order = new WC_Order($iOrderId);
        $payment_id = null;
        $table = $wpdb->prefix . 'cardgate_payments';
        if (empty($sParent_ID)) {
            $query = $wpdb->prepare("
                SELECT
                payment.id As id ,
                payment.order_id ,
                payment.parent_id ,
                payment.currency ,
                payment.amount ,
                payment.gateway_language ,
                payment.payment_method ,
                payment.first_name ,
                payment.last_name ,
                payment.address ,
                payment.postal_code ,
                payment.city ,
                payment.country ,
                payment.email ,
                payment.date_gmt
                FROM
                $table AS payment
                WHERE order_id = %d AND transaction_id = %s", $iOrderId, $sParent_ID);
            
            $result = $wpdb->get_row($query, ARRAY_A);
            if ($result) {
                $payment_id = $result['id'];
            }
        }
        
        $data = array(
            'order_id' => $order->id,
            'currency' => get_woocommerce_currency(),
            'amount' => $order->get_total() * 100,
            'gateway_language' => $this->getLanguage(),
            'payment_method' => $this->payment_method,
            'bank_option' => $this->bankOption,
            'first_name' => $order->billing_first_name,
            'last_name' => $order->billing_last_name,
            'address' => $order->billing_address_1,
            'postal_code' => $order->billing_postcode,
            'city' => $order->billing_city,
            'country' => $order->billing_country,
            'email' => $order->billing_email,
            'status' => 'pending',
            'date_gmt' => date('Y-m-d H:i:s')
        );
        
        $format = array(
            '%s',
            '%s',
            '%s',
            '%d',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s'
        );
        
        if ($payment_id == null || ! empty($sParent_ID)) {
            $wpdb->insert($table, $data, $format);
        } else {
            $wpdb->update($table, $data, array(
                'id' => $payment_id
            ), $format, array(
                '%d'
            ));
        }
    }

    // ////////////////////////////////////////////////
    /**
     * Collect the product data from an order
     *
     * @param integer $iOrderId            
     */
    private function getCartItems($iOrderId) {
        global $woocommerce;
        
        $items = array();
        $nr = 0;
        $iCartItemTotal = 0;
        $iCartItemTaxTotal = 0;
        
        $oOrder = new WC_Order($iOrderId);
        $iOrderTotal = round($oOrder->get_total() * 100);
        
        // any discount will be already calculated in the item total
        $aOrder_items = $oOrder->get_items();
        
        foreach ($aOrder_items as $oItem) {
            
            if (is_object($oItem)) {
                $oProduct = $oItem->get_product();
                $sName = $oProduct->get_name();
                $sModel = $this->formatSku($oProduct);
                $iQty = $oItem->get_quantity();
                $iPrice = round(($oItem->get_total() * 100) / $iQty);
                $iTax = round(($oItem->get_total_tax() * 100) / $iQty);
                $iTotal = round($iPrice + $iTax);
                $iTaxrate = ($iTax > 0 ? round((($oItem->get_total_tax() * 100) / $iQty) / (($oItem->get_total()) / $iQty), 1) : 0);
            } else {
                
                $aItem = $oItem;
                $sName = $aItem['name'];
                $sModel = 'product_' . $aItem['item_meta']['_product_id'][0];
                $oProduct = $oOrder->get_product_from_item($aItem);
                $iQty = (int) $aItem['item_meta']['_qty'][0];
                $iPrice = round(($oOrder->get_item_total($aItem, false, false) * 100));
                $iTax = round(($oOrder->get_item_tax($aItem, false) * 100));
                $iTotal = round($iPrice + $iTax);
                $iTaxrate = ($iTax > 0 ? round($oOrder->get_item_tax($aItem, false) / $oOrder->get_item_total($aItem, false, false) * 100, 1) : 0);
            }
            
            $nr ++;
            $items[$nr]['type'] = 'product';
            $items[$nr]['model'] = $sModel;
            $items[$nr]['name'] = $sName;
            $items[$nr]['quantity'] = $iQty;
            $items[$nr]['price_wt'] = $iPrice;
            $items[$nr]['vat'] = $iTaxrate;
            $items[$nr]['vat_amount'] = $iTax;
            
            $iCartItemTotal += round($iPrice * $iQty);
            $iCartItemTaxTotal += round($iTax * $iQty);
        }
        
        $iShippingTotal = 0;
        $iShippingVatTotal = 0;
        
        $aShipping_methods = $oOrder->get_shipping_methods();
        
        if (! empty($aShipping_methods) && is_array($aShipping_methods)) {
            foreach ($aShipping_methods as $oShipping) {
                if (is_object($oShipping)) {
                    $sName = $oShipping->get_name();
                    $sModel = $oShipping->get_type();
                    $iPrice = round($oShipping->get_total() * 100);
                    $iTax = round($oShipping->get_total_tax() * 100);
                    $iTotal = round($iPrice + $iTax);
                    $iTaxrate = ($iTax > 0 ? round(($oShipping->get_total_tax()/ $oShipping->get_total()) * 100, 1) : 0);
                } else {
                    $aShipping = $oShipping;
                    $sName = $aShipping['name'];
                    $sModel = 'shipping_' . $aShipping['item_meta']['method_id'][0];
                    $iPrice = round($oOrder->get_total_shipping() * 100);
                    $iTax = round($oOrder->get_shipping_tax() * 100);
                    $iTotal = round($iPrice + $iTax);
                    $iTaxrate = ($iTax > 0 ? round(($oOrder->get_shipping_tax()/ $oOrder->get_total_shipping()) * 100, 1) : 0);
                }
                $nr ++;
                $items[$nr]['type'] = 'shipping';
                $items[$nr]['model'] = $sModel;
                $items[$nr]['name'] = $sName;
                $items[$nr]['quantity'] = 1;
                $items[$nr]['price_wt'] = $iPrice;
                $items[$nr]['vat'] = $iTaxrate;
                $items[$nr]['vat_amount'] = $iTax;
                
                $iShippingTotal = $iPrice;
                $iShippingVatTotal = $iTax;
            }
        }
        
        $fpExtraFee = (empty($woocommerce->session->extra_cart_fee) ? 0 : $woocommerce->session->extra_cart_fee);
        $iExtraFee = round($fpExtraFee * 100);
        
        if ($iExtraFee > 0) {
            
            $nr ++;
            $items[$nr]['type'] = 'paymentfee';
            $items[$nr]['model'] = 'extra_costs';
            $items[$nr]['name'] = 'payment_fee';
            $items[$nr]['quantity'] = 1;
            $items[$nr]['price_wt'] = $iExtraFee;
            $items[$nr]['vat'] = 0;
            $items[$nr]['vat_amount'] = 0;
        }
        
        $iTaxDifference = round($oOrder->get_total_tax() * 100) - $iCartItemTaxTotal - $iShippingVatTotal;
        if ($iTaxDifference != 0) {
            $nr ++;
            $items[$nr]['type'] = 'vatcorrection';
            $items[$nr]['model'] = 'Correction';
            $items[$nr]['name'] = 'vat_correction';
            $items[$nr]['quantity'] = 1;
            $items[$nr]['price_wt'] = $iTaxDifference;
            $items[$nr]['vat'] = 0;
            $items[$nr]['vat_amount'] = 0;
        }
        
        $iCorrection = round($iOrderTotal - $iCartItemTotal - $iCartItemTaxTotal - $iShippingTotal - $iShippingVatTotal - $iExtraFee - $iTaxDifference);
        
        if ($iCorrection != 0) {
            
            $nr ++;
            $items[$nr]['type'] = 'correction';
            $items[$nr]['model'] = 'Correction';
            $items[$nr]['name'] = 'item_correction';
            $items[$nr]['quantity'] = 1;
            $items[$nr]['price_wt'] = $iCorrection;
            $items[$nr]['vat'] = 0;
            $items[$nr]['vat_amount'] = 0;
        }
        
        return $items;
    }

    // ////////////////////////////////////////////////
    
    /**
     * Validate Frontend Fields
     *
     * Validate payment fields on the frontend.
     *
     * @since 1.0.0
     */
    function validate_fields() {
        global $woocommerce;
        
        if ($_POST['payment_method'] == 'cardgateideal') {
            if (empty($_POST['cgp_bank_options']) || $_POST['cgp_bank_options'] == '0') {
                wc_add_notice(__(' Choose your bank first, please', 'cardgate'), 'error');
                return false;
            } else {
                $this->bankOption = $_POST['cgp_bank_options'];
            }
        } else {
            return true;
        }
    }

    // ////////////////////////////////////////////////
    
    /**
     * retrieve the Woocommerce version used
     */
    function get_woocommerce_version() {
        if (! function_exists('get_plugins'))
            require_once (ABSPATH . 'wp-admin/includes/plugin.php');
        $plugin_folder = get_plugins('/woocommerce');
        $plugin_file = 'woocommerce.php';
        
        if (array_key_exists($plugin_file, $plugin_folder)) {
            return $plugin_folder[$plugin_file]['Version'];
        } else {
            return 'unknown';
        }
    }

    // ////////////////////////////////////////////////
    private function swap_order_number($order_id) {
        global $wpdb;
        
        // swap order_id with sequetial order_id if it exists
        $tableName = $wpdb->prefix . 'postmeta';
        $qry = $wpdb->prepare("SELECT post_id, meta_value FROM $tableName WHERE  meta_key='%s' AND post_id=%s", '_order_number', $order_id);
        
        $seq_order_ids = $wpdb->get_results($qry, ARRAY_A);
        if (count($seq_order_ids) > 0) {
            foreach ($seq_order_ids as $k => $v) {
                return $v['meta_value'];
            }
        }
        return $order_id;
    }

    function getLanguage() {
        return substr(get_locale(), 0, 2);
    }

    private function formatSku($oProduct) {
        if (is_object($oProduct) && method_exists($oProduct, 'get_sku')) {
            $sSku = $oProduct->get_sku();
            
            if ($sSku == null || $sSku == '') {
                return 'SKU_' . $oProduct->get_id();
            } else {
                return $sSku;
            }
        }
        return 'SKU_UNDETERMINED';
    }
}
