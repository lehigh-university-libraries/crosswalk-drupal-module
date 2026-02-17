<?php

namespace Drupal\crosswalk\Plugin\Block;

/**
 * Renders schema.org JSON-LD via the crosswalk CLI.
 *
 * @Block(
 *   id = "crosswalk_schema",
 *   admin_label = @Translation("Crosswalk Schema.org"),
 *   category = @Translation("Crosswalk"),
 * )
 */
class CrosswalkSchemaBlock extends CrosswalkBlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $node = $this->getCurrentNode();
    if (!$node) {
      return [];
    }

    $output = $this->convert($node, 'schemaorg');
    if ($output === NULL) {
      return [];
    }

    $build = [
      '#type' => 'html_tag',
      '#tag' => 'script',
      '#attributes' => ['type' => 'application/ld+json'],
      '#value' => $output,
    ];

    $this->applyCacheMetadata($build, $node);

    return $build;
  }

}
