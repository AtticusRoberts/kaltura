<?php

namespace Drupal\kaltura\Kaltura;

/**
 * Defines an interface for a collection of oEmbed provider information.
 *
 * The provider repository is responsible for fetching information about all
 * available oEmbed providers, most likely pulled from the online database at
 * https://oembed.com/providers.json, and creating \Drupal\kaltura\Kaltura\Provider
 * value objects for each provider.
 */
interface ProviderRepositoryInterface {

  /**
   * Returns information on all available oEmbed providers.
   *
   * @return \Drupal\kaltura\Kaltura\Provider[]
   *   Returns an array of provider value objects, keyed by provider name.
   *
   * @throws \Drupal\kaltura\Kaltura\ProviderException
   *   If the oEmbed provider information cannot be retrieved.
   */
  public function getAll();

  /**
   * Returns information for a specific oEmbed provider.
   *
   * @param string $provider_name
   *   The name of the provider.
   *
   * @return \Drupal\kaltura\Kaltura\Provider
   *   A value object containing information about the provider.
   *
   * @throws \InvalidArgumentException
   *   If there is no known oEmbed provider with the specified name.
   */
  public function get($provider_name);

}
