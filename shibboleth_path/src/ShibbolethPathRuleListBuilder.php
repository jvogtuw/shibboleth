<?php

namespace Drupal\shibboleth_path;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;

/**
 * Provides a listing of Shibboleth protected path rules.
 */
class ShibbolethPathRuleListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['label'] = $this->t('Label');
    $header['pattern'] = $this->t('Pattern');
    // $header['criteria_type'] = $this->t('Criteria type');
    $header['criteria'] = $this->t('Criteria');
    $header['status'] = $this->t('Status');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\shibboleth\ShibPath\ShibPathInterface $entity */
    $row['label'] = $entity->label();
    $row['pattern'] = $entity->get('pattern');
    // $row['criteria_type'] = $entity->get('criteria_type');
    $criteria = '';
    if(!empty($entity->get('criteria_type'))) {
      $criteria = $entity->get('criteria_type') . ': ' . $entity->get('criteria');
    }
    $row['criteria'] = $criteria;
    $row['status'] = $entity->status() ? $this->t('Enabled') : $this->t('Disabled');
    return $row + parent::buildRow($entity);
  }

}
