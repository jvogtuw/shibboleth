<?php

namespace Drupal\shibboleth_path;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;

/**
 * Provides an interface defining a Shibboleth protected path rule entity type.
 */
interface ShibbolethPathRuleInterface extends ConfigEntityInterface {

  public function preSave(EntityStorageInterface $storage);

  public function getCriteria($asArray = TRUE);
}
