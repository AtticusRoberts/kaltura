<?php

namespace Drupal\kaltura\Plugin\media\Source;

use Drupal\Component\Plugin\Derivative\DeriverBase;

/**
 * Derives media source plugin definitions for supported oEmbed providers.
 *
 * @internal
 *   This is an internal part of the oEmbed system and should only be used by
 *   oEmbed-related code in Drupal core.
 */
class KalturaDeriver extends DeriverBase {

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    $this->derivatives = [
      'video' => [
        'id' => 'video',
        'label' => t('Kaltura'),
        'description' => t('Use remote video URL for reusable media.'),
        'providers' => ['YouTube', 'Vimeo', 'Kaltura'],
        'default_thumbnail_filename' => 'video.png',
      ] + $base_plugin_definition,
    ];
    return parent::getDerivativeDefinitions($base_plugin_definition);
  }

}
