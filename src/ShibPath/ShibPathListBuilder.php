<?php

namespace Drupal\shibboleth\ShibPath;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;

/**
 * Provides a listing of protected paths.
 */
class ShibPathListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['label'] = $this->t('Label');
    $header['path'] = $this->t('Path');
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
    $row['path'] = $entity->get('path');
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
