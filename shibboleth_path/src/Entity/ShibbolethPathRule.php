<?php

namespace Drupal\shibboleth_path\Entity;

use Drupal\Core\Config\ConfigValueException;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\shibboleth_path\ShibbolethPathRuleInterface;

/**
 * Defines the protected path entity type.
 *
 * @ConfigEntityType(
 *   id = "shibboleth_path_rule",
 *   label = @Translation("Protected path rule"),
 *   label_collection = @Translation("Protected path rules"),
 *   label_singular = @Translation("protected path rule"),
 *   label_plural = @Translation("protected path rules"),
 *   label_count = @PluralTranslation(
 *     singular = "@count protected path rule",
 *     plural = "@count protected path rules",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\shibboleth_path\ShibbolethPathRuleListBuilder",
 *     "storage" = "Drupal\shibboleth_path\ShibbolethPathRuleStorage",
 *     "form" = {
 *       "add" = "Drupal\shibboleth_path\Form\ShibbolethPathRuleForm",
 *       "edit" = "Drupal\shibboleth_path\Form\ShibbolethPathRuleForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *     }
 *   },
 *   config_prefix = "shibboleth_path_rule",
 *   admin_permission = "administer shibboleth",
 *   links = {
 *     "collection" = "/admin/config/people/shibboleth/path-rules",
 *     "add-form" = "/admin/config/people/shibboleth/path-rules/add",
 *     "edit-form" = "/admin/config/people/shibboleth/path-rules/{shibboleth_path_rule}",
 *     "delete-form" = "/admin/config/people/shibboleth/path-rules/{shibboleth_path_rule}/delete",
 *     "enable" = "/admin/config/people/shibboleth/path-rules/{shibboleth_path_rule}/enable",
 *     "disable" = "/admin/config/people/shibboleth/path-rules/{shibboleth_path_rule}/disable",
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "pattern",
 *     "criteria_type",
 *     "criteria",
 *     "status",
 *     "locked"
 *   }
 * )
 */
class ShibbolethPathRule extends ConfigEntityBase implements ShibbolethPathRuleInterface {

  /**
   * The protected path rule ID.
   *
   * @var string
   */
  protected $id;

  /**
   * The rule label.
   *
   * @var string
   */
  protected $label;

  /**
   * The protected path rule pattern.
   *
   * @var string
   */
  protected $pattern;

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
   * The rule status.
   *
   * @var bool
   */
  protected $status;

  /**
   * The rule is locked to editing and deletion.
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
    if (!preg_match($path_regex, $this->pattern)) {
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
