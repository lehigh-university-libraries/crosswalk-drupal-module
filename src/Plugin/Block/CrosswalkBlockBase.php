<?php

namespace Drupal\crosswalk\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\crosswalk\EntityEnricher;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Base class for crosswalk conversion blocks.
 */
abstract class CrosswalkBlockBase extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected RouteMatchInterface $routeMatch;

  /**
   * The serializer service.
   *
   * @var \Symfony\Component\Serializer\SerializerInterface
   */
  protected SerializerInterface $serializer;

  /**
   * The entity enricher.
   *
   * @var \Drupal\crosswalk\EntityEnricher
   */
  protected EntityEnricher $enricher;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RouteMatchInterface $route_match, SerializerInterface $serializer, EntityEnricher $enricher) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->routeMatch = $route_match;
    $this->serializer = $serializer;
    $this->enricher = $enricher;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match'),
      $container->get('serializer'),
      $container->get('crosswalk.entity_enricher'),
    );
  }

  /**
   * Gets the current node from the route.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The node entity, or NULL if not on a node page.
   */
  protected function getCurrentNode(): ?NodeInterface {
    $node = $this->routeMatch->getParameter('node');
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Runs a crosswalk convert command for the given format.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to convert.
   * @param string $format
   *   The output format (e.g. schemaorg, bibtex, csl).
   *
   * @return string|null
   *   The command output, or NULL on failure.
   */
  protected function convert(NodeInterface $node, string $format): ?string {
    $json = $this->serializer->serialize($node, 'json');
    $json = $this->enricher->enrich($json);

    $process = new Process([
      'crosswalk',
      'convert',
      'drupal',
      $format,
      '--profile',
      'default',
    ]);
    $process->setInput($json);
    $process->run();

    if (!$process->isSuccessful()) {
      return NULL;
    }

    return $process->getOutput();
  }

  /**
   * Applies node-based cache metadata to a render array.
   *
   * @param array &$build
   *   The render array.
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   */
  protected function applyCacheMetadata(array &$build, NodeInterface $node): void {
    $cache = new CacheableMetadata();
    $cache->addCacheableDependency($node);
    $cache->addCacheContexts(['route']);
    $cache->applyTo($build);
  }

}
