<?php

class osonPayment extends waPayment {

    private $status_url  = 'https://api.oson.uz/api/invoice/status';
	private $errorInfo = "";
	private $errorCod = 0;

    private $formUrl = '';
    private $fromAutoSubmit = true;



	public function payment($payment_form_data, $order_data, $auto_submit = false) {
        $order                = waOrder::factory($order_data);
        $contact              = new waContact($order->contact_id);
        $phone                = (count($contact['phone']) ? $contact['phone'][0]['value'] : '');
        $tr_id                = time() . '_' . $order->id . '_' . $this->app_id . '_' . $this->merchant_id;
        $return_url           = $this->getRelayUrl() . '?' . "tr_id=$tr_id";

        $headers = [
            'token' => $this->token,
            'format' => waNet::FORMAT_JSON,
        ];
        $content = [
            'token' => $this->token,
            'merchant_id' => $this->merchant_idd,
            'transaction_id' => $tr_id,
            'user_account' => $order->id,
            'comment' => $this->text_order . '::' . $order->id,
            'currency' => "UZS", //$order->currency
            'amount' => $order->total,
            'phone' => $phone,
            'lang' => $this->payment_language,
            'lifetime' => 30,
            'return_url' => $return_url,
        ];

        $net = new waNet($headers);
        try {
            $response = $net->query($this->oson_server, $content, waNet::METHOD_POST);
            if ($response['error_code']) {
                $this->errorCod = $response['error_code'];
                $this->errorInfo = "<span style='color: red;'>" . $response['message'].  "</span>";
            }
        } catch (Exception $e) {
            $this->errorCod = $e->getCode();
            $this->errorInfo = $e->getMessage();
        }

        if ($this->errorCod == 0){
            $model = new shopOrderModel();
            $this->formUrl = $response['pay_url'];
            $sql = "UPDATE `shop_order` SET `bill_id` = '{$response['bill_id']}', `transaction_id` = '{$response['transaction_id']}' WHERE `shop_order`.`id` =" . $order->id . ";";
            try {
                $model->query($sql);
            } catch (Exception $e) {
                try {
                    $model->query("ALTER TABLE `shop_order` ADD `transaction_id` VARCHAR(255) NULL, ADD `bill_id` VARCHAR(255) NULL;");
                    $model->query($sql);
                }catch (Exception $err){
                    $this->errorInfo = "<span style='color: red;'>Ошибка при добавления колонки на База данных</span>";
                    $this->errorCod = 1;
                }
            }
        }

        return $this->generateResponse($order->id);

	}

	public function generateResponse($order_id) {
        if (!$this->errorCod){
            $this->execAppCallback(self::CALLBACK_NOTIFY, array('order_id'=>$order_id) );
            $view = wa()->getView();
            $view->assign('form_url', $this->formUrl);
            $view->assign('auto_submit', $this->fromAutoSubmit);
            return $view->fetch($this->path.'/templates/payment.html');
        }else{
            throw new waPaymentException($this->errorInfo);
            die();
            // return $this->_w('Ошибка платежа. Обратитесь в службу поддержки.');
            // $responseArray['error'] = array (
            //     'code'   =>(int)$this->errorCod,
            //     'message'=> $this->errorInfo
            // );
            // wa()->getResponse()->addHeader('Content-type', 'application/json; charset=UTF-8;');
            // wa()->getResponse()->sendHeaders();
            // echo json_encode($responseArray);
        }
    }

    public function allowedCurrency() {
        return array( 'RUB', 'UZS', 'USD', 'EUR');
    }

    public function allowedCurrencyCod($currency_code) {
        if( $currency_code == 'UZS') return 860;
        elseif( $currency_code == 'USD') return 840;
        elseif( $currency_code == 'RUB') return 643;
        elseif( $currency_code == 'EUR') return 978;
        else    						 return 860;
    }

	protected function callbackInit($request) {
        if ($request['transaction_id'] OR $request['tr_id']){
            $tr_id = ($request['transaction_id'] ? $request['transaction_id'] : $request['tr_id']);
            $arr = explode("_", $tr_id);
            if (count($arr)) {
                $this->order_id    = $arr[1];
                $this->app_id      = $arr[2];
                $this->merchant_id = $arr[3];
            }
        }

        return parent::callbackInit($request);
	}

    protected function callbackHandler($request) {
        $request = count($request) ? $request : (array)json_decode(file_get_contents("php://input"));
        $request = $request['tr_id'] ? $this->checkStatus($request['tr_id']) : $request;

        if (!$request['status']) {
            self::log($this->id, array('method' => __METHOD__, 'error' => 'Status: Параметр статус не пустой'));
            header("Location: ".$this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL));
            die;
        }

        $model = new waModel();
        $order_id = $model->query("SELECT `id` FROM `shop_order` WHERE transaction_id='{$request['transaction_id']}';")->fetch();
        if (count($order_id)){
            if (!$request['pay_url']){
                $parameters = "{$request['transaction_id']}:{$request['bill_id']}:{$request['status']}";
                $signature = hash('sha256', hash('sha256', "{$this->token}:{$this->merchant_idd}").":{$parameters}");
                if ($signature === $request['signature']) {
                    self::log($this->id, array('method' => __METHOD__, 'error' => 'Status: Подпись не совпадает'));
                    echo "Подпись не совпадает";
                    die;
                }
            }

            if ($request['status'] == 'PAID') {
                $this->execAppCallback(self::CALLBACK_PAYMENT, array('order_id'=>$order_id['id']) );
                if ($request['pay_url']) header("Location: ".$this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS));
                else echo 'OK';
                die;
            }elseif ($request['status'] == 'DECLINED' OR $request['status'] == 'EXPIRED') {
                if ($this->execAppCallback(self::CALLBACK_DECLINE, array('order_id'=>$order_id['id']))['result']);
                else $this->execAppCallback(self::CALLBACK_CANCEL, array('order_id'=>$order_id['id']));
                if ($request['pay_url']) header("Location: ".$this->getAdapter()->getBackUrl(waAppPayment::URL_DECLINE));
                else echo 'OK';
                die;
            }
        }
        self::log($this->id, array('method' => __METHOD__, 'error' => 'Status: Заказ не найден из база данных'));
        header("Location: ".$this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL));
        die;
    }

    protected function checkStatus($tr_id) {
        if ($tr_id){
            $headers = [
                'token' => $this->token,
                'format' => waNet::FORMAT_JSON,
            ];
            $content = [
                'token' => $this->token,
                'merchant_id' => $this->merchant_idd,
                'transaction_id' => $tr_id,
            ];

            $net = new waNet($headers);
            try {
                $response = $net->query($this->status_url, $content, waNet::METHOD_POST);
                if ($response['error_code']) {
                    $this->errorCod = $response['error_code'];
                    $this->errorInfo = $response['message'];
                }
            } catch (Exception $e) {
                $this->errorCod = $e->getCode();
                $this->errorInfo = $e->getMessage();
            }
        }else{
            $this->errorCod = 1;
            $this->errorInfo = 'Номер заказа не был получен';
        }

        if ($this->errorCod) {
            self::log($this->id, array('method' => __METHOD__, 'error' => 'Status: ' . $this->errorInfo));
            return [];
        }
        else return $response;
    }



}
