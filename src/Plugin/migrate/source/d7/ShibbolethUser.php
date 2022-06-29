<?php

namespace Drupal\shibboleth\Plugin\migrate\source\d7;

use Drupal\migrate\Row;
use Drupal\user\Plugin\migrate\source\d7\User as D7User;
// use Drupal\migrate_drupal\Plugin\migrate\source\DrupalSqlBase;

/**
 * Fetches user authmaps from the Drupal 7 source database.
 *
 * @MigrateSource(
 *   id = "d7_shibboleth_user",
 *   source_module = "user",
 * )
 */
class ShibbolethUser extends D7User {

  /**
   * @inheritDoc
   */
  public function fields() {
    $fields = parent::fields();
    $fields['shibboleth_authname'] = $this->t('Shibboleth authname');
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $uid = $row->getSourceProperty('uid');

    $shib_username = $this->select('authmap', 'a')
      ->fields('a', ['authname'])
      ->condition('a.uid', $uid)
      ->execute()
      ->fetchCol();
    $row->setSourceProperty('shibboleth_authname', $shib_username);

    return parent::prepareRow($row);
  }


}
