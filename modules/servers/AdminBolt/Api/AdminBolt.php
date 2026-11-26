<?php

namespace ModulesGarden\AdminBolt\Api;

use Exception;
use ModulesGarden\AdminBolt\Helpers\ModuleLog;

class AdminBolt
{
    /** Seconds to wait for the panel to accept the connection. */
    public const CONNECT_TIMEOUT_SECONDS = 15;

    /**
     * Seconds a call may take in all, below the usual WHMCS PHP time limit,
     * so a panel that hangs fails the command with a clear error instead of
     * holding it until WHMCS kills it. withTimeout() changes it for one call.
     */
    public const TIMEOUT_SECONDS = 240;

    protected int $timeoutSeconds = self::TIMEOUT_SECONDS;

    /** An administrator key: the admin API (/api/...). */
    public const KEY_ADMIN = 'admin';

    /** A reseller's key: the reseller API (/api/reseller/...), own plans and accounts only. */
    public const KEY_RESELLER = 'reseller';

    /**
     * What the panel's API key middleware answers (403) for a key of another
     * owner type, before the request reaches any controller, so nothing was
     * done and the same request may go to the other API.
     */
    public const WRONG_KEY_TYPE_ERROR = 'API key does not have access to this resource';

    /**
     * The key type of the server's API key: null until the first answer
     * shows it. Calls go to the admin API first, as in module 0.0.5; when
     * the panel refuses the key there as a key of another owner type, the
     * call is sent to the reseller API, and every later call of this client
     * goes there.
     */
    protected ?string $keyType = null;

    public function __construct(
        protected string $url,
        protected string $apiKey,
        protected string $apiSecret
    ) {}

    protected function request(string $method, string $endpoint, ?array $content = null): ?array
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $this->url . $endpoint);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLINFO_HEADER_OUT, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS);
        curl_setopt($curl, CURLOPT_TIMEOUT, $this->timeoutSeconds);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-Key: ' . $this->apiKey,
            'X-API-Secret: ' . $this->apiSecret,
        ]);

        // IGNORE CERT
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        // IGNORE CERT

        if($content)
        {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($content));
        }

        $response = curl_exec($curl);

        if(curl_errno($curl))
        {
            throw new Exception('API error: ' . curl_error($curl));
        }

        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        $requestHeaders = curl_getinfo($curl, CURLINFO_HEADER_OUT);
        $request = $requestHeaders . ($content ? json_encode($content, JSON_PRETTY_PRINT) : '');

        $responseHeaderSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $responseBody = substr($response, $responseHeaderSize);

        $this->logCall($endpoint, $request, (string) $response, $content);

        curl_close($curl);

        $responseBodyDecoded = json_decode($responseBody, true);

        if(!self::isSuccessCode((int) $httpCode))
        {
            // The panel reports errors under "message"; "error" is kept for older responses.
            $errorMessage = 'API error (HTTP ' . $httpCode . ')';

            foreach(['error', 'message'] as $key)
            {
                if(is_array($responseBodyDecoded) && isset($responseBodyDecoded[$key]) && is_string($responseBodyDecoded[$key]) && $responseBodyDecoded[$key] !== '')
                {
                    $errorMessage = 'API error: ' . $responseBodyDecoded[$key];
                    break;
                }
            }

            throw new ApiException(
                $errorMessage,
                (int) $httpCode,
                is_array($responseBodyDecoded) ? $responseBodyDecoded : null
            );
        }

        return $responseBodyDecoded;
    }

    /**
     * The HTTP codes of a panel answer that is not an error: 202 is a call
     * the panel accepted and does in the background (reseller termination).
     */
    public static function isSuccessCode(int $httpCode): bool
    {
        return in_array($httpCode, [200, 201, 202, 204], true);
    }

    /**
     * Module Log entry of an API call, without the API key, the API secret
     * and passwords sent in the body.
     */
    protected function logCall(string $endpoint, string $request, string $response, ?array $content): void
    {
        ModuleLog::call(
            $endpoint,
            $request,
            $response,
            $response,
            array_merge([$this->apiKey, $this->apiSecret], ModuleLog::secretsOf($content ?? []))
        );
    }

    /** A copy of this client whose calls time out after $seconds in all. */
    public function withTimeout(int $seconds): static
    {
        $client = clone $this;
        $client->timeoutSeconds = $seconds;

        return $client;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function get(string $endpoint): ?array
    {
        return $this->send('GET', $endpoint);
    }

    public function post(string $endpoint, ?array $content = null): ?array
    {
        return $this->send('POST', $endpoint, $content);
    }

    public function put(string $endpoint, ?array $content = null): ?array
    {
        return $this->send('PUT', $endpoint, $content);
    }

    public function delete(string $endpoint): ?array
    {
        return $this->send('DELETE', $endpoint);
    }

    /** The key type shown by the panel's answers so far (KEY_ADMIN, KEY_RESELLER), or null. */
    public function keyType(): ?string
    {
        return $this->keyType;
    }

    /**
     * Sends a call written for the admin API. With a reseller key it goes to
     * the reseller API, which has the same hosting plan and hosting account
     * calls limited to the reseller's own plans and accounts; a call that
     * only the admin API has (resellers, the admin login) fails with a clear
     * message before anything is sent.
     */
    protected function send(string $method, string $endpoint, ?array $content = null): ?array
    {
        if($this->keyType === self::KEY_RESELLER)
        {
            return $this->request($method, $this->resellerEndpointOrFail($endpoint), $content);
        }

        try
        {
            $response = $this->request($method, $endpoint, $content);
        }
        catch(ApiException $e)
        {
            if($this->keyType !== null || !self::isWrongKeyType($e))
            {
                throw $e;
            }

            $resellerEndpoint = $this->resellerEndpointOrFail($endpoint);

            try
            {
                $response = $this->request($method, $resellerEndpoint, $content);
            }
            catch(ApiException $resellerError)
            {
                if(self::isWrongKeyType($resellerError))
                {
                    throw new Exception('The API key of this server is neither an administrator key nor a reseller key: the panel refused it on the admin API and on the reseller API ("' . self::WRONG_KEY_TYPE_ERROR . '"). Enter an administrator API key, or a reseller\'s API key for shared hosting products, in the server settings. Nothing was changed.', 0, $resellerError);
                }

                $this->keyType = self::KEY_RESELLER;
                throw $resellerError;
            }

            $this->keyType = self::KEY_RESELLER;

            return $response;
        }

        $this->keyType = self::KEY_ADMIN;

        return $response;
    }

    /**
     * The reseller API path of an admin API path: /api/hosting-plans... and
     * /api/hosting-accounts... (with every sub-path the module uses) under
     * /api/reseller/. Any other path has no reseller counterpart.
     */
    public static function resellerEndpoint(string $endpoint): ?string
    {
        if(preg_match('#^/api/(hosting-plans|hosting-accounts)(?=/|\?|$)(.*)$#s', $endpoint, $m))
        {
            return '/api/reseller/' . $m[1] . $m[2];
        }

        return null;
    }

    protected function resellerEndpointOrFail(string $endpoint): string
    {
        $resellerEndpoint = self::resellerEndpoint($endpoint);

        if($resellerEndpoint === null)
        {
            throw new Exception('This needs an administrator API key, and the API key of this server is not one (the panel answered "' . self::WRONG_KEY_TYPE_ERROR . '" on the admin API). A reseller\'s API key works for shared hosting products only; reseller products and the admin login to the panel need an administrator API key in the server settings. Nothing was changed.');
        }

        return $resellerEndpoint;
    }

    /** The panel refused the key as one of another owner type (403 from its key middleware). */
    public static function isWrongKeyType(ApiException $e): bool
    {
        $body = $e->getResponseBody() ?? [];
        $error = is_string($body['error'] ?? null) ? rtrim(trim($body['error']), '.') : '';

        return $e->getHttpCode() === 403 && $error === self::WRONG_KEY_TYPE_ERROR;
    }
}