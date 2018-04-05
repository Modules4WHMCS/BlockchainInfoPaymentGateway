<?php

// Define module version for release build tool
//MODULE_VERSION,'1.0';




# Required File Includes
$ROOTDIR = __DIR__."/../../";

include_once $ROOTDIR."init.php";
include_once $ROOTDIR.'includes/functions.php';
include_once $ROOTDIR.'includes/gatewayfunctions.php';
include_once $ROOTDIR . 'includes/invoicefunctions.php';

include_once __DIR__.'/blockchain_info/BlockchainDB.php';

use WHMCS\Database\Capsule;

if($_GET['invoice']) {
    $gateway = getGatewayVariables('blockchain_info');
?>
<!doctype html>
<html>
	<head>
		<title>Blockchain.info Invoice Payment</title>
		<script src="//ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js"></script>
		<script src="blockchain/jquery.qrcode.js"></script>
                <script src="blockchain/qrcode.js"></script>
		<script type="text/javascript">
		function checkStatus() {
			$.get("blockchain_info.php?checkinvoice=<?php echo $_GET['invoice']; ?>", function(data) {
				if(data == 'paid') {
					parent.location.href = '<?php echo $gateway['systemurl']; ?>/viewinvoice.php?id=<?php echo $_GET['invoice']; ?>';
				} else if(data == 'unpaid') {
					setTimeout(checkStatus, 5000);
				} else {
					$("#content").html("Transaction confirming... " + data + "/<?php echo $gateway['confirmations_required']; ?> confirmations");
					setTimeout(checkStatus, 10000);
				}
			});
		}
		</script>
		<style>
		body {
			font-family:Tahoma;
			font-size:12px;
			text-align:center;
		}
		a:link, a:visited {
			color:#08c;
			text-decoration:none;
		}
		a:hover {
			color:#005580;
			text-decoration:underline
		}
		</style>
	</head>
	<body onload="checkStatus()">
		<p id="content"><center><div id="qrcodeCanvas"></div></center><br><br>
        <?php
            echo blockchain_info_get_frame();
        ?>
        </p>
	</body>
    </html>

<?php
}
elseif($_GET['checkinvoice']) {
	header('Content-type: text/plain');
	$DB = new BlockchainDB();
	$q = $DB->fetch_assoc($DB->mysqlQuery('SELECT * FROM blockchain_payments WHERE invoice_id=%s',
                                    $_GET['checkinvoice']));
	if($q['status'] == 'paid'){
		echo 'paid';
	} elseif($q['status'] == 'confirming') {
		echo $q['confirmations'];
	} else {
		echo 'unpaid';
	}
}

return;


function blockchain_info_config()
{
    return array(
        "FriendlyName" => array("Type" => "System", "Value" => "BitCoin (Blockchain.info)"),
        "confirmations_required" => array("FriendlyName" => "Confirmations Required", "Type" => "text",
            "Size" => "4",
            "Description" => "Number of confirmations required before an invoice is marked 'Paid'."),
        "v2apikey" => array("FriendlyName" => "API Key", "Type" => "text", "Size" => "64",
            "Description" => "Blockchain.info V2 API Key"),
        "xpubkey" => array("FriendlyName" => "xPub Key", "Type" => "text", "Size" => "64",
            "Description" => "Blockchain.info V2 API xPub Key"),
        "licensekey" => array("FriendlyName" => "License Key", "Type" => "text", "Size" => "30")
    );
}

function blockchain_info_link($params)
{

    $DB = new BlockchainDB();
    $DB->mysqlQuery("CREATE TABLE IF NOT EXISTS blockchain_payments 
			(invoice_id int(11) NOT NULL, amount float(11,8) NOT NULL, address varchar(64) NOT NULL, 
			  secret varchar(64) NOT NULL, confirmations int(11) NOT NULL DEFAULT 0, 
			  status enum('unpaid','confirming','paid') NOT NULL DEFAULT 'unpaid', 
			PRIMARY KEY (invoice_id))");

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://blockchain.info/tobtc?currency={$params['currency']}&value={$params['amount']}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $amount = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if($status >= 300 || $amount < 0.0005) { // Blockchain.info will only relay a transaction if it's 0.0005 BTC or larger
        return "Transaction amount too low. Please try another payment method or open a ticket with Billing.";
    }

    $secret = '';
    $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
    for($i = 0; $i < 64; $i++) {
        $secret .= substr($characters, rand(0, strlen($characters) - 1), 1);
    }

    $callback_url = urlencode($params['systemurl'] . "/modules/gateways/callback/blockchain_info.php?secret=$secret");
    $gateway = getGatewayVariables('blockchain_info');

    $ch = curl_init();

    $url="https://api.blockchain.info/v2/receive?xpub={$gateway['xpubkey']}&callback=$callback_url&key={$gateway['v2apikey']}";
    curl_setopt($ch, CURLOPT_URL,$url );
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if($status >= 300) {
        return "An error has occurred, please contact Billing or choose a different payment method.";
    }

    $response = json_decode($response);

    if(!$response->address) {
        return "An error has occurred, please contact Billing or choose a different payment method.";
    }

    $DB->mysqlQuery('INSERT INTO blockchain_payments SET invoice_id=%s, 
				            amount=%s,address=%s,secret=%s',
        $params['invoiceid'],$amount,$response->address,$secret);

    return "<iframe src='{$params['systemurl']}/modules/gateways/blockchain_info.php?invoice={$params['invoiceid']}' 
                style='border:none; height:450px;width:350px;float:right;margin-top:50px;'>
                Your browser does not support frames.</iframe>";
}

function blockchain_info_get_frame()
{
    $gateway = getGatewayVariables('blockchain_info');
    $DB = new BlockchainDB();

    $q = $DB->fetch_assoc($DB->mysqlQuery('SELECT * FROM blockchain_payments WHERE invoice_id=%s',
                                    $_GET['invoice']));
    if(!$q['address']) {
        return "An error has occurred, please contact Billing or choose a different payment method.";
    }

    // QR code string for BTC wallet apps
    $qr_string = "bitcoin:{$q['address']}?amount={$q['amount']}&label=" . urlencode($gateway['companyname'] .
            ' Invoice #' . $q['invoice_id']);

    return "<script>jQuery('#qrcodeCanvas').qrcode({ text : '{$qr_string}'});</script>Please send <b>
                <a href='bitcoin:{$q['address']}?amount={$q['amount']}&label=" .
                urlencode($gateway['companyname'] . ' Invoice #' . $q['invoice_id']) . "'>
                {$q['amount']} BTC</a></b> to address:<br /><br /><b>
                <a href='https://blockchain.info/address/{$q['address']}' target='_blank'>{$q['address']}</a></b>
                <br /><br /><img src='" . $gateway['systemurl'] . "/modules/gateways/blockchain_info/loading.gif' />";
}






