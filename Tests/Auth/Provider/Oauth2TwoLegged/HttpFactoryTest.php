<?php


namespace MauticPlugin\IntegrationsBundle\Tests\Auth\Provider\Oauth2TwoLegged;


use GuzzleHttp\ClientInterface;
use kamermans\OAuth2\GrantType\ClientCredentials;
use kamermans\OAuth2\GrantType\PasswordCredentials;
use kamermans\OAuth2\OAuth2Middleware;
use kamermans\OAuth2\Persistence\TokenPersistenceInterface as KamermansTokenPersistenceInterface;
use kamermans\OAuth2\Signer\AccessToken\SignerInterface as AccessTokenSigner;
use kamermans\OAuth2\Signer\ClientCredentials\SignerInterface;
use MauticPlugin\IntegrationsBundle\Auth\Provider\AuthCredentialsInterface;
use MauticPlugin\IntegrationsBundle\Auth\Provider\ConfigAccess\CredentialsSignerInterface;
use MauticPlugin\IntegrationsBundle\Auth\Provider\ConfigAccess\TokenPersistenceInterface;
use MauticPlugin\IntegrationsBundle\Auth\Provider\ConfigAccess\TokenSignerInterface;
use MauticPlugin\IntegrationsBundle\Auth\Provider\Oauth2ThreeLegged\Credentials\CredentialsInterface;
use MauticPlugin\IntegrationsBundle\Auth\Provider\Oauth2TwoLegged\Credentials\ClientCredentialsGrantInterface;
use MauticPlugin\IntegrationsBundle\Auth\Provider\Oauth2TwoLegged\Credentials\PasswordCredentialsGrantInterface;
use MauticPlugin\IntegrationsBundle\Auth\Provider\Oauth2TwoLegged\Credentials\ScopeInterface;
use MauticPlugin\IntegrationsBundle\Auth\Provider\Oauth2TwoLegged\Credentials\StateInterface;
use MauticPlugin\IntegrationsBundle\Auth\Provider\Oauth2TwoLegged\HttpFactory;
use MauticPlugin\IntegrationsBundle\Exception\InvalidCredentialsException;
use MauticPlugin\IntegrationsBundle\Exception\PluginNotConfiguredException;

class HttpFactoryTest extends \PHPUnit_Framework_TestCase
{
    public function testType()
    {
        $this->assertEquals('oauth2_two_legged', (new HttpFactory())->getAuthType());
    }

    public function testInvalidCredentialsThrowsException()
    {
        $this->expectException(InvalidCredentialsException::class);

        $credentials = new Class implements AuthCredentialsInterface
        {
        };

        (new HttpFactory())->getClient($credentials);
    }

    public function testMissingAuthorizationUrlThrowsException()
    {
        $this->expectException(PluginNotConfiguredException::class);

        $credentials = new Class implements ClientCredentialsGrantInterface
        {
            public function getAuthorizationUrl(): string
            {
                return '';
            }

            public function getClientId(): ?string
            {
                return '';
            }

            public function getClientSecret(): ?string
            {
                return '';
            }
        };

        (new HttpFactory())->getClient($credentials);
    }

    public function testMissingClientIdThrowsException()
    {
        $this->expectException(PluginNotConfiguredException::class);

        $credentials = new Class implements ClientCredentialsGrantInterface
        {
            public function getAuthorizationUrl(): string
            {
                return 'http://test.com';
            }

            public function getClientId(): ?string
            {
                return '';
            }

            public function getClientSecret(): ?string
            {
                return '';
            }
        };

        (new HttpFactory())->getClient($credentials);
    }

    public function testMissingClientSecretIdThrowsException()
    {
        $this->expectException(PluginNotConfiguredException::class);

        $credentials = new Class implements ClientCredentialsGrantInterface
        {
            public function getAuthorizationUrl(): string
            {
                return 'http://test.com';
            }

            public function getClientId(): ?string
            {
                return 'foo';
            }

            public function getClientSecret(): ?string
            {
                return '';
            }
        };

        (new HttpFactory())->getClient($credentials);
    }

    public function testMissingUsernameThrowsException()
    {
        $this->expectException(PluginNotConfiguredException::class);

        $credentials = new Class implements PasswordCredentialsGrantInterface
        {
            public function getAuthorizationUrl(): string
            {
                return 'http://test.com';
            }

            public function getClientId(): ?string
            {
                return 'foo';
            }

            public function getClientSecret(): ?string
            {
                return 'bar';
            }

            public function getUsername(): ?string
            {
                return '';
            }

            public function getPassword(): ?string
            {
                return '';
            }
        };

        (new HttpFactory())->getClient($credentials);
    }

    public function testMissingPasswordThrowsException()
    {
        $this->expectException(PluginNotConfiguredException::class);

        $credentials = new Class implements PasswordCredentialsGrantInterface
        {
            public function getAuthorizationUrl(): string
            {
                return 'http://test.com';
            }

            public function getClientId(): ?string
            {
                return 'foo';
            }

            public function getClientSecret(): ?string
            {
                return 'bar';
            }

            public function getUsername(): ?string
            {
                return 'foo';
            }

            public function getPassword(): ?string
            {
                return '';
            }
        };

        (new HttpFactory())->getClient($credentials);
    }

    public function testInstantiatedClientIsReturned()
    {
        $credentials = new Class implements ClientCredentialsGrantInterface
        {
            public function getAuthorizationUrl(): string
            {
                return 'http://test.com';
            }

            public function getClientId(): ?string
            {
                return 'foo';
            }

            public function getClientSecret(): ?string
            {
                return 'bar';
            }
        };

        $factory = new HttpFactory();

        $client1 = $factory->getClient($credentials);
        $client2 = $factory->getClient($credentials);
        $this->assertTrue($client1 === $client2);

        $credentials2 = new Class implements ClientCredentialsGrantInterface
        {
            public function getAuthorizationUrl(): string
            {
                return 'http://test.com';
            }

            public function getClientId(): ?string
            {
                return 'bar';
            }

            public function getClientSecret(): ?string
            {
                return 'foo';
            }
        };

        $client3 = $factory->getClient($credentials2);
        $this->assertFalse($client1 === $client3);
    }

    public function testReAuthClientConfiguration()
    {
        $credentials = $this->getCredentials();

        $client = (new HttpFactory())->getClient($credentials);

        $middleware = $this->extractMiddleware($client);

        $reflectedMiddleware = new \ReflectionClass($middleware);
        $grantType           = $this->getProperty($reflectedMiddleware, $middleware, 'grantType');

        $reflectedGrantType = new \ReflectionClass($grantType);
        $reauthConfig       = $this->getProperty($reflectedGrantType, $grantType, 'config');

        $expectedConfig = [
            'client_id'     => $credentials->getClientId(),
            'client_secret' => $credentials->getClientSecret(),
            'scope'         => $credentials->getScope(),
            'state'         => $credentials->getState(),
            'username'      => $credentials->getUsername(),
            'password'      => $credentials->getPassword(),
        ];

        $this->assertEquals($expectedConfig, $reauthConfig->toArray());
    }

    public function testPasswordGrantTypeIsUsed()
    {
        $credentials = new Class implements PasswordCredentialsGrantInterface
        {
            public function getAuthorizationUrl(): string
            {
                return 'http://test.com';
            }

            public function getClientId(): ?string
            {
                return 'foo';
            }

            public function getClientSecret(): ?string
            {
                return 'bar';
            }

            public function getUsername(): ?string
            {
                return 'username';
            }

            public function getPassword(): ?string
            {
                return 'password';
            }
        };

        $client              = (new HttpFactory())->getClient($credentials);
        $middleware          = $this->extractMiddleware($client);
        $reflectedMiddleware = new \ReflectionClass($middleware);
        $grantType           = $this->getProperty($reflectedMiddleware, $middleware, 'grantType');

        $this->assertInstanceOf(PasswordCredentials::class, $grantType);
    }

    public function testClientCredentialsGrantTypeIsUsed()
    {
        $credentials = new Class implements ClientCredentialsGrantInterface
        {
            public function getAuthorizationUrl(): string
            {
                return 'http://test.com';
            }

            public function getClientId(): ?string
            {
                return 'foo';
            }

            public function getClientSecret(): ?string
            {
                return 'bar';
            }
        };

        $client              = (new HttpFactory())->getClient($credentials);
        $middleware          = $this->extractMiddleware($client);
        $reflectedMiddleware = new \ReflectionClass($middleware);
        $grantType           = $this->getProperty($reflectedMiddleware, $middleware, 'grantType');

        $this->assertInstanceOf(ClientCredentials::class, $grantType);
    }

    public function testClientConfiguration()
    {
        $credentials               = $this->getCredentials();
        $signerInterface           = $this->createMock(SignerInterface::class);
        $kamermansTokenPersistence = $this->createMock(KamermansTokenPersistenceInterface::class);
        $accessTokenSigner         = $this->createMock(AccessTokenSigner::class);

        $clientCredentialSigner = $this->createMock(CredentialsSignerInterface::class);
        $clientCredentialSigner->expects($this->once())
            ->method('getCredentialsSigner')
            ->willReturn($signerInterface);

        $client = (new \MauticPlugin\IntegrationsBundle\Auth\Provider\Oauth2ThreeLegged\HttpFactory())->getClient($credentials, $clientCredentialSigner);
        $middleware = $this->extractMiddleware($client);
        $reflectedMiddleware = new \ReflectionClass($middleware);
        $this->assertTrue($this->getProperty($reflectedMiddleware, $middleware, 'clientCredentialsSigner') === $signerInterface);

        $tokenPersistence = $this->createMock(TokenPersistenceInterface::class);
        $tokenPersistence->expects($this->once())
            ->method('getTokenPersistence')
            ->willReturn($kamermansTokenPersistence);

        $client = (new HttpFactory())->getClient($credentials, $tokenPersistence);
        $middleware = $this->extractMiddleware($client);
        $reflectedMiddleware = new \ReflectionClass($middleware);
        $this->assertTrue($this->getProperty($reflectedMiddleware, $middleware, 'tokenPersistence') === $kamermansTokenPersistence);

        $tokenPersistence = $this->createMock(TokenSignerInterface::class);
        $tokenPersistence->expects($this->once())
            ->method('getTokenSigner')
            ->willReturn($accessTokenSigner);

        $client = (new HttpFactory())->getClient($credentials, $tokenPersistence);
        $middleware = $this->extractMiddleware($client);
        $reflectedMiddleware = new \ReflectionClass($middleware);
        $this->assertTrue($this->getProperty($reflectedMiddleware, $middleware, 'accessTokenSigner') === $accessTokenSigner);
    }

    /**
     * @param ClientInterface $client
     *
     * @return OAuth2Middleware
     * @throws \ReflectionException
     */
    private function extractMiddleware(ClientInterface $client): OAuth2Middleware
    {
        $handler = $client->getConfig()['handler'];

        $reflection = new \ReflectionClass($handler);
        $property   = $reflection->getProperty('stack');
        $property->setAccessible(true);

        $stack = $property->getValue($handler);

        /** @var OAuth2Middleware $oauthMiddleware */
        $oauthMiddleware = array_pop($stack);

        return $oauthMiddleware[0];
    }

    private function getProperty(\ReflectionClass $reflection, $object, string $name)
    {
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);

        return $property->getValue($object);
    }

    /**
     * @return PasswordCredentialsGrantInterface|StateInterface|ScopeInterface
     */
    private function getCredentials(): PasswordCredentialsGrantInterface
    {
        return new Class implements PasswordCredentialsGrantInterface, StateInterface, ScopeInterface, CredentialsInterface
        {
            public function getAuthorizationUrl(): string
            {
                return 'http://test.com';
            }

            public function getClientId(): ?string
            {
                return 'bar';
            }

            public function getUsername(): ?string
            {
                return 'username';
            }

            public function getPassword(): ?string
            {
                return 'password';
            }

            public function getState(): ?string
            {
                return 'state';
            }

            public function getScope(): ?string
            {
                return 'scope';
            }

            public function getClientSecret(): ?string
            {
                return 'secret';
            }

            public function getTokenUrl(): string
            {
                return 'tokenurl';
            }
        };
    }
}