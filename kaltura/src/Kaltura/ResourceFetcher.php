<?php

namespace Drupal\kaltura\Kaltura;

use Drupal\kaltura\Plugin\media\Source\Kaltura;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Cache\UseCacheBackendTrait;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Kaltura\Client\Configuration as KalturaConfiguration;
use Kaltura\Client\Client as KalturaClient;
use Kaltura\Client\Enum\SessionType;
use Kaltura\Client\ApiException;
/**
 * Fetches and caches oEmbed resources.
 */
class ResourceFetcher implements ResourceFetcherInterface {

  use UseCacheBackendTrait;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The oEmbed provider repository service.
   *
   * @var \Drupal\kaltura\Kaltura\ProviderRepositoryInterface
   */
  protected $providers;

  /**
   * Constructs a ResourceFetcher object.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\kaltura\Kaltura\ProviderRepositoryInterface $providers
   *   The oEmbed provider repository service.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   (optional) The cache backend.
   */
  public function __construct(ClientInterface $http_client, ConfigFactoryInterface $config_factory, ProviderRepositoryInterface $providers, CacheBackendInterface $cache_backend = NULL) {
    $this->httpClient = $http_client;
    $this->providers = $providers;
    $this->cacheBackend = $cache_backend;
    $this->useCaches = isset($cache_backend);
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchResource($rawurl) {
    // $this->configFactory->getEditable('kaltura.settings')->set('oembed_providers_url','test')->save(TRUE);
    $userId = $this->configFactory->getEditable('kaltura.settings')->get('username');
    $secret = $this->configFactory->getEditable('kaltura.settings')->get('password');
    // throw new ResourceException($user, "test");
    $partner='/\/p\/(.+)\/sp\//';
    $entry='/entry_id=(.+)\" width/';
    $form='/oembed?url=(.+)/';
    preg_match($partner,urldecode(substr($rawurl,34)),$parse);
    $partnerId = $parse[1];
    preg_match($entry,urldecode(substr($rawurl,34)),$parse);
    $entryId = $parse[1];
    $config = new KalturaConfiguration($partnerId);
    $config->setServiceUrl('https://www.kaltura.com');
    $client = new KalturaClient($config);
    // $secret = "bc6d926d23c48bce39d089919b16c6cf";
    // $userId = "latticusroberts@gmail.com";
    $type = SessionType::USER;
    $expiry = 86400;
    $privileges = "";
    try {
      $ks = $client->getSessionService()->start($secret, $userId, $type, $partnerId, $expiry, $privileges);
    }
    catch (ApiException $e) {
      throw new ResourceException("Invalid login", $userId);
    }
    $url = "http://www.kaltura.com/api_v3/index.php?service=media&action=get&entryId=".$entryId."&ks=".$ks;
    $cache_id = "media:oembed_resource:$url";
    $cached = $this->cacheGet($cache_id);
    if ($cached) {
      return $this->createResource($cached->data, $url);
    }
    try {
      $response = $this->httpClient->get($url);
    }
    catch (RequestException $e) {
      throw new ResourceException('Could not retrieve the oEmbed resource.', $url, [], $e);
    }

    list($format) = $response->getHeader('Content-Type');
    $content = (string) $response->getBody();

    if (strstr($format, 'text/xml') || strstr($format, 'application/xml')) {
      $data = $this->parseResourceXml($content, $url);
    }
    elseif (strstr($format, 'text/javascript') || strstr($format, 'application/json')) {
      $data = Json::decode($content);
    }
    // If the response is neither XML nor JSON, we are in bat country.
    else {
      throw new ResourceException('The fetched resource did not have a valid Content-Type header.', $url);
    }
    $data['provider_name'] = 'Kaltura';
    $data['thumbnailUrl'] = $data['thumbnailUrl'].'.jpeg';
    $data['type'] = Resource::TYPE_VIDEO;
    $data['title'] = $data['name'];
    $data['html'] = str_replace("\"","'",urldecode(substr($rawurl,34)));
    $data['thumbnail_width'] = 120;
    $data['thumbnail_height'] = 68;
    if (isset($data['width'])) $data['width'] = 500;
    if (isset($data['height'])) $data['height'] = 500;
    $this->cacheSet($cache_id, $data);
    return $this->createResource($data, $url);
  }

  /**
   * Creates a Resource object from raw resource data.
   *
   * @param array $data
   *   The resource data returned by the provider.
   * @param string $url
   *   The URL of the resource.
   *
   * @return \Drupal\kaltura\Kaltura\Resource
   *   A value object representing the resource.
   *
   * @throws \Drupal\kaltura\Kaltura\ResourceException
   *   If the resource cannot be created.
   */
  protected function createResource(array $data, $url) {
    $data += [
      'title' => NULL,
      'author_name' => NULL,
      'author_url' => NULL,
      'provider_name' => NULL,
      'cache_age' => NULL,
      'thumbnailUrl' => NULL,
      'thumbnail_width' => NULL,
      'thumbnail_height' => NULL,
      'width' => NULL,
      'height' => NULL,
      'url' => NULL,
      'html' => NULL,
      'version' => NULL,
    ];

    // if ($data['version'] !== '1.0') {
    //   throw new ResourceException("Resource version must be '1.0'", $url, $data);
    // }

    // Prepare the arguments to pass to the factory method.
    $provider = $data['provider_name'] ? $this->providers->get($data['provider_name']) : NULL;

    // The Resource object will validate the data we create it with and throw an
    // exception if anything looks wrong. For better debugging, catch those
    // exceptions and wrap them in a more specific and useful exception.
    try {
      switch ($data['type']) {
        case Resource::TYPE_LINK:
          return Resource::link(
            $data['url'],
            $provider,
            $data['title'],
            $data['author_name'],
            $data['author_url'],
            $data['cache_age'],
            $data['thumbnailUrl'],
            $data['thumbnail_width'],
            $data['thumbnail_height']
          );

        case Resource::TYPE_PHOTO:
          return Resource::photo(
            $data['url'],
            $data['width'],
            $data['height'],
            $provider,
            $data['title'],
            $data['author_name'],
            $data['author_url'],
            $data['cache_age'],
            $data['thumbnail_url'],
            $data['thumbnail_width'],
            $data['thumbnail_height']
          );

        case Resource::TYPE_RICH:
          return Resource::rich(
            $data['html'],
            $data['width'],
            $data['height'],
            $provider,
            $data['title'],
            $data['author_name'],
            $data['author_url'],
            $data['cache_age'],
            $data['thumbnailUrl'],
            $data['thumbnail_width'],
            $data['thumbnail_height']
          );
        case Resource::TYPE_VIDEO:
          return Resource::video(
            $data['html'],
            $data['width'],
            $data['height'],
            $provider,
            $data['title'],
            $data['author_name'],
            $data['author_url'],
            $data['cache_age'],
            $data['thumbnailUrl'],
            $data['thumbnail_width'],
            $data['thumbnail_height']
          );

        default:
          throw new ResourceException('Unknown resource type: ' . $data['type'], $url, $data);
      }
    }
    catch (\InvalidArgumentException $e) {
      throw new ResourceException($e->getMessage(), $url, $data, $e);
    }
  }
  /**
   * Parses XML resource data.
   *
   * @param string $data
   *   The raw XML for the resource.
   * @param string $url
   *   The resource URL.
   *
   * @return array
   *   The parsed resource data.
   *
   * @throws \Drupal\kaltura\Kaltura\ResourceException
   *   If the resource data could not be parsed.
   */
  protected function parseResourceXml($data, $url) {
    // Enable userspace error handling.
    $was_using_internal_errors = libxml_use_internal_errors(TRUE);
    libxml_clear_errors();

    $content = simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NOCDATA);
    // Restore the previous error handling behavior.
    libxml_use_internal_errors($was_using_internal_errors);

    $error = libxml_get_last_error();
    if ($error) {
      libxml_clear_errors();
      throw new ResourceException($error->message, $url);
    }
    elseif ($content === FALSE) {
      throw new ResourceException('The fetched resource could not be parsed.', $url);
    }

    // Convert XML to JSON so that the parsed resource has a consistent array
    // structure, regardless of any XML attributes or quirks of the XML parser.
    $data = Json::encode($content);
    return Json::decode($data)['result'];
  }

}
