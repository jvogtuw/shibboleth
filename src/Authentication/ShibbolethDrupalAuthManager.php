<?php

namespace Drupal\shibboleth\Authentication;

use Drupal\Component\Utility\Random;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\SessionManagerInterface;
use Drupal\user\Entity\User;
use Psr\Log\LoggerInterface;

/**
 * Handles functionality to authenticate Drupal users with Shibboleth data.
 */
class ShibbolethDrupalAuthManager {

  /**
   * User entity storage.
   *
   * @var \Drupal\user\UserStorageInterface
   */
  protected $userStorage;

  /**
   * The Shibboleth module config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The Shibboleth authentication manager.
   *
   * @var \Drupal\shibboleth\Authentication\ShibbolethAuthManager
   */
  protected $shibbolethAuthManager;

  /**
   * The Shibboleth logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The Drupal session manager.
   *
   * @var \Drupal\Core\Session\SessionManagerInterface
   */
  protected $sessionManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructor for ShibbolethDrupalAuthManager.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory interface.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager interface.
   * @param \Psr\Log\LoggerInterface $logger
   *   The Shibboleth logger channel interface.
   * @param \Drupal\shibboleth\Authentication\ShibbolethAuthManager $shib_auth_manager
   *   The Shibboleth authentication manager.
   * @param \Drupal\Core\Session\SessionManagerInterface $session_manager
   *   The session manager interface.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, LoggerInterface $logger, ShibbolethAuthManager $shib_auth_manager, SessionManagerInterface $session_manager, AccountInterface $current_user) {

    $this->config = $config_factory->get('shibboleth.settings');
    $this->userStorage = $entity_type_manager->getStorage('user');
    $this->shibbolethAuthManager = $shib_auth_manager;
    $this->logger = $logger;
    $this->sessionManager = $session_manager;
    $this->currentUser = $current_user;

    // Start Session if it does not exist yet.
    // This is an artifact from the shib_auth module. Don't know if needed.
    if ($this->currentUser->isAnonymous() && !isset($_SESSION['session_started'])) {
      $_SESSION['session_started'] = TRUE;
      $this->sessionManager->start();
    }
  }

  /**
   * Attempts to log in or register a new user for the Shibboleth user.
   *
   * @return \Drupal\user\UserInterface|false
   *   Returns the logged in Drupal user or FALSE if login failed.
   */
  public function loginRegister() {

    // No Shibboleth session.
    if (!$this->shibbolethAuthManager->sessionExists()) {
      $this->logger->error('Shibboleth login attempt failed. No Shibboleth session was found.');
      return FALSE;
    }

    // Attempt to log in the user.
    $account = $this->login();
    if ($account) {
      return $account;
    }
    elseif ($this->config->get('auto_register_user')) {
      return $this->registerUser();
    }
    return FALSE;
  }

  /**
   * Attempts to log in a Drupal user with a Shibboleth session.
   *
   * Looks for a Drupal user with its Shibboleth authname field matching that of
   * the active Shibboleth session. If a matching user is found, it attempts to
   * complete Drupal authentication for that user.
   *
   * @return bool
   *   Returns TRUE if a matching user was found and successfully logged into
   *   Drupal, FALSE otherwise.
   *
   * @throws \Exception
   */
  public function login() {

    $authname = $this->shibbolethAuthManager->getTargetedId();
    // Either there's no Shibboleth session or one exists, but no authname was
    // found. (Check the server attribute you're using.)
    if (empty($authname)) {
      $this->logger->error('Shibboleth login attempt failed. A session exists, but the authname is empty.');
      throw new \Exception('Shibboleth session exists, but the authname is empty.');
    }

    $linked_user = $this->getLinkedUser();

    // There is no Drupal user linked to the Shibboleth authname.
    if (!$linked_user) {
      $this->logger->warning('Shibboleth login attempt failed. There is no Drupal user linked to the Shibboleth authname %authname.',
        ['%authname' => $authname]);
      return FALSE;
    }

    // Log in the Drupal user.
    user_login_finalize($linked_user);
    $this->logger->notice('Shibboleth authname %authname logged in.',
      ['%authname' => $authname]);
    return $linked_user;

  }

  /**
   * Creates a new Drupal user linked to the current Shibboleth session.
   *
   * The newly created user will be logged in.
   *
   * @return \Drupal\user\UserInterface
   *   Returns the newly created user.
   */
  private function registerUser() {

    $user_data = [
      'name' => $this->shibbolethAuthManager->getTargetedId(),
      'mail' => $this->shibbolethAuthManager->getEmail(),
      'pass' => $this->genPassword(),
      'status' => 1,
      'shibboleth_authname' => $this->shibbolethAuthManager->getTargetedId(),
    ];

    try {
      // Create a new Drupal user entity.
      /** @var \Drupal\user\UserInterface $new_user */
      $new_user = $this->userStorage->create($user_data);

      // Save the new user. Throws an exception on failure, so we can assume
      // success.
      $this->userStorage->save($new_user);
      user_login_finalize($new_user);
      return $new_user;
    }
    catch (\Exception $e) {
      $this->logger->error('Unable to create a Drupal user for the Shibboleth authname %authname.', ['%authname' => $this->shibbolethAuthManager->getTargetedId()]);
    }
  }

  /**
   * Gets the Drupal user associated with the given Shibboleth authname.
   *
   * Looks for the authname in the user.shibboleth_authname field value.
   *
   * @param string $authname
   *   The Shibboleth authname.
   *
   * @return \Drupal\user\Entity\User|bool
   *   Returns a User entity if a match was found, FALSE otherwise.
   */
  public function getLinkedUser(string $authname = '') {
    $authname = !empty($authname) ? $authname : $this->shibbolethAuthManager->getTargetedId();
    $linked_user_lookup = $this->userStorage
      ->loadByProperties([
        'shibboleth_authname' => $authname,
      ]);
    $linked_user = reset($linked_user_lookup);
    return empty($linked_user) ? FALSE : User::load($linked_user->id());
  }

  /**
   * Gets the Shibboleth authname mapped to a user.
   *
   * @param string $user_id
   *   The Drupal user ID to look up.
   *
   * @return string
   *   Returns the Shibboleth authname associated with the User entity. Returns
   *   an empty string if the authname value is not set.
   */
  public function getShibbolethAuthname(string $user_id) {
    /** @var \Drupal\user\UserInterface\UserInterface $account */
    $account = $this->userStorage->load($user_id);
    // @todo Is there a better way to get the property value?
    return isset($account->get('shibboleth_authname')->getValue()[0]) ? $account->get('shibboleth_authname')->getValue()[0]['value'] : '';
  }

  /**
   * Generate a random password for a new Drupal user account.
   *
   * @return string
   *   Returns a random string.
   */
  private function genPassword() {
    $rand = new Random();
    return $rand->string(30);
  }

}
