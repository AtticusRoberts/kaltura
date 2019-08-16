<?php

namespace Drupal\kaltura\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\kaltura\Kaltura\ProviderException;
use Drupal\kaltura\Kaltura\ResourceException;
use Drupal\kaltura\Kaltura\ResourceFetcherInterface;
use Drupal\kaltura\Kaltura\UrlResolverInterface;
use Drupal\kaltura\Plugin\media\Source\KalturaInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates kaltura resource URLs.
 *
 * @internal
 *   This is an internal part of the oEmbed system and should only be used by
 *   oEmbed-related code in Drupal core.
 */
class KalturaResourceConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * The kaltura URL resolver service.
   *
   * @var \Drupal\kaltura\Kaltura\UrlResolverInterface
   */
  protected $urlResolver;

  /**
   * The resource fetcher service.
   *
   * @var \Drupal\kaltura\Kaltura\ResourceFetcherInterface
   */
  protected $resourceFetcher;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a new KalturaResourceConstraintValidator.
   *
   * @param \Drupal\kaltura\Kaltura\UrlResolverInterface $url_resolver
   *   The oEmbed URL resolver service.
   * @param \Drupal\kaltura\Kaltura\ResourceFetcherInterface $resource_fetcher
   *   The resource fetcher service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger service.
   */
  public function __construct(UrlResolverInterface $url_resolver, ResourceFetcherInterface $resource_fetcher, LoggerChannelFactoryInterface $logger_factory) {
    $this->urlResolver = $url_resolver;
    $this->resourceFetcher = $resource_fetcher;
    $this->logger = $logger_factory->get('media');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('kaltura.kaltura.url_resolver'),
      $container->get('kaltura.kaltura.resource_fetcher'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate($value, Constraint $constraint) {
    /** @var \Drupal\media\MediaInterface $media */

    $media = $value->getEntity();
    /** @var \Drupal\kaltura\Plugin\media\Source\KalturaInterface $source */
    $source = $media->getSource();
    if (!($source instanceof KalturaInterface)) {
      throw new \LogicException('Media source must implement ' . KalturaInterface::class);
    }
    $url = $source->getSourceFieldValue($media);

    // Ensure that the URL matches a provider.
    try {
      $provider = $this->urlResolver->getProviderByUrl($url);
    }
    catch (ResourceException $e) {
      $this->handleException($e, $constraint->unknownProviderMessage);
      return;
    }
    catch (ProviderException $e) {
      $this->handleException($e, $constraint->providerErrorMessage);
      return;
    }

    // Ensure that the provider is allowed.
    if (!in_array($provider->getName(), $source->getProviders(), TRUE)) {
      $this->context->addViolation($constraint->disallowedProviderMessage, [
        '@name' => $provider->getName(),
      ]);
      return;
    }

    // Verify that resource fetching works, because some URLs might match
    // the schemes but don't support oEmbed.
    try {
      $endpoints = $provider->getEndpoints();
      $resource_url = reset($endpoints)->buildResourceUrl($url);
      try {
        $this->resourceFetcher->fetchResource($resource_url);
      }
      catch(ResourceException $e) {
        $this->handleException($e, $constraint->invalidLoginMessage);
      }
    }
    catch (ResourceException $e) {
      $this->handleException($e, $constraint->invalidResourceMessage);
    }
  }

  /**
   * Handles exceptions that occur during validation.
   *
   * @param \Exception $e
   *   The caught exception.
   * @param string $error_message
   *   (optional) The error message to set as a constraint violation.
   */
  protected function handleException(\Exception $e, $error_message = NULL) {
    if ($error_message) {
      $this->context->addViolation($error_message);
    }

    // The oEmbed system makes heavy use of exception wrapping, so log the
    // entire exception chain to help with troubleshooting.
    do {
      // @todo If $e is a ProviderException or ResourceException, log additional
      // debugging information contained in those exceptions in
      // https://www.drupal.org/project/drupal/issues/2972846.
      $this->logger->error($e->getMessage());
      $e = $e->getPrevious();
    } while ($e);
  }

}
