<?php
/**
 * Omise Payment Gateway Client
 * Integration with Omise for credit card processing
 */

class OmiseClient {
    private $publicKey;
    private $secretKey;
    private $apiUrl = 'https://api.omise.co';

    public function __construct() {
        // Load from environment variables
        $this->publicKey = getenv('OMISE_PUBLIC_KEY') ?: 'pkey_test_654hy8uu12f7afruxgn';
        $this->secretKey = getenv('OMISE_SECRET_KEY') ?: 'skey_test_654hy8vcxqqkhs4gokz';
    }

    /**
     * Create Omise customer
     */
    public function createCustomer($email, $description, $cardToken) {
        $data = [
            'email' => $email,
            'description' => $description,
            'card' => $cardToken
        ];

        return $this->request('POST', '/customers', $data);
    }

    /**
     * Get customer details
     */
    public function getCustomer($customerId) {
        return $this->request('GET', '/customers/' . $customerId);
    }

    /**
     * Add card to customer
     */
    public function addCard($customerId, $cardToken) {
        $data = ['card' => $cardToken];
        return $this->request('PATCH', '/customers/' . $customerId, $data);
    }

    /**
     * Delete card
     */
    public function deleteCard($customerId, $cardId) {
        return $this->request('DELETE', '/customers/' . $customerId . '/cards/' . $cardId);
    }

    /**
     * Create charge
     */
    public function createCharge($amount, $currency, $customerId, $cardId = null, $description = '') {
        $data = [
            'amount' => $amount * 100, // Convert to satang (smallest unit)
            'currency' => $currency,
            'customer' => $customerId,
            'description' => $description
        ];

        if ($cardId) {
            $data['card'] = $cardId;
        }

        return $this->request('POST', '/charges', $data);
    }

    /**
     * Get charge details
     */
    public function getCharge($chargeId) {
        return $this->request('GET', '/charges/' . $chargeId);
    }

    /**
     * Create PromptPay source
     * @param int $amount Amount in THB (will be converted to satang)
     * @param string $currency Currency code (default: THB)
     * @return array Source details including QR code URL
     */
    public function createPromptPaySource($amount, $currency = 'THB') {
        $data = [
            'amount' => $amount * 100, // Convert to satang
            'currency' => $currency,
            'type' => 'promptpay'
        ];

        return $this->request('POST', '/sources', $data);
    }

    /**
     * Create charge from source (supports PromptPay and other source types)
     * @param string $sourceId Source ID from createPromptPaySource or similar
     * @param int $amount Amount in THB
     * @param string $currency Currency code
     * @param string $description Charge description
     * @return array Charge details
     */
    public function createChargeFromSource($sourceId, $amount, $currency = 'THB', $description = '') {
        $data = [
            'amount' => $amount * 100, // Convert to satang
            'currency' => $currency,
            'source' => $sourceId,
            'description' => $description,
            'return_uri' => getenv('APP_URL') ?: 'http://localhost/autobot/public/payment.php'
        ];

        return $this->request('POST', '/charges', $data);
    }

    /**
     * Retrieve charge details (alias for getCharge with better name)
     * @param string $chargeId Charge ID
     * @return array Charge details including status
     */
    public function retrieveCharge($chargeId) {
        return $this->getCharge($chargeId);
    }

    /**
     * Make HTTP request to Omise API
     */
    private function request($method, $endpoint, $data = []) {
        $url = $this->apiUrl . $endpoint;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $this->secretKey . ':');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);

        if ($httpCode >= 400) {
            throw new Exception($result['message'] ?? 'Omise API request failed');
        }

        return $result;
    }

    /**
     * Get public key for frontend
     */
    public function getPublicKey() {
        return $this->publicKey;
    }
}
