<?php
// tools/send-random-smsapi-callback.php

$endpoint = 'https://81ed-185-237-159-163.ngrok-free.app/smsapi-callback.php';

$numbers = [

    '48500100299',
    //'48500900900',
];
$fromNumbers = [
    '48506502706',
    //'48500100211',
    //'48500900900',
];
$messages = [
    'Testowa wiadomość A',
    'Wiadomość testowa B',
    'SMS z losową treścią',
    'Przykładowy tekst',
    'Hello from SMSAPI!',
    'Random message: ' . rand(1000, 9999),
];

$to = $numbers[array_rand($numbers)];
$from = $fromNumbers[array_rand($fromNumbers)];
$message = $messages[array_rand($messages)];
$smsDate = time();
$username = 'testuser@example.com';

$postFields = [
    'sms_to' => $to,
    'sms_from' => $from,
    'sms_text' => $message,
    'sms_date' => $smsDate,
    'username' => $username,
];

$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Output result
if ($httpCode === 200) {
    echo "[OK] Sent: ".$message."\n";
    echo "Response: ".$response."\n";
} else {
    echo "[ERROR] HTTP $httpCode\n";
    echo "Response: ".$response."\n";
} 