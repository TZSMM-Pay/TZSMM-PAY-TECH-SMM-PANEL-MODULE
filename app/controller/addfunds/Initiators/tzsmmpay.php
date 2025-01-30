<?php
if (!defined('ADDFUNDS')) {
    http_response_code(404);
    die();
}

$APIKey = $methodExtras["apikey"];
$orderId = md5(uniqid(rand(), true));

$insert = $conn->prepare(
    "INSERT INTO payments SET
    client_id=:client_id,
    payment_amount=:amount,
    payment_method=:method,
    payment_mode=:mode,
    payment_create_date=:date,
    payment_ip=:ip,
    payment_extra=:extra"
);

$insert->execute([
    "client_id" => $user["client_id"],
    "amount" => $paymentAmount,
    "method" => $methodId,
    "mode" => "Automatic",
    "date" => date("Y-m-d H:i:s"), // Fixed date format
    "ip" => GetIP(),
    "extra" => ''
]);

$endpoint = 'https://tzsmmpay.com/api/payment/create';

$params = [
    'api_key'     => $APIKey,
    'cus_name'    => $user["name"] ?? 'No Name', // Fallback if name is missing
    'cus_email'   => $user["email"] ?? 'noemail@example.com', // Fallback if email is missing
    'cus_number'  => $orderId, // Use order ID instead of phone number
    'amount'      => number_format($paymentAmount ?? 0, 2, '.', ''), // Ensure valid amount
    'currency'    => $methodCurrency ?? 'USD', // Default currency
    'success_url' => site_url("addfunds") . '?success=Payment Success',
    'cancel_url'  => site_url("addfunds") . '?error=Payment Failed',
    'callback_url'=> site_url("payment/" . ($methodCallback ?? 'default_callback')),
    'redirect'    => 'true' // Auto redirect
];

// Build the plain-text GET request URL
$paymentUrl = $endpoint . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

// Save transaction ID in session for tracking (Optional)
$_SESSION["tzsmmPayTransactionID"] = $orderId;

$redirectForm .= '<script type="text/javascript">
window.location.href = "' . $paymentUrl . '";
</script>';

$response["success"] = true;
$response["message"] = "Your payment has been initiated and you will now be redirected to the payment gateway.";
$response["content"] = $redirectForm;
