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

    $data = json_decode($json, TRUE);
    if ($data !== NULL) {
      $url = $this->resolveUrl($data, $node);
      $data['_url'] = $url;
      $json = json_encode($data);
    }

    $process = new Process([
      'crosswalk',
      'convert',
      'drupal',
      $format,
    ]);
    $process->setInput($json);
    $process->run();

    if (!$process->isSuccessful()) {
      return NULL;
    }

    $output = $process->getOutput();

    if ($data !== NULL) {
      $output = $this->postProcessOutput($output, $format, $url);
    }

    return $output;
  }

  /**
   * Resolves the canonical URL for a node.
   *
   * Uses the DOI if available, otherwise falls back to the absolute canonical
   * node URL.
   *
   * @param array $data
   *   The enriched JSON data.
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   *
   * @return string
   *   The resolved URL.
   */
  protected function resolveUrl(array $data, NodeInterface $node): string {
    $doi = $this->extractDoi($data);
    if ($doi !== NULL) {
      return 'https://doi.org/' . $doi;
    }
    return $node->toUrl('canonical', ['absolute' => TRUE])->toString();
  }

  /**
   * Extracts a DOI value from enriched entity data.
   *
   * @param array $data
   *   The enriched JSON data.
   *
   * @return string|null
   *   The DOI string, or NULL if not found.
   */
  protected function extractDoi(array $data): ?string {
    if (empty($data['field_identifier']) || !is_array($data['field_identifier'])) {
      return NULL;
    }
    foreach ($data['field_identifier'] as $item) {
      if (is_array($item) && isset($item['attr0']) && $item['attr0'] === 'doi' && !empty($item['value'])) {
        return $item['value'];
      }
    }
    return NULL;
  }

  /**
   * Post-processes crosswalk CLI output to ensure URL is set.
   *
   * @param string $output
   *   The raw CLI output.
   * @param string $format
   *   The output format (e.g. schemaorg, csl).
   * @param string $url
   *   The resolved URL.
   *
   * @return string
   *   The post-processed output.
   */
  protected function postProcessOutput(string $output, string $format, string $url): string {
    $decoded = json_decode($output, TRUE);
    if ($decoded === NULL) {
      return $output;
    }

    if ($format === 'schemaorg') {
      $decoded['url'] = $url;
      return json_encode($decoded);
    }

    if ($format === 'csl') {
      if (array_is_list($decoded)) {
        foreach ($decoded as &$item) {
          $item['URL'] = $url;
        }
        unset($item);
      }
      else {
        $decoded['URL'] = $url;
      }
      return json_encode($decoded);
    }

    return $output;
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
