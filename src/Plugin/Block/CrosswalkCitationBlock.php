<?php

namespace Drupal\crosswalk\Plugin\Block;

/**
 * Renders an APA citation using citation.js with CSL-JSON and BibTeX data.
 *
 * @Block(
 *   id = "crosswalk_citation",
 *   admin_label = @Translation("Crosswalk Citation"),
 *   category = @Translation("Crosswalk"),
 * )
 */
class CrosswalkCitationBlock extends CrosswalkBlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $node = $this->getCurrentNode();
    if (!$node) {
      return [];
    }

    $csl = $this->convert($node, 'csl');
    $bibtex = $this->convert($node, 'bibtex');
    $schemaorg = $this->convert($node, 'schemaorg');
    if ($csl === NULL && $bibtex === NULL && $schemaorg === NULL) {
      return [];
    }

    $build = [
      '#theme' => 'crosswalk_citation',
      '#csl_json' => $csl,
      '#bibtex' => $bibtex,
      '#schemaorg' => $schemaorg,
      '#attached' => [
        'library' => [
          'crosswalk/citation',
        ],
      ],
    ];

    $this->applyCacheMetadata($build, $node);

    return $build;
  }

}
