<?php

namespace Omnibus\Fedex;

use Omnibus\Exception\CarrierException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** FedEx's REST APIs on an OAuth2 client-credentials token, kept until it expires. */
final class Api
{
    public const LIVE = 'https://apis.fedex.com';
    public const TEST = 'https://apis-sandbox.fedex.com';

    private ?string $token = null;
    private int $expiresAt = 0;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $clientId,
        private readonly string $clientSecret,
        public readonly string $accountNumber,
        public readonly bool $sandbox = false,
        private readonly int $timeout = 20,
    ) {
    }

    public function base(): string
    {
        return $this->sandbox ? self::TEST : self::LIVE;
    }

    /** @return array<string, mixed> */
    public function call(string $method, string $path, ?array $body = null, string $locale = 'en_US'): array
    {
        try {
            $response = $this->http->request($method, $this->base().$path, [
                'headers' => ['Authorization' => 'Bearer '.$this->token(), 'Content-Type' => 'application/json', 'X-locale' => $locale, 'x-customer-transaction-id' => bin2hex(random_bytes(8))],
                'body' => null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR),
                'timeout' => $this->timeout,
            ]);
            $status = $response->getStatusCode();
            $data = json_decode($response->getContent(false), true);
        } catch (HttpExceptionInterface|\JsonException $e) {
            throw new CarrierException('fedex', 'FedEx request failed: '.$e->getMessage(), null, $e);
        }
        if (!\is_array($data)) {
            throw new CarrierException('fedex', sprintf('FedEx answered HTTP %d with a body that is not JSON.', $status));
        }
        if ($status >= 400) {
            $error = $data['errors'][0] ?? [];
            throw new CarrierException('fedex', (string) ($error['message'] ?? sprintf('HTTP %d', $status)), isset($error['code']) ? (string) $error['code'] : null);
        }

        return $data;
    }

    private function token(): string
    {
        if (null !== $this->token && time() < $this->expiresAt - 60) {
            return $this->token;
        }
        try {
            $data = $this->http->request('POST', $this->base().'/oauth/token', [
                'body' => ['grant_type' => 'client_credentials', 'client_id' => $this->clientId, 'client_secret' => $this->clientSecret],
                'timeout' => $this->timeout,
            ])->toArray(false);
        } catch (HttpExceptionInterface $e) {
            throw new CarrierException('fedex', 'FedEx gave no token: '.$e->getMessage(), null, $e);
        }
        if (empty($data['access_token'])) {
            throw new CarrierException('fedex', (string) ($data['errors'][0]['message'] ?? 'FedEx gave no token: check the client id and secret.'));
        }
        $this->token = (string) $data['access_token'];
        $this->expiresAt = time() + (int) ($data['expires_in'] ?? 3600);

        return $this->token;
    }
}
