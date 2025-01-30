<?php
if (!defined('PAYMENT')) {
    http_response_code(404);
    die();
}

$errors = [];

// Validate required fields
if (!isset($_REQUEST['amount']) || empty($_REQUEST['amount']) || !is_numeric($_REQUEST['amount'])) {
    $errors[] = 'Amount is required and must be a numeric value.';
}

if (!isset($_REQUEST['cus_name']) || empty($_REQUEST['cus_name'])) {
    $errors[] = 'Customer name is required.';
}

if (!isset($_REQUEST['cus_email']) || empty($_REQUEST['cus_email']) || !filter_var($_REQUEST['cus_email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Customer email is required and must be a valid email address.';
}

if (!isset($_REQUEST['cus_number']) || empty($_REQUEST['cus_number'])) {
    $errors[] = 'Customer number is required.';
}

if (!isset($_REQUEST['method_transaction_id']) || empty($_REQUEST['method_transaction_id'])) {
    $errors[] = 'Transaction ID is required.';
}

if (!isset($_REQUEST['status']) || empty($_REQUEST['status'])) {
    $errors[] = 'Status is required.';
}

// Return errors if any
if (!empty($errors)) {
    echo json_encode(['status' => 'error', 'errors' => $errors]);
    exit;
}

$chargeCode = $_REQUEST['cus_number'] ?? null;

$paymentDetails = $conn->prepare("SELECT * FROM payments WHERE payment_extra = :chargeCode");
$paymentDetails->execute(["chargeCode" => $chargeCode]);

if (!$paymentDetails->rowCount()) {
    echo json_encode(['status' => 'error', 'message' => 'Transaction Not Found']);
    exit;
}

$paymentData = $paymentDetails->fetch(PDO::FETCH_ASSOC);

if ($_REQUEST['status'] == 'Completed') {
    $api_key = $methodExtras["apikey"] ?? null;
    $trx_id = $_REQUEST['trx_id'];

    // Use cURL instead of file_get_contents() for better handling
    $url = "https://tzsmmpay.com/api/payment/verify?api_key={$api_key}&trx_id={$trx_id}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $http_status !== 200) {
        echo json_encode(['status' => 'error', 'message' => 'Error verifying transaction.']);
        exit;
    }

    $result = json_decode($response, true);

    if (isset($result['status']) && $result['status'] == 'Completed') {
        // Fetch user balance before updating
        $stmt = $conn->prepare("SELECT balance FROM clients WHERE client_id = :id");
        $stmt->execute(["id" => $paymentData["client_id"]]);
        $userBalance = floatval($stmt->fetchColumn());

        $paidAmount = floatval($paymentData["payment_amount"]);


        // Apply fee deduction
        if ($paymentFee > 0) {
            $fee = ($paidAmount * ($paymentFee / 100));
            $paidAmount -= $fee;
        }

        // Apply bonus if applicable
        if ($paymentBonusStartAmount != 0 && $paidAmount > $paymentBonusStartAmount) {
            $bonus = $paidAmount * ($paymentBonus / 100);
            $paidAmount += $bonus;
        }

        // Currency conversion function (assuming `from_to` function exists)
        $paidAmount = from_to($currencies_array, $methodCurrency, $settings["site_base_currency"], $paidAmount);

        // Update payments table
        $update = $conn->prepare('UPDATE payments SET 
            client_balance = :balance,
            payment_status = :status, 
            payment_delivery = :delivery,
            t_id = :tid 
            WHERE payment_id = :id');
        $update->execute([
            'balance' => $userBalance,
            'status' => 3,
            'delivery' => 2,
            'tid' => $trx_id,
            'id' => $paymentData['payment_id']
        ]);

        // Update client balance
        $newBalance = $userBalance + $paidAmount;
        $balance = $conn->prepare('UPDATE clients SET balance = :balance WHERE client_id = :id');
        $balance->execute([
            "balance" => $newBalance,
            "id" => $paymentData["client_id"]
        ]);

        echo json_encode(['status' => 'success', 'message' => 'Payment verified and balance updated.']);
        exit;
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Transaction verification failed.', 'response' => $result]);
        exit;
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid payment status: ' . $_REQUEST['status']]);
    exit;
}
?>
