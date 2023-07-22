<?php


/**
 * PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *
 * Payment Gateway flutterwave.com
 *
 * created by @foculinkstech
 *
 **/


 function flutterwave_validate_config()
 {
     global $config;
     if (empty($config['flutterwave_secret_key'])) {
         Message::sendTelegram("flutterwave payment gateway not configured");
         r2(U . 'order/package', 'w', Lang::T("Admin has not yet setup flutterwave payment gateway, please tell admin"));
     }
 }

 function flutterwave_show_config()
 {
     global $ui;
     $ui->assign('_title', 'Flutterwave - Payment Gateway');
     $ui->assign('cur', json_decode(file_get_contents('system/paymentgateway/flutterwave_currency.json'), true));
     $ui->assign('channel', json_decode(file_get_contents('system/paymentgateway/flutterwave_channel.json'), true));
     $ui->display('flutterwave.tpl');
 }


 function flutterwave_save_config()
 {
     global $admin, $_L;
     $flutterwave_secret_key = _post('flutterwave_secret_key');
     $flutterwave_currency = _post('flutterwave_currency');
     $d = ORM::for_table('tbl_appconfig')->where('setting', 'flutterwave_secret_key')->find_one();
     if ($d) {
         $d->value = $flutterwave_secret_key;
         $d->save();
     } else {
         $d = ORM::for_table('tbl_appconfig')->create();
         $d->setting = 'flutterwave_secret_key';
         $d->value = $flutterwave_secret_key;
         $d->save();
     }
     $d = ORM::for_table('tbl_appconfig')->where('setting', 'flutterwave_currency')->find_one();
     if ($d) {
         $d->value = $flutterwave_currency;
         $d->save();
     } else {
         $d = ORM::for_table('tbl_appconfig')->create();
         $d->setting = 'flutterwave_currency';
         $d->value = $flutterwave_currency;
         $d->save();
     }
     $d = ORM::for_table('tbl_appconfig')->where('setting', 'flutterwave_channel')->find_one();
    if ($d) {
        $d->value = implode(',', $_POST['flutterwave_channel']);
        $d->save();
    } else {
        $d = ORM::for_table('tbl_appconfig')->create();
        $d->setting = 'flutterwave_channel';
        $d->value = implode(',', $_POST['flutterwave_channel']);
        $d->save();
    }
     _log('[' . $admin['username'] . ']: Flutterwave ' . $_L['Settings_Saved_Successfully'], 'Admin', $admin['id']);

     r2(U . 'paymentgateway/flutterwave', 's', $_L['Settings_Saved_Successfully']);
 }

function flutterwave_create_transaction($trx, $user)
{
  global $config;
  $txref = uniqid('trx');
    $json = [
       'tx_ref' => $txref,
       'amount' => $trx['price'],
       'currency' => $config['flutterwave_currency'],
       'payment_options' => explode(',', $config['flutterwave_channel']),
       'customer' => [
           'email' => (empty($user['email'])) ? $user['username'] . '@' . $_SERVER['HTTP_HOST'] : $user['email'],
           'name' =>  $user['fullname'],
           'phonenumber' => $user['phonenumber']
       ],
       'meta' => [
           'price' => $trx['price'],
           'username' => $user['username'],
           'trxid' => $trx['id']
       ],

       'customizations' => [
           'title' => $trx['plan_name'],
           'description' => $trx['plan_name'],
       ],

       'redirect_url' => U . 'callback/flutterwave'
   ];
  // die(json_encode($json,JSON_PRETTY_PRINT));

 $result = json_decode(Http::postJsonData(flutterwave_get_server() . 'payments', $json,[
              'Authorization: Bearer ' . $config['flutterwave_secret_key'],
              'Cache-Control: no-cahe'
            ],
        ),
true);

//die(json_encode($result,JSON_PRETTY_PRINT));

if ($result['status'] == 'error') {
        Message::sendTelegram("Flutterwave payment failed\n\n" . json_encode($result, JSON_PRETTY_PRINT));
        r2(U . 'order/package', 'e', Lang::T("Failed to create transaction.\n".$result['message']));
    }
    $d = ORM::for_table('tbl_payment_gateway')
        ->where('username', $user['username'])
        ->where('status', 1)
        ->find_one();
    $d->gateway_trx_id = $txref;
    $d->pg_url_payment = $result['data']['link'];
    $d->pg_request = json_encode($result);
    $d->expired_date = date('Y-m-d H:i:s', strtotime("+ 6 HOUR"));
    $d->save();

    header('Location: ' . $result['data']['link']);
  exit();

    r2(U . "order/view/" . $d['id'], 's', Lang::T("Create Transaction Success"));


}

function flutterwave_payment_notification()
{
  global $config;
if(isset($_GET['status']))

  {
        //* check payment status
      if($_GET['status'] == 'cancelled')
      {
      // die(json_encode($txref,JSON_PRETTY_PRINT));
        Message::sendTelegram("Flutterwave Payment Cancelled: \n\n");
        r2(U . 'order/package', 'e', Lang::T("Flutterwave Payment Cancelled."));
    }
      elseif($_GET['status'] == 'successful')
    {

         $txid = $_GET['transaction_id'];
         $result = json_decode(Http::getData(flutterwave_get_server() . 'transactions/' . $txid. '/verify', [
          'Authorization: Bearer ' . $config['flutterwave_secret_key'],
          'Cache-Control: no-cahe'
        ]), true);
          //die(json_encode($result,JSON_PRETTY_PRINT));
          {
            $id = $result['data']['id'];
            $amountPaid = $result['data']['charged_amount'];
            $amountToPay = $result['data']['meta']['price'];
            $username =  $result['data']['meta']['username'];
            $trxid =  $result['data']['meta']['trxid'];
            if($amountPaid >= $amountToPay)
            {
               // die(json_encode($trxid,JSON_PRETTY_PRINT));
              // echo 'Payment successful';
               $d = ORM::for_table('tbl_payment_gateway')
               ->where('username', $username)
               ->where('status', 1)
               ->find_one();
               $d->gateway_trx_id = $id;
               $d->save();
               r2(U . 'order/view/'.$trxid.'/check');
            // r2(U . 'order/package', 's', Lang::T("Flutterwave Payment Completed."));
               exit();
                //* Continue to give item to the user
            }
            else
            {
              //  echo 'Fraud transactio detected';
                r2(U . 'order/package', 'e', Lang::T("Fraud transactions detected."));
                exit();
            }
        }
      }
   }
 }


 function flutterwave_get_status($trx, $user)
 {
     global $config;
     $trans_id = $trx['gateway_trx_id'];
     $result = json_decode(Http::getData(flutterwave_get_server() . 'transactions/' . $trx['gateway_trx_id']. '/verify', [
       'Authorization: Bearer ' . $config['flutterwave_secret_key'],
       'Cache-Control: no-cahe'
     ]), true);
  //die(json_encode($result,JSON_PRETTY_PRINT));
   if ($result['status'] == 'error') {
       r2(U . "order/view/" . $trx['id'], 'w', Lang::T("Transaction still unpaid."));
   } else if (in_array($result['status'], ['success']) && $trx['status'] != 2) {
       if (!Package::rechargeUser($user['id'], $trx['routers'], $trx['plan_id'], $trx['gateway'], 'Flutterwave')) {
           r2(U . "order/view/" . $trx['id'], 'd', Lang::T("Failed to activate your Package, please try again later."));
       }
       $trx->pg_paid_response = json_encode($result);
       $trx->payment_method = 'Flutterwave';
       $trx->payment_channel = $result['data']['payment_type'];
       $trx->paid_date = date('Y-m-d H:i:s', strtotime( $result['data']['created_at']));
       $trx->status = 2;
       $trx->save();

       r2(U . "order/view/" . $trx['id'], 's', Lang::T("Transaction successful."));
   } else if ($result['status'] == 'EXPIRED') {
       $trx->pg_paid_response = json_encode($result);
       $trx->status = 3;
       $trx->save();
       r2(U . "order/view/" . $trx['id'], 'd', Lang::T("Transaction expired."));
   } else if ($trx['status'] == 2) {
       r2(U . "order/view/" . $trx['id'], 'd', Lang::T("Transaction has been paid.."));
   }else{
       Message::sendTelegram("flutterwave_get_status: unknown result\n\n".json_encode($result, JSON_PRETTY_PRINT));
       r2(U . "order/view/" . $trx['id'], 'd', Lang::T("Unknown Command."));
   }

 }


function flutterwave_get_server()
{
    global $_app_stage;
    if ($_app_stage == 'Live') {
        return 'https://api.flutterwave.com/v3/';
    } else {
        return 'https://api.flutterwave.com/v3/';
    }
}
