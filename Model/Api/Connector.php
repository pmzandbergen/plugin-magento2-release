<?php

namespace DHLParcel\Shipping\Model\Api;

use DHLParcel\Shipping\Helper\Data;
use DHLParcel\Shipping\Logger\DebugLogger;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Exception\ClientException;
use Psr\Http\Message\ResponseInterface;
use DHLParcel\Shipping\Model\Cache\Api as ApiCache;

class Connector
{
    const POST = 'post';
    const GET = 'get';
    const AUTH_API = 'authenticate/api-key';
    protected $accessToken;
    protected $apiCache;
    /** @var Client */
    protected $client;
    protected $debugLogger;
    protected $failedAuthentication = false;
    protected $helper;
    protected $url = 'https://api-gw.dhlparcel.nl/';
    public $isError = false;
    public $errorId = null;
    public $errorCode = null;
    public $errorMessage = null;

    public function __construct(
        ApiCache $apiCache,
        Client $client,
        Data $helper,
        DebugLogger $debugLogger
    ) {
        $this->apiCache = $apiCache;
        $this->client = $client;
        $this->debugLogger = $debugLogger;
        $this->helper = $helper;
        if ($this->helper->getConfigData('debug/alternative_api_enable')) {
            $this->url = $this->helper->getConfigData('debug/alternative_api_url');
        }
    }

    public function getAvailableMethods()
    {
        return [
            self::POST,
            self::GET
        ];
    }

    public function post($endpoint, $params = null)
    {
        $request = $this->request(self::POST, $endpoint, $params);

        if (!$request) {
            return false;
        }

        $data = json_decode($request->getBody()->getContents(), true);
        $this->debugLogger->info('CONNECTOR API response decoded', ['response' => $data]);
        return $data;
    }

    public function get($endpoint, $params = null)
    {
        $request = $this->request(self::GET, $endpoint, $params);
        if (!$request) {
            return false;
        }

        $data = json_decode($request->getBody()->getContents(), true);
        $this->debugLogger->info('CONNECTOR API response decoded', ['response' => $data]);
        return $data;
    }

    /**
     * @param $method
     * @param $endpoint
     * @param array|null $params
     * @param bool $isRetry
     * @return ResponseInterface|bool
     */
    public function request($method, $endpoint, $params = [], $isRetry = false)
    {
        // Assume there's always an error, until this method manages to return correctly and set the boolean to true.
        $this->isError = true;
        $this->errorId = null;
        $this->errorCode = null;
        $this->errorMessage = null;
        $options = [RequestOptions::HEADERS => [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json'
        ]];

        if ($endpoint != self::AUTH_API) {
            if (empty($this->accessToken)) {
                $this->authenticate();
            }
            $options[RequestOptions::HEADERS]['Authorization'] = "Bearer {$this->accessToken}";
        }

        if (!empty($params)) {
            if ($method == self::POST) {
                $options[RequestOptions::JSON] = $params;
            } else {
                $options[RequestOptions::QUERY] = $params;
            }
        }
        try {
            $this->debugLogger->info('CONNECTOR API request', ['method' => $method, 'url' => $this->url, $endpoint, $options]);
            /** @var Response $response */
            $response = $this->client->{$method}($this->url . $endpoint, $options);
            $this->debugLogger->info('CONNECTOR API response raw', ['response' => $response->getBody()->getContents()]);
            $response->getBody()->rewind();
        } catch (ClientException $e) {
            if ($e->getCode() === 401 && $endpoint !== self::AUTH_API && $isRetry === false && $this->failedAuthentication !== true) {
                // Try again after an auth
                $this->authenticate(true);
                $this->debugLogger->info('CONNECTOR API request failed, attempting retry');
                $response = $this->request($method, $endpoint, $params, true);
            } else {
                $this->isError = true;
                $this->errorCode = $e->getCode();
                $this->errorMessage = $e->getResponse()->getBody()->getContents();
                $this->debugLogger->info('CONNECTOR API request failed, client exception', ['code' => $this->errorCode, 'message' => $this->errorMessage]);

                return false;
            }
        } catch (\GuzzleHttp\Exception\ServerException $e) {
            $this->isError = true;
            $this->errorCode = $e->getCode();
            $this->errorMessage = $e->getResponse()->getBody()->getContents();
            $this->debugLogger->info('CONNECTOR API request failed, server exception', ['code' => $this->errorCode, 'message' => $this->errorMessage]);
            if ($this->helper->getConfigData('debug/enabled')) {
                throw $e;
            }

            return false;
        }

        if (!is_bool($response) && $response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $this->debugLogger->info('CONNECTOR API request successful');
            $this->isError = false;
            return $response;
        }
        $this->debugLogger->info('CONNECTOR API request failed');
        return false;
    }

    protected function authenticate($refresh = false)
    {
        // Prevent endless authentication calls
        if ($this->failedAuthentication) {
            // Exit early
            return;
        }
        if (!empty($this->accessToken) && $refresh === false) {
            return;
        }

        $accessToken = $this->apiCache->load('accessToken');
        if ($accessToken === false || $refresh === true) {
            $response = $this->post(self::AUTH_API, [
                'userId' => $this->helper->getConfigData('api/user'),
                'key'    => $this->helper->getConfigData('api/key'),
            ]);
            if (!empty($response['accessToken'])) {
                $this->apiCache->save($response['accessToken'], 'accessToken', [], 720);
                $this->accessToken = $response['accessToken'];
            }
            if (empty($response['accessToken']) && $refresh === true) {
                $this->failedAuthentication = true;
            }
        } else {
            $this->accessToken = $accessToken;
        }
    }

    /**
     * @param $user_id
     * @param $key
     * @return array|bool
     */
    public function testAuthenticate($user_id, $key)
    {
        $response = $this->post(self::AUTH_API, [
            'userId' => $user_id,
            'key'    => $key,
        ]);

        if (!isset($response['accessToken'])) {
            return false;
        }

        if (isset($response['accounts'])) {
            $accounts = $response['accounts'];
        } else {
            $accounts = $this->parseToken($response['accessToken'], 'accounts');
        }

        return [
            'accounts' => $accounts,
        ];
    }

    /**
     * @param $token
     * @param $key
     * @return bool
     * @deprecated
     */
    protected function parseToken($token, $key)
    {
        // Retrieve middle part
        $tokenParts = explode('.', $token);
        if (count($tokenParts) < 2) {
            return false;
        }

        // Base64 decode
        $jsonData = base64_decode($tokenParts[1]);
        if (!$jsonData) {
            return false;
        }

        // Json decode
        $data = json_decode($jsonData, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        // Find key
        if (!isset($data[$key])) {
            return false;
        }

        return $data[$key];
    }
}
