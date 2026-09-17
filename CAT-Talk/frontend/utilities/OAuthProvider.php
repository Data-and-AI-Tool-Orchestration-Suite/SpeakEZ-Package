<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use TheNetworg\OAuth2\Client\Token\AccessToken;
use TheNetworg\OAuth2\Client\Provider\Azure;

class OAuthProvider {
    protected static $provider = null;

    public static function getProvider(): Azure {
        if (!is_null(self::$provider))
            return self::$provider;
        $config = include CONFIG_FILE;
        self::$provider = new TheNetworg\OAuth2\Client\Provider\Azure([
            'clientId'                  => $config['oauth2']['clientId'],
            'clientSecret'              => $config['oauth2']['clientSecret'],
            'redirectUri'               => $config['oauth2']['redirectUri'],
            'scopes'                    => $config['oauth2']['scopes'],
            'tenant'                    => $config['oauth2']['tenant'],
            'defaultEndPointVersion'    => $config['oauth2']['defaultEndPointVersion']
        ]);
        self::$provider->defaultEndPointVersion = TheNetworg\OAuth2\Client\Provider\Azure::ENDPOINT_VERSION_2_0;
        $baseGraphUri = self::$provider->getRootMicrosoftGraphUri(null);
        self::$provider->scope = 'openid profile ' . $baseGraphUri . '/User.Read';
        return self::$provider;
    }

    public static function getProfile(AccessToken $token): ?array {
        $client = new Client();
        $headers = [
            'Authorization' => 'Bearer ' . $token->getToken(),
        ];
        try {
            $response = $client->request(
                'GET',
                'https://graph.microsoft.com/v1.0/me',
                array('headers' => $headers)
            );
            return json_decode($response->getBody(), true);
        } catch (ClientException | GuzzleException $e) {
            return null;
        }
    }
}