<?php

class ControllerPaymentIpaymu extends Controller {

    public function index() {
        $this->language->load('payment/ipaymu');
        $data['action'] = $this->url->link('payment/ipaymu/send');
        $data['button_confirm'] = $this->language->get('button_confirm');

        if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/payment/ipaymu.tpl')) {
            return $this->load->view($this->config->get('config_template') . '/template/payment/ipaymu.tpl', $data);
        } else {
            return $this->load->view('default/template/payment/ipaymu.tpl', $data);
        }
    }

    private function simpleXor($string, $password) {
        $data = array();

        for ($i = 0; $i < strlen($password); $i++) {
            $data[$i] = ord(substr($password, $i, 1));
        }

        $output = '';

        for ($i = 0; $i < strlen($string); $i++) {
            $output .= chr(ord(substr($string, $i, 1)) ^ ($data[$i % strlen($password)]));
        }

        return $output;
    }

    public function send() {
        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        $data['ap_merchant'] = $this->config->get('ipaymu_merchant');
        $data['url_web'] = $this->url->link('common/home');
        $data['ap_security'] = $this->config->get('ipaymu_security');
        $data['ap_virtual_account'] = $this->config->get('ipaymu_virtual_account');
        $data['ap_ipaymu_rate'] = $this->config->get('ipaymu_rate');
        $data['ap_inv_virtual_account'] = $this->config->get('ipaymu_inv_virtual_account');
        $data['ap_amount'] = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);
        $data['ap_currency'] = $order_info['currency_code'];
        $data['ap_purchasetype'] = 'Item';
        $data['ap_itemname'] = $this->config->get('config_name') . ' - #' . $this->session->data['order_id'];
        $data['ap_itemcode'] = $this->session->data['order_id'];

        $security_code = $data['ap_security'] . $this->session->data['order_id'];

        $data = array();
        $data['orderid'] = $this->session->data['order_id'];
        $def_curr = $this->config->get('config_currency');
        $data['jumlah'] = $def_curr == 'IDR' ? $order_info['total'] : $this->currency->convert($order_info['total'], $order_info['currency_code'], 'IDR');


        $crypt_data = array();

        foreach ($data as $key => $value) {
            $crypt_data[] = $key . '=' . $value;
        }

        $data['crypt'] = base64_encode($this->simpleXor(utf8_decode(implode('&', $crypt_data)), $security_code));

        $data['ap_returnurl'] = str_replace('&amp;', '&', $this->url->link('payment/ipaymu/success', 'order_id=' . $this->session->data['order_id'] . '&crypt=' . $data['crypt']));
        $data['ap_notifyurl'] = str_replace('&amp;', '&', $this->url->link('checkout/checkout'));

        $data['ap_cancelurl'] = $this->url->link('checkout/checkout', '', 'SSL');

        $_SESSION['crypt'] = $data['crypt'];

        $url = 'https://my.ipaymu.com/payment.htm';

        // Prepare Parameters
        $pprate = isset($data['ap_ipaymu_rate']) && !empty($data['ap_ipaymu_rate']) ? $data['ap_ipaymu_rate'] : 1;
        $params = array(
            'key' => '' . $this->config->get('ipaymu_security') . '', // API Key Merchant / Penjual
            'action' => 'payment',
            'product' => 'Order #' . $data['orderid'] . '',
            'price' => '' . $data['jumlah'] . '', // Total Harga
            'quantity' => 1,
            'comments' => 'Transaksi Pembelian di ' . $_SERVER["SERVER_NAME"] . '', // Optional           
            'ureturn' => '' . $data['ap_returnurl'] . '',
            'unotify' => '' . $data['ap_notifyurl'] . '',
            'ucancel' => '' . $data['ap_cancelurl'] . '',
            /* Parameter untuk pembayaran lain menggunakan PayPal 
             * ----------------------------------------------- */
            'invoice_number' => uniqid($data['ap_inv_virtual_account']), // Optional
            'virtual_account' => $data['ap_virtual_account'],
            'virtual_account_price' => round($data['ap_amount'] / $data['ap_ipaymu_rate'], 2), // Total harga dalam kurs USD
            /* ----------------------------------------------- */
            'format' => 'json' // Format: xml / json. Default: xml 
        );


        $params_string = http_build_query($params);

        //open connection_aborted(oci_internal_debug(onoff))
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, count($params));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

        //execute post
        $request = curl_exec($ch);

        if ($request === false) {
            echo 'Curl Error: ' . curl_error($ch);
        } else {

            $result = json_decode($request, true);

            if (isset($result['url']))
                header('location: ' . $result['url']);
            else {
                echo "Request Error " . $result['Status'] . ": " . $result['Keterangan'];
            }
        }
        //close connection
        curl_close($ch);
    }

    public function success() {
        $datane = array();
        foreach ($_REQUEST as $key => $value) {
            $datane[$key] = $value;
        }

        if (isset($_SESSION['crypt'])) {
            if (isset($datane['crypt']) && ($datane['crypt'] == $_SESSION['crypt'])) {
                unset($_SESSION['crypt']);
                $this->load->model('checkout/order');

                if($datane['status'] == 'berhasil') {
                	$message = 'iPaymu with transaction id: '.$datane['trx_id'];
                	if($datane['ref_no']) {
                		$message .= ' ,ref. number: '.$datane['ref_no'];
                	}
                	$this->model_checkout_order->addOrderHistory($datane['order_id'], 15, $message);
                } elseif ($datane['status'] == 'pending'){
                	$message = 'Non Member iPaymu with transaction id: '.$datane['trx_id'];
                	$this->model_checkout_order->addOrderHistory($datane['order_id'], 1, $message);
                } elseif ($datane['status'] == 'gagal'){
                	$message = 'iPaymu with transaction id: '.$datane['trx_id'];
                	if($datane['ref_no']) {
                		$message .= ' ,ref. number: '.$datane['ref_no'];
                	}
                	$this->model_checkout_order->addOrderHistory($datane['order_id'], 10, $message);
                }
                $this->response->redirect($this->url->link('checkout/success'));
            }
        }
    }

}

?>