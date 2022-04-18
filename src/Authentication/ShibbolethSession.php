<?php

namespace Drupal\shibboleth\Authentication;

use Drupal\Core\Config\ConfigFactoryInterface;

class ShibbolethSession {

  /**
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  private $config;

  /**
   * @var string
   */
  private $session_id;

  /**
   * @var string
   */
  private $targeted_id;

  /**
   * @var string
   */
  private $email;

  /**
   * @var string
   */
  private $idp;

  /**
   * @var array
   */
  private $affiliation;

  /**
   * @var array
   */
  private $groups;

  /**
   * ShibSession constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->config = $config_factory->get('shibboleth.settings');
    $this->session_id = self::fixModRewriteIssues('Shib-Session-ID');
    $this->targeted_id = self::fixModRewriteIssues($this->config->get('server_variable_username'));
    $this->email = self::fixModRewriteIssues($this->config->get('server_variable_email'));
    $this->idp = self::fixModRewriteIssues('Shib-Identity-Provider');
    $this->affiliation = $this->setAffiliation();
    $this->groups = $this->setGroups();
  }

  /**
   * @return string
   */
  public function getSessionId() {
    return $this->session_id;
  }

  /**
   * @return string
   */
  public function getTargetedId() {
    return $this->targeted_id;
  }

  /**
   * @return string
   */
  public function getEmail() {
    // Replace the outdated 'u.washington.edu' email domain with 'uw.edu'
    if (str_replace('@u.washington.edu', '', $this->email) == $this->target_id) {
      return $this->targeted_id . '@uw.edu';
    }
    return $this->email;
  }

  /**
   * @return string
   */
  public function getIdp() {
    return $this->idp;
  }

  /**
   * @return array
   */
  public function getAffiliation() {
    return $this->affiliation;
  }

  private function setAffiliation() {
    $affiliation = self::fixModRewriteIssues($this->config->get('server_variable_affiliation')) ?? self::fixModRewriteIssues('UNSCOPED_AFFILIATION');
    $this->affiliation = !empty($affiliation) ? explode(';', $affiliation) : [];
  }

  /**
   * @return array
   */
  public function getGroups() {
    return $this->groups;
  }

  private function setGroups() {
    $groups = self::fixModRewriteIssues($this->config->get('server_variable_groups')) ?? self::fixModRewriteIssues('isMemberOf');
    $groups_arr = [];
    if (!empty($groups)) {
      $groups_arr = explode(';', $groups);
      // Remove prefixes (separated by ':') from the groups to keep just the
      // group name.
      for($i = 0; $i < count($groups_arr); $i++) {
        $groups_arr[$i] = trim(substr($groups_arr[$i], strrpos($groups_arr[$i], ':') + 1));
      }
    }
    $this->groups = $groups_arr;
  }

  public function sessionExists() {
    return !empty($this->session_id);
  }

  /**
   * Get environment variables that may have been modified by mod_rewrite.
   *
   * @param $var
   *
   * @return string or null
   */
  private static function fixModRewriteIssues($var) {

    if (!$var) {
      return NULL;
    }
    // foo-bar.
    if (array_key_exists($var, $_SERVER)) {
      return $_SERVER[$var];
    }

    // FOO-BAR.
    $var = strtoupper($var);
    if (array_key_exists($var, $_SERVER)) {
      return $_SERVER[$var];
    }

    // REDIRECT_foo_bar.
    $var = "REDIRECT_" . str_replace('-', '_', $var);
    if (array_key_exists($var, $_SERVER)) {
      return $_SERVER[$var];
    }

    // HTTP_FOO_BAR.
    $var = strtoupper($var);
    $var = preg_replace('/^REDIRECT/', 'HTTP', $var);
    if (array_key_exists($var, $_SERVER)) {
      return $_SERVER[$var];
    }

    return NULL;
  }
}
