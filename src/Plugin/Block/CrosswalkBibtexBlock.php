<?php

namespace Drupal\crosswalk\Plugin\Block;

/**
 * Renders BibTeX output via the crosswalk CLI.
 *
 * @Block(
 *   id = "crosswalk_bibtex",
 *   admin_label = @Translation("Crosswalk BibTeX"),
 *   category = @Translation("Crosswalk"),
 * )
 */
class CrosswalkBibtexBlock extends CrosswalkBlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $node = $this->getCurrentNode();
    if (!$node) {
      return [];
    }

    $output = $this->convert($node, 'bibtex');
    if ($output === NULL) {
      return [];
    }

    $build = [
      '#type' => 'html_tag',
      '#tag' => 'pre',
      '#value' => htmlspecialchars($output),
      '#attributes' => ['class' => ['crosswalk-bibtex']],
    ];

    $this->applyCacheMetadata($build, $node);

    return $build;
  }

}
