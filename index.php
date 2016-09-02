<?php

/**
* File Description: this file is to receive posts from Spark bot webhook, then translate the messages via Google translate API and send back the translated message back to the Spark room.
* @version: 2.0
* @date: 1 Sep, 2016 5:20:06 pm
* @author: Adam Kong
*/

// Get the header and body information of the webhook post from spark.
if (!function_exists("getallheaders")) {
    error_log("Your PHP version does not support getallheader() funcion, please upgrade!");
    exit(0);
}
$headerFromWebHook = getallheaders();
$spark_signature = $headerFromWebHook["X-Spark-Signature"];
$rawDataFromWebHook = file_get_contents('php://input');
$JSONDataFromWebHook = json_decode($rawDataFromWebHook, true);
$secretKey = "secretbottranslator"; // The secret string set when creating webhook.
$senderEmail = $JSONDataFromWebHook["data"]["personEmail"];

// no Bearer string before it, since we will add it later.
$sparkBotToken = "xxxxxxxxxxxxxxxxxxx";
// This header is used to interact with Spark API.
$headers = array(
    "Content-type: application/json",
    "Authorization: Bearer ".$sparkBotToken
);

// Getting the details of the bot
$rawBotDetails = getData("https://api.ciscospark.com/v1/people/me", $headers, "get", null);
$JSONBotDetails = json_decode($rawBotDetails, true);
$botEmail = $JSONBotDetails["emails"][0];
$botName = trim($JSONBotDetails["displayName"]," (bot)");

// verify the signature.
$validated = validateSecret($spark_signature, $rawDataFromWebHook, $secretKey);
if ($validated && ($senderEmail != $botEmail)) {
    
    // logging out tracking ID for debugging.
    $TrackingID = $headerFromWebHook["TrackingID"];
    error_log("TrackingID: ".$TrackingID);
    
    // This header is  used to interact with Google API.
    $emptyHeader = array();

    // Google's API key
    $googleAPIKey = "xxxxxxxxxxxxxxxxxxxxx";
    $commandName = "-help";
    $helpMessage ="Command Description: mention bot first, then input a space, write the code of the preferred language, space again and write the source sentences. [ For example: Translator en 你好,世界 ]. If you do not specify the language code, it's defaulted as English. See language codes for different languages from: https://cloud.google.com/translate/v2/translate-reference#supported_languages";
    $promptMessage = "The language of the source sentence is the same as the destination language!";
    $messageID = $JSONDataFromWebHook["data"]["id"];  
    $roomId = $JSONDataFromWebHook["data"]["roomId"];
    $langArr = array("af","sq","ar","hy","az","eu","be","bn","bs","bg","ca","ceb","ny","zh-CN","zh-TW","hr","cs","da","nl","en","eo","et","tl","fi","fr","gl","ka","de","el","gu","ht","ha","iw","hi","hmn","hu","is","ig","id","ga","it","ja","jw","kn","kk","km","ko","lo","la","lv","lt","mk","mg","ms","ml","mt","mi","mr","mn","my","ne","no","fa","pl","pt","ma","ro","ru","sr","st","si","sk","sl","so","es","su","sw","sv","tg","ta","te","th","tr","uk","ur","uz","vi","cy","yi","yo","zu");    
    $desLangCode = "en"; // defaulted to be en.
        
    // Use the message ID to retrieve the message body
    $messageData = getData("https://api.ciscospark.com/v1/messages/".$messageID, $headers, "get", null);
    $messageText = json_decode($messageData, true)["text"];
    error_log("message content: ".$messageText);
    // tailing the message to translate the valid string.
    // remove the bot name, and the space in leading and ending.
    $validMessageText = trim(substr($messageText, strlen($botName)));
    $firstWord = explode(' ',$validMessageText)[0];
    if ($firstWord === $commandName) {
        sendMessageToSpark($roomId, $headers, $helpMessage);        
    } elseif (in_array($firstWord, $langArr)) {
        $desLangCode = $firstWord;
        $sourceMessage = trim(substr($validMessageText,strlen($firstWord)));
        $sourceLangCode = detectSourceLangCode($sourceMessage, $googleAPIKey, $emptyHeader);
        if ($sourceLangCode !== $desLangCode) {
            $desLangTranslatedText = getTranslatedText($sourceMessage,$googleAPIKey,$desLangCode,$emptyHeader);
            sendMessageToSpark($roomId, $headers, $desLangTranslatedText);
        } else {
            sendMessageToSpark($roomId, $headers, $promptMessage);
        }
    } else {
        // if the source sentence is in English, and you do not specify a lang code (defaulted to en), then prompt.        
        $sourceLangCode = detectSourceLangCode($validMessageText, $googleAPIKey, $emptyHeader);
        if ($sourceLangCode === $desLangCode) {
            sendMessageToSpark($roomId, $headers, $promptMessage);
        } else {
            $desLangTranslatedText = getTranslatedText($validMessageText,$googleAPIKey,$desLangCode,$emptyHeader);
            sendMessageToSpark($roomId, $headers, $desLangTranslatedText);
        }
    }
}


/**
* Function Description: do the actual HTTP GET/POST request.
* @access:public
* @param: string $url
* @param: array $headers
* @param: string $method
* @param: string $data
* @return: string
*/
function getData($url, $headers, $method, $data) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($method === "post") {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    } else {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); //Does not verify certificate
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); //Does not verify certificate
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // assign the returned value to a varable.
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}


/**
* Function Description: validate the signature
* @access:public
* @param: string $signature
* @param: string $str
* @param: string $key
* @return: boolean
*/
function validateSecret($signature, $str, $key) {
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


/**
* Function Description: send message back to Spark room by bot's token.
* @access:public
* @param: string $roomId
* @param: array $headers
* @param: string $message
* @return: void
*/
function sendMessageToSpark($roomId, $headers, $message) {
    $message = str_replace('"', '\"', $message);
    // composing JSON
    $JSONData='{"roomId": "'.$roomId.'","text": "'.$message.'"}';
    $messageToSparkResult = getData("https://api.ciscospark.com/v1/messages", $headers, "post", $JSONData);
    error_log("sending message result: ".$messageToSparkResult);        
}


/**
* Function Description:detect the language code of the source message.
* @access:public
* @param: string $sourceMessage
* @param: string $googleAPIKey
* @param: array $header
* @return: string
*/
function detectSourceLangCode($sourceMessage, $googleAPIKey, $header) {
    $sourceLangCodeDetection = getData("https://www.googleapis.com/language/translate/v2/detect?key=".$googleAPIKey."&q=".str_replace(" ","%20",$sourceMessage), $header, "get", null);
    $sourceLangCode = json_decode($sourceLangCodeDetection, true)["data"]["detections"][0][0]["language"];
    return $sourceLangCode;
}


/**
* Function Description: get the translated sentence from GoogleAPI
* @access:public
* @param: string $sourceMessage
* @param: string $googleAPIKey
* @param: string $desLangCode
* @param: array $header
* @return: string
*/
function getTranslatedText($sourceMessage, $googleAPIKey, $desLangCode, $header) {
    $sourceMessage = str_replace(array(" ","\r","\n"), array("%20","%0A","%0A"), $sourceMessage);
    error_log("sourceMessage:".$sourceMessage);
    $desLangData = getData("https://www.googleapis.com/language/translate/v2?key=".$googleAPIKey."&q=".$sourceMessage."&target=".$desLangCode, $header, "get", null);
    $desLangTranslatedText = json_decode($desLangData, true)["data"]["translations"][0]["translatedText"];
    error_log("desLangTranslatedText:".$desLangTranslatedText);
    $desLangTranslatedText = str_replace(array("&#39;","&quot;"), array("'","\""), $desLangTranslatedText);
    return $desLangTranslatedText;
}
?>