<?php
/*
 * Copyright 2010 Google Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Google\Auth\Tests;

use Google\Auth\OAuth2;
use GuzzleHttp\Client;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Stream\Stream;
use GuzzleHttp\Subscriber\Mock;
use GuzzleHttp\Url;
use JWT;

class OAuth2AuthorizationUriTest extends \PHPUnit_Framework_TestCase
{

  private $minimal = [
      'authorizationUri' => 'https://accounts.test.org/insecure/url',
      'redirectUri' => 'https://accounts.test.org/redirect/url',
      'clientId' => 'aClientID'
  ];

  /**
   * @expectedException InvalidArgumentException
   */
  public function testIsNullIfAuthorizationUriIsNull()
  {
    $o = new OAuth2([]);
    $this->assertNull($o->buildFullAuthorizationUri());
  }

  /**
   * @expectedException InvalidArgumentException
   */
  public function testRequiresTheClientId()
  {
    $o = new OAuth2([
        'authorizationUri' => 'https://accounts.test.org/auth/url',
        'redirectUri' => 'https://accounts.test.org/redirect/url'
    ]);
    $o->buildFullAuthorizationUri();
  }

  /**
   * @expectedException InvalidArgumentException
   */
  public function testRequiresTheRedirectUri()
  {
    $o = new OAuth2([
        'authorizationUri' => 'https://accounts.test.org/auth/url',
        'clientId' => 'aClientID'
    ]);
    $o->buildFullAuthorizationUri();
  }

  /**
   * @expectedException InvalidArgumentException
   */
  public function testCannotHavePromptAndApprovalPrompt()
  {
    $o = new OAuth2([
        'authorizationUri' => 'https://accounts.test.org/auth/url',
        'clientId' => 'aClientID'
    ]);
    $o->buildFullAuthorizationUri([
        'approvalPrompt' => 'an approval prompt',
        'prompt' => 'a prompt',
    ]);
  }

  /**
   * @expectedException InvalidArgumentException
   */
  public function testCannotHaveInsecureAuthorizationUri()
  {
    $o = new OAuth2([
        'authorizationUri' => 'http://accounts.test.org/insecure/url',
        'redirectUri' => 'https://accounts.test.org/redirect/url',
        'clientId' => 'aClientID'
    ]);
    $o->buildFullAuthorizationUri();
  }

  /**
   * @expectedException InvalidArgumentException
   */
  public function testCannotHaveRelativeRedirectUri()
  {
    $o = new OAuth2([
        'authorizationUri' => 'http://accounts.test.org/insecure/url',
        'redirectUri' => '/redirect/url',
        'clientId' => 'aClientID'
    ]);
    $o->buildFullAuthorizationUri();
  }

  public function testHasDefaultXXXTypeParams()
  {
    $o = new OAuth2($this->minimal);
    $q = $o->buildFullAuthorizationUri()->getQuery();
    $this->assertEquals('code', $q->get('response_type'));
    $this->assertEquals('offline', $q->get('access_type'));
  }

  public function testCanBeUrlObject()
  {
    $config = array_merge($this->minimal, [
        'authorizationUri' => Url::fromString('https://another/uri')
    ]);
    $o = new OAuth2($config);
    $this->assertEquals('/uri', $o->buildFullAuthorizationUri()->getPath());
  }

  public function testCanOverrideParams()
  {
    $overrides = [
        'access_type' => 'o_access_type',
        'client_id' => 'o_client_id',
        'redirect_uri' => 'o_redirect_uri',
        'response_type' => 'o_response_type',
        'state' => 'o_state',
    ];
    $config = array_merge($this->minimal, ['state' => 'the_state']);
    $o = new OAuth2($config);
    $q = $o->buildFullAuthorizationUri($overrides)->getQuery();
    $this->assertEquals('o_access_type', $q->get('access_type'));
    $this->assertEquals('o_client_id', $q->get('client_id'));
    $this->assertEquals('o_redirect_uri', $q->get('redirect_uri'));
    $this->assertEquals('o_response_type', $q->get('response_type'));
    $this->assertEquals('o_state', $q->get('state'));
  }

  public function testIncludesTheScope()
  {
    $with_strings = array_merge($this->minimal, ['scope' => 'scope1 scope2']);
    $o = new OAuth2($with_strings);
    $q = $o->buildFullAuthorizationUri()->getQuery();
    $this->assertEquals('scope1 scope2', $q->get('scope'));

    $with_array = array_merge($this->minimal, [
        'scope' => ['scope1', 'scope2']
    ]);
    $o = new OAuth2($with_array);
    $q = $o->buildFullAuthorizationUri()->getQuery();
    $this->assertEquals('scope1 scope2', $q->get('scope'));
  }

}

class OAuth2GrantTypeTest extends \PHPUnit_Framework_TestCase
{
  private $minimal = [
      'authorizationUri' => 'https://accounts.test.org/insecure/url',
      'redirectUri' => 'https://accounts.test.org/redirect/url',
      'clientId' => 'aClientID'
  ];

  public function testReturnsNullIfCannotBeInferred()
  {
    $o = new OAuth2($this->minimal);
    $this->assertNull($o->getGrantType());
  }

  public function testInfersAuthorizationCode()
  {
    $o = new OAuth2($this->minimal);
    $o->setCode('an auth code');
    $this->assertEquals('authorization_code', $o->getGrantType());
  }

  public function testInfersRefreshToken()
  {
    $o = new OAuth2($this->minimal);
    $o->setRefreshToken('a refresh token');
    $this->assertEquals('refresh_token', $o->getGrantType());
  }

  public function testInfersPassword()
  {
    $o = new OAuth2($this->minimal);
    $o->setPassword('a password');
    $o->setUsername('a username');
    $this->assertEquals('password', $o->getGrantType());
  }

  public function testInfersJwtBearer()
  {
    $o = new OAuth2($this->minimal);
    $o->setIssuer('an issuer');
    $o->setSigningKey('a key');
    $this->assertEquals('urn:ietf:params:oauth:grant-type:jwt-bearer',
                        $o->getGrantType());
  }

  public function testSetsKnownTypes()
  {
    $o = new OAuth2($this->minimal);
    foreach (OAuth2::$knownGrantTypes as $t) {
      $o->setGrantType($t);
      $this->assertEquals($t, $o->getGrantType());
    }
  }

  public function testSetsUrlAsGrantType()
  {
    $o = new OAuth2($this->minimal);
    $o->setGrantType('http://a/grant/url');
    $this->assertInstanceOf('GuzzleHttp\Url', $o->getGrantType());
    $this->assertEquals('http://a/grant/url', strval($o->getGrantType()));
  }
}

class OAuth2TimingTest extends \PHPUnit_Framework_TestCase
{
  private $minimal = [
      'authorizationUri' => 'https://accounts.test.org/insecure/url',
      'redirectUri' => 'https://accounts.test.org/redirect/url',
      'clientId' => 'aClientID'
  ];

  public function testIssuedAtDefaultsToNull()
  {
    $o = new OAuth2($this->minimal);
    $this->assertNull($o->getIssuedAt());
  }

  public function testExpiresAtDefaultsToNull()
  {
    $o = new OAuth2($this->minimal);
    $this->assertNull($o->getExpiresAt());
  }

  public function testExpiresInDefaultsToNull()
  {
    $o = new OAuth2($this->minimal);
    $this->assertNull($o->getExpiresIn());
  }

  public function testSettingExpiresInSetsIssuedAt()
  {
    $o = new OAuth2($this->minimal);
    $this->assertNull($o->getIssuedAt());
    $aShortWhile = 5;
    $o->setExpiresIn($aShortWhile);
    $this->assertEquals($aShortWhile, $o->getExpiresIn());
    $this->assertNotNull($o->getIssuedAt());
  }

  public function testSettingExpiresInSetsExpireAt()
  {
    $o = new OAuth2($this->minimal);
    $this->assertNull($o->getExpiresAt());
    $aShortWhile = 5;
    $o->setExpiresIn($aShortWhile);
    $this->assertNotNull($o->getExpiresAt());
    $this->assertEquals($aShortWhile, $o->getExpiresAt() - $o->getIssuedAt());
  }

  public function testIsNotExpiredByDefault()
  {
    $o = new OAuth2($this->minimal);
    $this->assertFalse($o->isExpired());
  }

  public function testIsNotExpiredIfExpiresAtIsOld()
  {
    $o = new OAuth2($this->minimal);
    $o->setExpiresAt(time() - 2);
    $this->assertTrue($o->isExpired());
  }
}

class OAuth2GeneralTest extends \PHPUnit_Framework_TestCase
{
  private $minimal = [
      'authorizationUri' => 'https://accounts.test.org/insecure/url',
      'redirectUri' => 'https://accounts.test.org/redirect/url',
      'clientId' => 'aClientID'
  ];

  /**
   * @expectedException InvalidArgumentException
   */
  public function testFailsOnUnknownSigningAlgorithm()
  {
    $o = new OAuth2($this->minimal);
    $o->setSigningAlgorithm('this is definitely not an algorithm name');
  }

  public function testAllowsKnownSigningAlgorithms()
  {
    $o = new OAuth2($this->minimal);
    foreach (OAuth2::$knownSigningAlgorithms as $a) {
      $o->setSigningAlgorithm($a);
      $this->assertEquals($a, $o->getSigningAlgorithm());
    }
  }
}

class OAuth2JwtTest extends \PHPUnit_Framework_TestCase
{
  private $signingMinimal = [
      'signingKey' => 'example_key',
      'signingAlgorithm' => 'HS256',
      'scope' => 'https://www.googleapis.com/auth/userinfo.profile',
      'issuer' => 'app@example.com',
      'audience' => 'accounts.google.com',
      'clientId' => 'aClientID'
  ];

  /**
   * @expectedException DomainException
   */
  public function testFailsWithMissingAudience()
  {
    $testConfig = $this->signingMinimal;
    unset($testConfig['audience']);
    $o = new OAuth2($testConfig);
    $o->toJwt();
  }

  /**
   * @expectedException DomainException
   */
  public function testFailsWithMissingIssuer()
  {
    $testConfig = $this->signingMinimal;
    unset($testConfig['issuer']);
    $o = new OAuth2($testConfig);
    $o->toJwt();
  }

  /**
   * @expectedException DomainException
   */
  public function testFailsWithMissingScope()
  {
    $testConfig = $this->signingMinimal;
    unset($testConfig['scope']);
    $o = new OAuth2($testConfig);
    $o->toJwt();
  }

  /**
   * @expectedException DomainException
   */
  public function testFailsWithMissingSigningKey()
  {
    $testConfig = $this->signingMinimal;
    unset($testConfig['signingKey']);
    $o = new OAuth2($testConfig);
    $o->toJwt();
  }

  /**
   * @expectedException DomainException
   */
  public function testFailsWithMissingSigningAlgorithm()
  {
    $testConfig = $this->signingMinimal;
    unset($testConfig['signingAlgorithm']);
    $o = new OAuth2($testConfig);
    $o->toJwt();
  }

  public function testCanHS256EncodeAValidPayload()
  {
    $testConfig = $this->signingMinimal;
    $o = new OAuth2($testConfig);
    $payload = $o->toJwt();
    $roundTrip = JWT::decode($payload, $testConfig['signingKey']) ;
    $this->assertEquals($roundTrip->iss, $testConfig['issuer']);
    $this->assertEquals($roundTrip->aud, $testConfig['audience']);
    $this->assertEquals($roundTrip->scope, $testConfig['scope']);
  }

  public function testCanRS256EncodeAValidPayload()
  {
    $publicKey = file_get_contents(__DIR__ . '/fixtures' . '/public.pem');
    $privateKey = file_get_contents(__DIR__ . '/fixtures' . '/private.pem');
    $testConfig = $this->signingMinimal;
    $o = new OAuth2($testConfig);
    $o->setSigningAlgorithm('RS256');
    $o->setSigningKey($privateKey);
    $payload = $o->toJwt();
    $roundTrip = JWT::decode($payload, $publicKey) ;
    $this->assertEquals($roundTrip->iss, $testConfig['issuer']);
    $this->assertEquals($roundTrip->aud, $testConfig['audience']);
    $this->assertEquals($roundTrip->scope, $testConfig['scope']);
  }
}

class OAuth2GenerateAccessTokenRequestTest extends \PHPUnit_Framework_TestCase
{
  private $tokenRequestMinimal = [
      'tokenCredentialUri' => 'https://tokens_r_us/test',
      'scope' => 'https://www.googleapis.com/auth/userinfo.profile',
      'issuer' => 'app@example.com',
      'audience' => 'accounts.google.com',
      'clientId' => 'aClientID'
  ];

  /**
   * @expectedException DomainException
   */
  public function testFailsIfNoTokenCredentialUri()
  {
    $testConfig = $this->tokenRequestMinimal;
    unset($testConfig['tokenCredentialUri']);
    $o = new OAuth2($testConfig);
    $o->generateCredentialsRequest();
  }

  /**
   * @expectedException DomainException
   */
  public function testFailsIfAuthorizationCodeIsMissing()
  {
    $testConfig = $this->tokenRequestMinimal;
    $testConfig['redirectUri'] = 'https://has/redirect/uri';
    $o = new OAuth2($testConfig);
    $o->generateCredentialsRequest();
  }

  public function testGeneratesAuthorizationCodeRequests()
  {
    $testConfig = $this->tokenRequestMinimal;
    $testConfig['redirectUri'] = 'https://has/redirect/uri';
    $o = new OAuth2($testConfig);
    $o->setCode('an_auth_code');

    // Generate the request and confirm that it's correct.
    $req = $o->generateCredentialsRequest();
    $this->assertInstanceOf('GuzzleHttp\Message\RequestInterface', $req);
    $this->assertEquals('POST', $req->getMethod());
    $fields = $req->getBody()->getFields();
    $this->assertEquals('authorization_code', $fields['grant_type']);
    $this->assertEquals('an_auth_code', $fields['code']);
  }

  public function testGeneratesPasswordRequests()
  {
    $testConfig = $this->tokenRequestMinimal;
    $o = new OAuth2($testConfig);
    $o->setUsername('a_username');
    $o->setPassword('a_password');

    // Generate the request and confirm that it's correct.
    $req = $o->generateCredentialsRequest();
    $this->assertInstanceOf('GuzzleHttp\Message\RequestInterface', $req);
    $this->assertEquals('POST', $req->getMethod());
    $fields = $req->getBody()->getFields();
    $this->assertEquals('password', $fields['grant_type']);
    $this->assertEquals('a_password', $fields['password']);
    $this->assertEquals('a_username', $fields['username']);
  }

  public function testGeneratesRefreshTokenRequests()
  {
    $testConfig = $this->tokenRequestMinimal;
    $o = new OAuth2($testConfig);
    $o->setRefreshToken('a_refresh_token');

    // Generate the request and confirm that it's correct.
    $req = $o->generateCredentialsRequest();
    $this->assertInstanceOf('GuzzleHttp\Message\RequestInterface', $req);
    $this->assertEquals('POST', $req->getMethod());
    $fields = $req->getBody()->getFields();
    $this->assertEquals('refresh_token', $fields['grant_type']);
    $this->assertEquals('a_refresh_token', $fields['refresh_token']);
  }

  public function testGeneratesAssertionRequests()
  {
    $testConfig = $this->tokenRequestMinimal;
    $o = new OAuth2($testConfig);
    $o->setSigningKey('a_key');
    $o->setSigningAlgorithm('HS256');

    // Generate the request and confirm that it's correct.
    $req = $o->generateCredentialsRequest();
    $this->assertInstanceOf('GuzzleHttp\Message\RequestInterface', $req);
    $this->assertEquals('POST', $req->getMethod());
    $fields = $req->getBody()->getFields();
    $this->assertEquals(OAuth2::JWT_URN, $fields['grant_type']);
    $this->assertTrue(array_key_exists('assertion', $fields));
  }

  public function testGeneratesExtendedRequests()
  {
    $testConfig = $this->tokenRequestMinimal;
    $o = new OAuth2($testConfig);
    $o->setGrantType('urn:my_test_grant_type');
    $o->setExtensionParams(['my_param' => 'my_value']);

    // Generate the request and confirm that it's correct.
    $req = $o->generateCredentialsRequest();
    $this->assertInstanceOf('GuzzleHttp\Message\RequestInterface', $req);
    $this->assertEquals('POST', $req->getMethod());
    $fields = $req->getBody()->getFields();
    $this->assertEquals('my_value', $fields['my_param']);
    $this->assertEquals('urn:my_test_grant_type', $fields['grant_type']);
  }
}

class OAuth2FetchAuthTokenTest extends \PHPUnit_Framework_TestCase
{
  private $fetchAuthTokenMinimal = [
      'tokenCredentialUri' => 'https://tokens_r_us/test',
      'scope' => 'https://www.googleapis.com/auth/userinfo.profile',
      'signingKey' => 'example_key',
      'signingAlgorithm' => 'HS256',
      'issuer' => 'app@example.com',
      'audience' => 'accounts.google.com',
      'clientId' => 'aClientID'
  ];

  private function mockPluginWithCode($code)
  {
    $plugin = new Mock();
    $plugin->addResponse(new Response($code));
    return $plugin;
  }

  /**
   * @expectedException GuzzleHttp\Exception\ClientException
   */
  public function testFailsOn400()
  {
    $testConfig = $this->fetchAuthTokenMinimal;
    $client = new Client();
    $client->getEmitter()->attach($this->mockPluginWithCode(400));
    $o = new OAuth2($testConfig);
    $o->fetchAuthToken($client);
  }

  /**
   * @expectedException GuzzleHttp\Exception\ServerException
   */
  public function testFailsOn500()
  {
    $testConfig = $this->fetchAuthTokenMinimal;
    $client = new Client();
    $client->getEmitter()->attach($this->mockPluginWithCode(500));
    $o = new OAuth2($testConfig);
    $o->fetchAuthToken($client);
  }

  /**
   * @expectedException GuzzleHttp\Exception\ParseException
   */
  public function testFailsOnNoContentTypeIfResponseIsNotJSON()
  {
    $testConfig = $this->fetchAuthTokenMinimal;
    $notJson = '{"foo": , this is cannot be passed as json" "bar"}';
    $client = new Client();
    $plugin = new Mock();
    $plugin->addResponse(new Response(200, [], Stream::factory($notJson)));
    $client->getEmitter()->attach($plugin);
    $o = new OAuth2($testConfig);
    $o->fetchAuthToken($client);
  }

  public function testFetchesJsonResponseOnNoContentTypeOK()
  {
    $testConfig = $this->fetchAuthTokenMinimal;
    $json = '{"foo": "bar"}';
    $client = new Client();
    $plugin = new Mock();
    $plugin->addResponse(new Response(200, [], Stream::factory($json)));
    $client->getEmitter()->attach($plugin);
    $o = new OAuth2($testConfig);
    $tokens = $o->fetchAuthToken($client);
    $this->assertEquals($tokens['foo'], 'bar');
  }

  public function testFetchesFromFormEncodedResponseOK()
  {
    $testConfig = $this->fetchAuthTokenMinimal;
    $json = 'foo=bar&spice=nice';
    $client = new Client();
    $plugin = new Mock();
    $plugin->addResponse(new Response(
        200,
        ['Content-Type' => 'application/x-www-form-urlencoded'],
        Stream::factory($json)));
    $client->getEmitter()->attach($plugin);
    $o = new OAuth2($testConfig);
    $tokens = $o->fetchAuthToken($client);
    $this->assertEquals($tokens['foo'], 'bar');
    $this->assertEquals($tokens['spice'], 'nice');
  }

  public function testUpdatesTokenFieldsOnFetch()
  {
    $testConfig = $this->fetchAuthTokenMinimal;
    $wanted_updates = [
        'expires_at' => '1',
        'expires_in' => '57',
        'issued_at' => '2',
        'access_token' => 'an_access_token',
        'id_token' => 'an_id_token',
        'refresh_token' => 'a_refresh_token',
    ];
    $json = json_encode($wanted_updates);
    $client = new Client();
    $plugin = new Mock();
    $plugin->addResponse(new Response(200, [], Stream::factory($json)));
    $client->getEmitter()->attach($plugin);
    $o = new OAuth2($testConfig);
    $this->assertNull($o->getExpiresAt());
    $this->assertNull($o->getExpiresIn());
    $this->assertNull($o->getIssuedAt());
    $this->assertNull($o->getAccessToken());
    $this->assertNull($o->getIdToken());
    $this->assertNull($o->getRefreshToken());
    $tokens = $o->fetchAuthToken($client);
    $this->assertEquals(1, $o->getExpiresAt());
    $this->assertEquals(57, $o->getExpiresIn());
    $this->assertEquals(2, $o->getIssuedAt());
    $this->assertEquals('an_access_token', $o->getAccessToken());
    $this->assertEquals('an_id_token', $o->getIdToken());
    $this->assertEquals('a_refresh_token', $o->getRefreshToken());
  }
}