<?php


$ROOTDIR    =   __DIR__."/../../../";

# Required File Includes
include_once $ROOTDIR."init.php";
include_once $ROOTDIR.'includes/functions.php';
include_once $ROOTDIR.'includes/gatewayfunctions.php';
include_once $ROOTDIR . 'includes/invoicefunctions.php';

include_once __DIR__.'/../blockchain_info/BlockchainDB.php';


use WHMCS\Database\Capsule;

$gatewaymodule = "blockchain_info";

$gateway = getGatewayVariables($gatewaymodule);
if(!$gateway['type']) {
	exit("Module Not Activated");
}


$DB = new BlockchainDB();

$q = $DB->fetch_assoc($DB->mysqlQuery('SELECT * FROM blockchain_payments WHERE address=%s AND secret=%s',
                                $_GET['address'],$_GET['secret']));
if(!q){
    exit();
}

$invoice = $DB->fetch_assoc($DB->mysqlQuery('SELECT * FROM tblinvoices WHERE id=%s',$q['invoice_id']));
if($invoice['status'] != 'Unpaid') {
    exit("*ok*");
}


if($DB->fetch_assoc($DB->mysqlQuery('SELECT transid FROM tblaccounts WHERE transid=%s',$_GET['transaction_hash']))){
	exit('*ok*');
}

$value_in_satoshi = $_GET['value'];
$value_in_btc = $value_in_satoshi / 100000000;
if($value_in_btc !== $q['amount']) {
	logTransaction($gateway['name'], $_GET, "Unsuccessful: Invalid amount received");
	exit('Invalid amount');
}

if($_GET['address'] !== $gateway['receiving_address']) {
	logTransaction($gateway['name'], $_GET, "Unsuccessful: Invalid receiving address");
	exit('Invalid receiving address');
}

$status = 'confirming';
if(!$gateway['confirmations_required'] || $_GET['confirmations'] >= $gateway['confirmations_required']) {
    $status = 'paid';
    addInvoicePayment($q['invoice_id'], $_GET['input_transaction_hash'], $invoice['total'],  0, $gatewaymodule);

    $order["orderid"] = Capsule::table('tblclients')->where('invoiceid', $q['invoice_id'])->value('id');
    $order["autosetup"] = true;
    $order["sendemail"] = true;
    $results = localAPI("acceptorder",$order,$gateway['whmcs_admin']);

    logTransaction($gateway['name'], $_GET, "Payment recieved");
    echo '*ok*';
}

$DB->mysqlQuery('UPDATE blockchain_payments SET confirmations=%s,status=%s WHERE invoice_id=%s',
		                    $_GET['confirmations'],$status,$q['invoice_id']);






