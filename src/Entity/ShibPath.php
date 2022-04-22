<?php

namespace Drupal\shibboleth\Entity;

use Drupal\Core\Config\ConfigValueException;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\shibboleth\ShibPath\ShibPathInterface;

/**
 * Defines the protected path entity type.
 *
 * @ConfigEntityType(
 *   id = "shib_path",
 *   label = @Translation("Protected path"),
 *   label_collection = @Translation("Protected paths"),
 *   label_singular = @Translation("protected path"),
 *   label_plural = @Translation("protected paths"),
 *   label_count = @PluralTranslation(
 *     singular = "@count protected path",
 *     plural = "@count protected paths",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\shibboleth\ShibPath\ShibPathListBuilder",
 *     "storage" = "Drupal\shibboleth\ShibPath\ShibPathStorage",
 *     "form" = {
 *       "add" = "Drupal\shibboleth\Form\ShibPathForm",
 *       "edit" = "Drupal\shibboleth\Form\ShibPathForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *     }
 *   },
 *   config_prefix = "shib_path",
 *   admin_permission = "administer shibboleth",
 *   links = {
 *     "collection" = "/admin/config/people/shibboleth/paths",
 *     "add-form" = "/admin/config/people/shibboleth/paths/add",
 *     "edit-form" = "/admin/config/people/shibboleth/paths/{shib_path}",
 *     "delete-form" = "/admin/config/people/shibboleth/paths/{shib_path}/delete",
 *     "enable" = "/admin/config/people/shibboleth/paths/{shib_path}/enable",
 *     "disable" = "/admin/config/people/shibboleth/paths/{shib_path}/disable",
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "path",
 *     "criteria_type",
 *     "criteria",
 *     "status",
 *     "locked"
 *   }
 * )
 */
class ShibPath extends ConfigEntityBase implements ShibPathInterface {

  /**
   * The protected path ID.
   *
   * @var string
   */
  protected $id;

  /**
   * The protected path label.
   *
   * @var string
   */
  protected $label;

  /**
   * The protected path pattern.
   *
   * @var string
   */
  protected $path;

  /**
   * The criteria type. Associated with a Shibboleth attribute.
   *
   * @var string
   */
  protected $criteria_type;

  /**
   * The value(s) of the criteria type that can access the path.
   *
   * @var string
   */
  protected $criteria;

  /**
   * The protected path status.
   *
   * @var bool
   */
  protected $status;

  /**
   * The path is locked to editing and deletion.
   *
   * @var bool
   */
  protected $locked;


  private $criteria_list;

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);

    // Validate the path.
    // Ensure that the path is internal
    // Ensure that the path starts with a slash (/)
    $path_regex = '/^\//i';
    if (!preg_match($path_regex, $this->path)) {
      // throw new ConfigValueException('')
    }
    // @todo Check if path is unique
  }

  public function getCriteria($asArray = TRUE) {

    if (!$asArray) {
      return $this->criteria;
    }

    if (!isset($this->criteria_list) && !empty($this->criteria)) {
      $this->setCriteriaList();
    }
    return $this->criteria_list;
  }

  private function setCriteriaList() {
    $criteria_list = [];
    if (!empty($this->criteria)) {
      $criteria_list = explode("\n\r", $this->criteria);
    }
    $this->criteria_list = $criteria_list;
  }

}
