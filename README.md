# Spark Bot of Translation Master

Thinking you have business partners from many different countries, and they only say their own language – English, German, Vietnamese, Chinese, Japenese and so on. How do you overcome the language barrier and communicate with them freely via IM? Now this is resolved by Cisco Spark + Google. The solution is the translation bot, which can translate easily between any two kinds of languages and show up the translated sentences to each other. This blog will walk  you through using the Spark bot feature and Google Translate API to build such a translation bot.


First of all, you will need to build a Spark bot from https://developer.ciscospark.com/add-app.html . Please click the “Create a Bot” in the right, and it will lead you to the page where you can follow to create a bot. Note please do not use the space character in the “Display Name” field since it would only show up the first word when it’s mentioned, and words after a space character will be ignored in a room. 


Secondly, you need to use the bot’s token to create a webhook (https://developer.ciscospark.com/endpoint-webhooks-post.html) so that Spark Cloud can send a notification post to your web server once the bot is involved, i.e. when it’s mentioned. You can just leave the “filter” field empty in order that the webhook can be triggered by bot involvements in any room. If you do not have a public web server, you can use tunnlr (https://www.tunnlr.com) or ngrok (https://ngrok.com/) to give your local web server a public address.


Thirdly, you need to add the bot by its email address (you set in “Bot Username” field when creating the bot) to the room where you want to chat with your partner, by either API (https://developer.ciscospark.com/endpoint-memberships-post.html) or Spark client UI (choose the room, then click the “people” icon, then “Add People”). Note you do need to have the appropriate permissions to add a person (an account) to a room – if you get any failure via API or no place to add people, then you might need the “Moderator” role of the room to do it.


Fourthly, create an account on Google Cloud platform https://cloud.google.com/translate/, enable the Translate API and get an API key. There are plenty of instructions for how to do it on the Google site, so I won’t introduce more on this here. The quickstart doc of how to detect the language code of  a sentence and how to get the translated sentence is https://cloud.google.com/translate/v2/quickstart . Those are basically HTTP GET with parameters and expecting results back, so I won’t say more here, either.


Now when the bot is mentioned in a room, Spark will send a post to your web server. In your web server side, you need to do the below things:


1. Extract the “X-Spark-Signature” header, to verify the request. The below is my PHP script to do the verification:

	function validateSecret($signature, $str, $key) {
		if (function_exists("hash_hmac")) {
			// generate HMAC-SHA1 signature using the JSON and key.
			$generatedSignature = hash_hmac('sha1', $str, $key);
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

We have a blog specifically for how to use a webhook secret to verify Spark POST, and you can find it from - https://developer.ciscospark.com/blog/blog-details-8123.html


2. Extract the “personEmail”. If the personEmail is bot’s email (means the message is from the bot itself), then no translation. Otherwise start translation, if the #1 is good.

	$rawDataFromWebHook = file_get_contents('php://input');
	$JSONDataFromWebHook = json_decode($rawDataFromWebHook, true);
	$senderEmail= $JSONDataFromWebHook["data"]["personEmail"];
	// Getting the details of the bot
	$rawBotDetails = getData("https://api.ciscospark.com/v1/people/me", $headers, "get", null);
	$JSONBotDetails = json_decode($rawBotDetails, true);
	$botEmail = $JSONBotDetails["emails"][0];

	if ($validated && ($senderEmail != $botEmail)) {	
		processing further
	} else {
		stop, since this is a message from the bot itself – we only translate messages from a person. If the bot translates its own messages it would drop into a loop.
	}

The gateDate() is an encapsulated funtion for doing the actual HTTP GET/POST, so easy enough to understand:

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


3. Extract the message ID and room ID. 

	$messageID = $JSONDataFromWebHook["data"]["id"];  
	$roomId = $JSONDataFromWebHook["data"]["roomId"];


4. Use the message ID to retrieve the message content from Spark Could. This is basically a HTTP GET to Spark Cloud.

	$messageData = getData("https://api.ciscospark.com/v1/messages/".$messageID, $headers, "get", null);
	$messageText = json_decode($messageData, true)["text"];

	
5. Tailor the message content. Remove the bot’s name, recognize the destination language code specified, get the desired sentences which need to be translated.

	$validMessageText = trim(substr($messageText, strlen($botName)));
	$firstWord = explode(' ',$validMessageText)[0];
	$sourceMessage = trim(substr($validMessageText,strlen($firstWord)));


6. Based on the command in the message content, do the needful. If the command is “–help”, then it posts a helping message to the room. If the command is one of the language codes in https://cloud.google.com/translate/v2/translate-reference#supported_languages, or not set (it’s defaulted to “en” as the destination language code), then send the sentences to Google API to detect the source language code. If the source language code is the same as the destination language code, then no need to translate, otherwise do the translation. 

	if ($firstWord === $commandName) { 
		// If the first Word is the “-help”
		sending helping message to spark        .
	} elseif (in_array($firstWord, $langArr)) {
		// If the first word is a language code,
		// judge if it’s the same as the langeuage code
		// of the source message
		$desLangCode = $firstWord;
		$sourceMessage = trim(substr($validMessageText,strlen($firstWord)));
		$sourceLangCode = detectSourceLangCode($sourceMessage, $googleAPIKey, $emptyHeader);
		if ($sourceLangCode !== $desLangCode) {
	   // If they are not the same, then try to get
	   // the translated text, and send back to the 
	   // spark room.
	   Get translated text, and send to spark room.
		} else {
			//  send the prompt message to Spark, saying
			//  the language codes are the same, no need to
			  //  do translation.
			 Send prompt message to Spark room saying the codes are the same, no need to translate.
		}
	} else {
		// if the source sentence is in English, and 
		// there is no destination language code specified,
		// then prompt the language codes are the same.
		// no need to do translation. If they’re not, then
		// do translation.
	$sourceLangCode = detectSourceLangCode($validMessageText, $googleAPIKey, $emptyHeader);
		if ($sourceLangCode === $desLangCode) {
			sending prompt message to Spark room saying the codes are the same, no need to translate.
		} else {
			get translated sentence and send to spark room.
		}
	}

The detectSourceLangCode() is to detect the language code of the source message.

	function detectSourceLangCode($sourceMessage, $googleAPIKey, $header) {
		$sourceLangCodeDetection = getData("https://www.googleapis.com/language/translate/v2/detect?key=".$googleAPIKey."&q=".str_replace(" ","%20",$sourceMessage), $header, "get", null);
		$sourceLangCode = json_decode($sourceLangCodeDetection, true)["data"]["detections"][0][0]["language"];
		return $sourceLangCode;
	}


7. Send the desired sentences to Google API to get the translated text.

	function getTranslatedText($sourceMessage, $googleAPIKey, $desLangCode, $header) {
	$sourceMessage = str_replace(array(" ","\r","\n"), array("%20","%0A","%0A"), $sourceMessage);
	$desLangData = getData("https://www.googleapis.com/language/translate/v2?key=".$googleAPIKey."&q=".$sourceMessage."&target=".$desLangCode, $header, "get", null);
	$desLangTranslatedText = json_decode($desLangData, true)["data"]["translations"][0]["translatedText"];
	$desLangTranslatedText = str_replace(array("&#39;","&quot;"), array("'","\""), $desLangTranslatedText);
	return $desLangTranslatedText;
	}


8. Send the translated sentences back to Spark room where the source message is from (identified by the room ID in #3).

	function sendMessageToSpark($roomId, $headers, $message) {
		$message = str_replace('"', '\"', $message);
		// composing JSON
		$JSONData='{"roomId": "'.$roomId.'","text": "'.$message.'"}';
		$messageToSparkResult = getData("https://api.ciscospark.com/v1/messages", $headers, "post", $JSONData);
		error_log("sending message result: ".$messageToSparkResult);        
	}



Now you have the translation bot ready, try it and see the magic (The “Translator” is the bot’s name I set when creating the bot):

	Translator –help

The above command shows you the way of how to use it (fr means French).

	Translator fr How are you doing?

The above command tells the bot to transtlate “How are you doing?” to French. 


The complete code can be found on our Github https://github.com/AdamKong/translatorbot/blob/master/index.php. If you have any questions, please contact devsupport@ciscospark.com 24/7/365 and we’ll be happy to help!

Adam Kong - Customer Support Engineer
