<?php
require_once(__DIR__ . '/Unifi-API-client/Client.php');
require_once(__DIR__ . '/Unifi-API-client/config.php');
require_once(__DIR__ . '/../vendor/autoload.php');

use GuzzleHttp\Client as GuzzleClient;

// Load environment variables
$knownMacs = explode(',', getenv('KNOWN_MACS')); // MAC addresses are comma-separated
$checkInterval = getenv('CHECK_INTERVAL') ?: 60; // Time in seconds
$telegramBotToken = getenv('TELEGRAM_BOT_TOKEN');
$telegramChatId = getenv('TELEGRAM_CHAT_ID');
$guestSubnet = getenv('GUEST_SUBNET'); // 10.1.0.0
$guestSubnetMask = getenv('GUEST_SUBNET_MASK'); // 255.255.255.0


function loadKnownClients() {
    $filePath = __DIR__ . '/known_clients.json';
    if (file_exists($filePath)) {
        $json = file_get_contents($filePath);
        $data = json_decode($json, true);
        if (is_array($data)) {
            return $data;
        }
    }
    return [];
}

function saveKnownClients($clients) {
    $filePath = __DIR__ . '/known_clients.json';
    $json = json_encode($clients);
    file_put_contents($filePath, $json);
}

function isInGuestlistedSubnet($ip, $subnet, $mask) {
    $ip_long = ip2long($ip);
    $subnet_long = ip2long($subnet);
    $mask_long = ip2long($mask);

    return ($ip_long & $mask_long) == ($subnet_long & $mask_long);
}

function createUnifiClient() {
    global $controlleruser, $controllerpassword, $controllerurl, $site_id, $controllerversion;
    $unifiClient = new UniFi_API\Client($controlleruser, $controllerpassword, $controllerurl, $site_id, $controllerversion);
    $unifiClient->login();
    return $unifiClient;
}

$unifiClient = createUnifiClient();

$telegramClient = new GuzzleClient([
    'base_uri' => 'https://api.telegram.org'
]);

$knownClients = loadKnownClients();

while (true) {
    $clients = $unifiClient->list_clients();
    
    if ($clients === false) {
        echo "Error: Failed to retrieve clients from the UniFi Controller. Retrying in 60 seconds...\n";
        sleep(60); // Wait for 60 seconds
        $unifiClient->logout(); // Close the current connection
        $unifiClient = createUnifiClient(); // Reopen the connection
        continue; // Skip to the next iteration of the loop
    } elseif (is_array($clients) && count($clients) > 0) {
        $newDeviceFound = false;

        foreach ($clients as $client) {
            if (!array_key_exists($client->mac, $knownClients) && 
                !isInGuestlistedSubnet($client->ip, $guestSubnet, $guestSubnetMask))  {
                $newDeviceFound = true;

                $message = "New device seen on network\n";
                $message .= "Device Name: " . ($client->name ?? 'Unknown') . "\n";
                $message .= "IP Address: " . $client->ip . "\n";
                $message .= "Hostname: " . ($client->hostname ?? 'N/A') . "\n";
                $message .= "MAC Address: " . $client->mac . "\n";
                $message .= "Connection Type: " . ($client->is_wired ? "Wired" : "Wireless") . "\n";
                $message .= "Network: " . ($client->network ?? 'N/A');

                $deviceName = $client->name ?? 'Unknown'; 
                $ipAddress = $client->ip ?? 'N/A';

                echo "New device found: Name - {$deviceName}, IP - {$ipAddress}. Sending a notification.\n";
                $telegramClient->post("/bot{$telegramBotToken}/sendMessage", [
                    'json' => [
                        'chat_id' => $telegramChatId,
                        'text' => $message
                    ]
                ]);

                $knownClients[$client->mac] = (array)$client;
            }
        }

        if (!$newDeviceFound) {
            #echo "No new devices found on the network.\n";
            sleep(1);
        }
    } else {
        echo "No clients currently connected to the network.\n";
    }

    saveKnownClients($knownClients); 
    echo "Checking in {$checkInterval} seconds...\n";
    sleep($checkInterval);
}