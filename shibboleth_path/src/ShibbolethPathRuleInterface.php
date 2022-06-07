<?php

namespace Drupal\shibboleth_path;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;

/**
 * Provides an interface defining a Shibboleth protected path rule entity type.
 */
interface ShibbolethPathRuleInterface extends ConfigEntityInterface {

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage);

  /**
   * Gets the criteria for the ShibbolethPathRule.
   *
   * @param bool $asArray
   *   Opt to get the return value as an array instead of a string.
   *
   * @return string[]|string
   */
  public function getCriteria(bool $asArray = TRUE);
}
