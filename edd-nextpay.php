<?php
/**
 * Created by NextPay.ir
 * Created by NextPay.ir
 * author: Nextpay Company
 * ID: @nextpay
 * Date: 09/22/2016
 * Time: 5:05 PM
 * Website: NextPay.ir
 * Email: info@nextpay.ir
 * @copyright 2016
 * @package NextPay_Gateway
 * @version 1.0
 * Plugin Name: Nextpay payment for EDD
 * Plugin URI: http://www.nextpay.ir
 * Description: درگاه پرداخت <a href="http://nextpay.ir">نکست پی</a> را به EDD اضافه میکند
 * Version: 1.0
 * Author URI: http://www.nextpay.ir
*/

if (!function_exists('edd_rial')) {

    function edd_rial($formatted, $currency, $price) {
        return $price . ' ریال';
    }

}
add_filter('edd_rial_currency_filter_after', 'edd_rial', 10, 3);
@session_start();

function np_edd_rial($formatted, $currency, $price) {
    return $price . 'ریال';
}

function add_nextpay_gateway($gateways) {
    $gateways['nextpay'] = array(
        'admin_label' => 'نکست پی',
        'checkout_label' => 'درگاه نکست پی'
    );

    return $gateways;
}

add_filter('edd_payment_gateways', 'add_nextpay_gateway');

function np_cc_form() {
    return;
}

add_action('edd_nextpay_cc_form', 'np_cc_form');

function np_process($purchase_data) {
    global $edd_options;

    $payment_data = array(
        'price' => $purchase_data['price'],
        'date' => $purchase_data['date'],
        'user_email' => $purchase_data['post_data']['edd_email'],
        'purchase_key' => $purchase_data['purchase_key'],
        'currency' => $edd_options['currency'],
        'downloads' => $purchase_data['downloads'],
        'cart_details' => $purchase_data['cart_details'],
        'user_info' => $purchase_data['user_info'],
        'status' => 'pending'
    );
    $payment = edd_insert_payment($payment_data);

    if ($payment) {
        delete_transient('edd_nextpay_record');
        set_transient('edd_nextpay_record', $payment);

        $_SESSION['edd_nextpay_record'] = $payment;
        $callback = add_query_arg('verify', 'nextpay', get_permalink($edd_options['success_page']));
        $currency = isset( $edd_options['currency'] ) ? $edd_options['currency'] : 'IRR';
        if($currency == 'IRR'){
            $amount = intval($payment_data['price']) / 10;
        }else{
            $amount = intval($payment_data['price']) ;
        }
        $order_id = time();
        $api_key = $edd_options['api_key'];

        include_once "nextpay_payment.php";
        $data = array(
            'api_key' => $api_key,
            'order_id' => $order_id,
            'amount' => $amount,
            'callback_uri' => $callback
        );
        $nextpay = new Nextpay_Payment($data);
        $result = $nextpay->token();
        edd_insert_payment_note($payment, 'کد پاسخ نکست پی: ' . $result->code . ' و کد پرداخت: ' . $result->trans_id);
        if(intval($result->code) == -1) {
            //$nextpay->send($result->trans_id);
            $trans_id = $result->trans_id;
            $nextpay_paymentpage = $nextpay->request_http . "/$trans_id";
            wp_redirect($nextpay_paymentpage);
            exit;
        } else {
            wp_die('خطای ' . $result->code . ': در اتصال به درگاه پرداخت مشکلی پیش آمد');
            exit;
        }
    } else {
        edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
    }
}

add_action('edd_gateway_nextpay', 'np_process');

function np_verify() {
    global $edd_options;
    if (isset($_GET['verify']) && $_GET['verify'] == 'nextpay' && isset($_POST['trans_id'])) {

        $trans_id = $_POST['trans_id'];
        $order_id = $_POST['order_id'];


        $payment_id = $_SESSION['edd_nextpay_record'];
        // get_transient( 'edd_nextpay_record' );
        //delete_transient( 'edd_nextpay_record' );

        if (strlen($trans_id) > 32 && strpos($trans_id, '-') !== false) {

            include_once ("nextpay_payment.php");
            $currency = isset( $edd_options['currency'] ) ? $edd_options['currency'] : 'IRR';
            if($currency == 'IRR'){
                $Amount = intval(edd_get_payment_amount($payment_id)) / 10;
            }else{
                $Amount = intval(edd_get_payment_amount($payment_id)) ;
            }
            $nextpay = new Nextpay_Payment();
            $Api_key = $edd_options['api_key'];
            //$nextpay->setApiKey($Api_key);
            //$nextpay->setTransId($trans_id);
            //$nextpay->setAmount($Amount);
            $result = $nextpay->verify_request(array("api_key"=>$Api_key,"order_id"=>$order_id,"amount"=>$Amount,"trans_id"=>$trans_id));
            edd_empty_cart();
            if(intval($result) == 0) {
                //update_post_meta( $payment, '_edd_payment_ppalrefnum',$Refnumber);
                edd_insert_payment_note($payment_id, 'نتیجه بازگشت: وضعیت: ' . $result . ' و کد پرداخت: ' . $trans_id);
                edd_update_payment_status($payment_id, 'publish');
                edd_send_to_success_page();
            } else {
                edd_update_payment_status($payment_id, 'failed');
                wp_redirect(get_permalink($edd_options['failure_page']));
            }
            exit;
        } else {
            edd_update_payment_status($payment_id, 'revoked');
            wp_redirect(get_permalink($edd_options['failure_page']));
            exit;
        }
    }
}

add_action('init', 'np_verify');

function np_settings($settings) {
    $nextpay_options = array(
        array(
            'id' => 'nextpay_settings',
            'type' => 'header',
            'name' => '<a target="_blank" href="http://nextpay.ir/">سایت سازنده پلاگین</a>'
        ),
        array(
            'id' => 'api_key',
            'type' => 'text',
            'name' => 'کلید مجوزدهی',
            'desc' => 'کلید مجوزدهی که از نکست پی دریافت نموده اید را وارد نمایید'
        )
    );

    return array_merge($settings, $nextpay_options);
}

add_filter('edd_settings_gateways', 'np_settings');
