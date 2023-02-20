<?php 

namespace CycleGateway;

class Client {
    private $api_key;

    const API_CREATE_URL = 'https://dev.cyclebit.io/api/payment/';
    const API_STATUS_URL = 'https://dev.cyclebit.io/api/orders/';

    public function __construct($api_key) {
        $this->api_key = $api_key;
    }

    /*** Get customer IP address. */
    public function getUserIp() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            // check ip from share internet
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        }
        elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // to check ip is pass from proxy
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        }
        else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        return $ip;
    }

    /** Request to URI using curl. */
    private function request($url, $type = 'GET', $data = ''): array {
        $headers = [    
            "content-type: application/json",
            "Authorization: " .  $this->api_key
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);

        curl_setopt($ch, CURLOPT_POST, $type == 'POST' ? 1 : 0 );
        if ($type == 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, $data); 

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1000);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // TRUE to return the transfer as a string of the return value of curl_exec() instead of outputting it out directly.
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
        curl_setopt($ch, CURLOPT_VERBOSE, true); // Enable this line to see debug prints

        $output = curl_exec($ch);
        if (!$output) {
            $output = curl_error($ch);
        }

        $info = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        return ['code' => $info, 'body' => $output];
    }

    public function createPayment($requestJSON): array
    {
        return $this->request(Client::API_CREATE_URL, 'POST', $requestJSON);
    }

    public function getPaymentStatus($transactionId): array
    {
        return $this->request(Client::API_STATUS_URL . $transactionId . '/payment');
    }
}
