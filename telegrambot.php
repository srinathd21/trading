<?php

$botToken = "8699836334:AAGoNX3xd5N4M1prbC1MWk_9WZJC6yuQSbk";

$chatIds = ["1104815868", "1137437725"]; // multiple users

$message = "Hello! Chandru is a paithiyakaran.";

$url = "https://api.telegram.org/bot$botToken/sendMessage";

foreach ($chatIds as $chatId) {

    $data = [
        'chat_id' => $chatId,
        'text' => $message
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

    // ✅ Optional: remove SSL error (only if still facing issue)
    // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);

    if (curl_error($ch)) {
        echo "Error for Chat ID $chatId: " . curl_error($ch) . "<br>";
    } else {
        echo "Message sent to $chatId <br>";
    }

    curl_close($ch);
}

?>