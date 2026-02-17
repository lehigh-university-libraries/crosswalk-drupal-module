<?php

namespace Drupal\crosswalk;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Enriches entity references in serialized Drupal JSON.
 *
 * Walks the JSON structure, finds entity references (objects with target_id
 * and target_type), loads the referenced entities, serializes them, and nests
 * the result under an "_entity" key. Recurses up to a configurable max depth.
 */
class EntityEnricher {

  /**
   * Maximum recursion depth for nested references.
   */
  protected int $maxDepth = 2;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The serializer service.
   *
   * @var \Symfony\Component\Serializer\SerializerInterface
   */
  protected SerializerInterface $serializer;

  /**
   * Entity types that support loading by ID.
   *
   * @var string[]
   */
  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Entity types that support loading by ID.
   *
   * @var string[]
   */
  protected static array $supportedTypes = [
    'taxonomy_term',
    'node',
    'media',
    'file',
  ];

  /**
   * Constructs an EntityEnricher.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, SerializerInterface $serializer, LoggerInterface $logger) {
    $this->entityTypeManager = $entity_type_manager;
    $this->serializer = $serializer;
    $this->logger = $logger;
  }

  /**
   * Enriches entity references in serialized JSON.
   *
   * @param string $json
   *   The serialized JSON string.
   *
   * @return string
   *   The enriched JSON string.
   */
  public function enrich(string $json): string {
    $data = json_decode($json, TRUE);
    if ($data === NULL) {
      return $json;
    }

    $enriched = $this->enrichValue($data, 0);
    return json_encode($enriched);
  }

  /**
   * Recursively enriches a value.
   */
  protected function enrichValue(mixed $value, int $depth): mixed {
    if ($depth > $this->maxDepth) {
      return $value;
    }

    if (is_string($value)) {
      return strip_tags($value);
    }

    if (is_array($value)) {
      // Check if this is an associative array (map) or a sequential array.
      if ($this->isAssociative($value)) {
        return $this->enrichMap($value, $depth);
      }
      return $this->enrichList($value, $depth);
    }

    return $value;
  }

  /**
   * Enriches an associative array by recursing into its values.
   */
  protected function enrichMap(array $map, int $depth): array {
    $result = [];
    foreach ($map as $key => $value) {
      $result[$key] = $this->enrichValue($value, $depth);
    }
    return $result;
  }

  /**
   * Enriches a sequential array, resolving entity references.
   */
  protected function enrichList(array $list, int $depth): array {
    $result = [];
    foreach ($list as $item) {
      if (is_array($item) && $this->isEntityReference($item)) {
        $enriched = $this->enrichReference($item, $depth);
        $result[] = $enriched;
      }
      else {
        $result[] = $this->enrichValue($item, $depth);
      }
    }
    return $result;
  }

  /**
   * Checks if an array looks like a Drupal entity reference.
   */
  protected function isEntityReference(array $item): bool {
    return isset($item['target_id'], $item['target_type']);
  }

  /**
   * Enriches a single entity reference by loading the referenced entity.
   */
  protected function enrichReference(array $ref, int $depth): array {
    $targetType = $ref['target_type'] ?? NULL;
    $targetId = $ref['target_id'] ?? NULL;

    if (!is_string($targetType) || !is_numeric($targetId)) {
      return $ref;
    }

    // Skip user entities — they contain sensitive data.
    if ($targetType === 'user') {
      return $ref;
    }

    if (!in_array($targetType, static::$supportedTypes, TRUE)) {
      return $ref;
    }

    try {
      $storage = $this->entityTypeManager->getStorage($targetType);
      $entity = $storage->load($targetId);
      if (!$entity) {
        return $ref;
      }

      $entityJson = $this->serializer->serialize($entity, 'json');
      $entityData = json_decode($entityJson, TRUE);
      if ($entityData === NULL) {
        return $ref;
      }

      // Recursively enrich the loaded entity.
      $enrichedEntity = $this->enrichValue($entityData, $depth + 1);

      $ref['_entity'] = $enrichedEntity;
    }
    catch (\Exception $e) {
      // Log but don't fail — keep the original reference.
      $this->logger->warning('Failed to enrich @type @id: @message', [
        '@type' => $targetType,
        '@id' => $targetId,
        '@message' => $e->getMessage(),
      ]);
    }

    return $ref;
  }

  /**
   * Checks if an array is associative (map) vs sequential (list).
   */
  protected function isAssociative(array $arr): bool {
    if (empty($arr)) {
      return FALSE;
    }
    return array_keys($arr) !== range(0, count($arr) - 1);
  }

}
