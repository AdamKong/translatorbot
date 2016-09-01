<?php

// Get the header and body information of the webhook post from spark.
if (!function_exists("getallheaders")) {
    error_log("Your PHP version does not support getallheader() funcion, please upgrade!");
    exit(0);
}
$headerFromWebHook = getallheaders();
$spark_signature = $headerFromWebHook["X-Spark-Signature"];
$TrackingID = $headerFromWebHook["TrackingID"];
error_log("TrackingID: ".$TrackingID); // merely for debugging purpose
$rawDataFromWebHook = file_get_contents('php://input');
$JSONDataFromWebHook = json_decode($rawDataFromWebHook, true);
$secretKey = "secretbottranslator"; // The secret string set when creating webhook.
$senderEmail = $JSONDataFromWebHook["data"]["personEmail"];
$botEmail = "my_translator@sparkbot.io";
$validated = validateSecret($spark_signature, $rawDataFromWebHook, $secretKey);

if ($validated && ($senderEmail != $botEmail)) {

    // no Bearer string before it, since we will add it later.
    $sparkBotToken = "xxxxxxxxxx";
    // Google's API key
    $botName = "Translator";
    $googleAPIKey = "xxxxxxxxxx";
    $messageID = $JSONDataFromWebHook["data"]["id"];  
    $roomId = $JSONDataFromWebHook["data"]["roomId"];  
    $sourceLang = "";
    $desLang = "en";
    
    // This header is used to interact with Spark API.
    $headers = array(
        "Content-type: application/json",
        "Authorization: Bearer ".$sparkBotToken
    );
    // This header is  used to interact with Google API.
    $emptyHeader = array();
    
    // Use the message ID to retrieve the message body
    $messageData = getData("https://api.ciscospark.com/v1/messages/".$messageID, $headers, "get", null);
    $messageText = json_decode($messageData, true)["text"];
    // logging out the message content, for debugging.
    error_log("message content: ".$messageText);
    // tailing the message to translate the valid string.
    // remove the bot name, and the space in leading and ending.
    $validMessageText = trim(substr($messageText, strlen($botName)));
    // logging out the valid message text for tranlation.
    error_log("validMessageText:".$validMessageText);
    
    
    // Use the valid message body to send request to Google to detect the language
    $sourceLangDetection = getData("https://www.googleapis.com/language/translate/v2/detect?key=".$googleAPIKey."&q=".str_replace(" ","%20",$validMessageText), $emptyHeader, "get", null);
    $sourceLang = json_decode($sourceLangDetection, true)["data"]["detections"][0][0]["language"];
    // logging out the language of source string.
    error_log("source language: ".$sourceLang);
   
    if ($sourceLang != "en") {
                
        // Use the message body and source language to send request to Google to get the translated sentence.
        $desLangData = getData("https://www.googleapis.com/language/translate/v2?key=".$googleAPIKey."&q=".str_replace(" ","%20",$validMessageText)."&source=".$sourceLang."&target=".$desLang, $emptyHeader, "get", null);
        $desLangTranslated = json_decode($desLangData, true)["data"]["translations"][0]["translatedText"];
        // logging out the translated text.
        error_log("des language: ".$desLangTranslated);

        
        // Use bot to send the message to Spark room
        $dataBackToSpark='{"roomId": "'.$roomId.'","text": "'.$desLangTranslated.'"}';
        $messageToSparkFromBot = getData("https://api.ciscospark.com/v1/messages", $headers, "post", $dataBackToSpark);
        // logging out sending result for debugging.
        error_log("sending message result: ".$messageToSparkFromBot);   
    }
}

// Do the actual http GET/POST request
function getData($url, $headers, $method, $data) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); // Here we need to use CURLOPT_HTTPHEADER (which is used to set an array into header) instead of CURLOPT_HEADER.
    if ($method === "post") {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    } else {
        curl_setopt($ch, CURLOPT_HTTPGET, true); // GET method is used by default.
    }
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); //Does not verify certificate
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); //Does not verify certificate
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // assign the returned value to a varable.
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}


// Validate secret
function validateSecret($signature, $str, $key) {
    // verify if the hash_hmac function exists.
    if (function_exists("hash_hmac")) {
        // generate HMAC-SHA1 signature using the JSON and key.
        $generatedSignature = hash_hmac( 'sha1', $str, $key);
    } else {
        error_log("hash_hmac function does not exist in your php installation! Please upgrade your PHP version!");
        return false;
    }        
    if ($signature === $generatedSignature) {
        return true;
    } else {
        return false;
    }
}

?>