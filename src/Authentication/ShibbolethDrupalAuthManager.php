<?php

namespace Drupal\shibboleth\Authentication;

use Drupal\Component\Utility\Random;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigValueException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\SessionManagerInterface;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use mysql_xdevapi\Exception;
use Psr\Log\LoggerInterface;

// use Psr\Log\LoggerInterface;

class ShibbolethDrupalAuthManager {

  /**
   * @var
   */
  // protected $user;

  /**
   * @var \Drupal\user\UserStorageInterface
   */
  protected $userStorage;

  /**
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * @var \Drupal\shibboleth\Authentication\ShibbolethAuthManager
   */
  protected $shibAuth;

  /**
   * \Drupal\Core\Logger\LoggerChannelInterface
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * @var \Drupal\Core\Session\SessionManagerInterface
   */
  protected $sessionManager;

  /**
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  // protected $potentialUserMatch;

  /**
   * @var string
   */
  protected $errorMessage;

  /**
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;


  /**
   * LoginHandler constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface              $config_factory
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface          $entity_type_manager
   * @param \Psr\Log\LoggerInterface                                $logger
   * @param \Drupal\shibboleth\Authentication\ShibbolethAuthManager $shib_auth_manager
   * @param \Drupal\Core\Session\SessionManagerInterface            $session_manager
   * @param \Drupal\Core\Session\AccountInterface                   $current_user
   * @param \Drupal\Core\Messenger\MessengerInterface               $messenger
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, LoggerInterface $logger, ShibbolethAuthManager $shib_auth_manager, SessionManagerInterface $session_manager, AccountInterface $current_user, MessengerInterface $messenger) {
    // $this->db = $db;
    $this->config = $config_factory->get('shibboleth.settings');
    $this->userStorage = $entity_type_manager->getStorage('user');
    $this->shibAuth = $shib_auth_manager;
    $this->logger = $logger;
    // $this->temp_store_factory = $temp_store_factory;
    $this->sessionManager = $session_manager;
    $this->currentUser = $current_user;
    $this->messenger = $messenger;
    // $this->custom_data_store = $this->temp_store_factory->get('shib_auth');

    // Start Session if it does not exist yet.
    // This is an artifact from the shib_auth module. Don't know if needed.
    if ($this->currentUser->isAnonymous() && !isset($_SESSION['session_started'])) {
      $_SESSION['session_started'] = TRUE;
      $this->sessionManager->start();
    }
  }

  // public function getLoginUrl() {
  //
  // }
  //
  // public function getLogoutUrl() {
  //   return $this->shibAuth->getLoginUrl();
  // }

  /**
   * Attempts to log in or register a new user associated with the active
   * Shibboleth session.
   *
   * @return bool|\Drupal\user\UserInterface
   * @throws \Exception
   */
  public function loginRegister() {

    // No Shibboleth session
    if (!$this->shibAuth->sessionExists()) {
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
   * Looks for a Drupal user with its Shibboleth username field matching the
   * target ID (username) of the active Shibboleth session. If a matching user
   * is found, it attempts to complete Drupal authentication for that user.
   *
   * @return bool
   *   Returns TRUE if a matching user was found and successfully logged into
   *   Drupal, FALSE otherwise.
   */
  public function login() {
    $authname = $this->shibAuth->getTargetedId();

    // try {
      // Either there's no Shibboleth session or one exists, but no authname was
      // found. (Check the server attribute you're using.)
      if (empty($authname)) {
        $this->logger->error('Shibboleth login attempt failed. A session exists, but the authname is empty.');
        throw new \Exception('Shibboleth session exists, but the authname is empty.');
        // return FALSE;
      }
    // }
    // catch (\Exception $e) {
    //   $this->errorMessage = $e;
    //   return FALSE;
    // }


    // There is no user linked to the Shibboleth authname.
    $linked_user = $this->getLinkedUser($authname);
    if (!$linked_user) {
      $this->logger->warning('Shibboleth login attempt failed. There is no Drupal user linked to the Shibboleth authname %authname.', ['%authname' => $authname]);
      return FALSE;
    }

    // Log in the Drupal user.
    user_login_finalize($linked_user);
    $this->logger->notice('Shibboleth user %authname logged in.', ['%authname' => $authname]);
    return $linked_user;

  }

  /**
   * Creates a new Drupal user linked to the current Shibboleth session.
   *
   * The newly created user will be logged in.
   *
   * @return \Drupal\user\UserInterface
   */
  private function registerUser() {
    $user_data = [
      'name' => $this->shibAuth->getTargetedId(),
      'mail' => $this->shibAuth->getEmail(),
      'pass' => $this->genPassword(),
      'status' => 1,
      'shibboleth_username' => $this->shibAuth->getTargetedId(),
    ];

    try {
      // throw new ShibbolethAutoRegisterException('nope');
      // Create a new Drupal user entity.
      /** @var \Drupal\user\UserInterface $new_user */
      $new_user = $this->userStorage->create($user_data);
      // Save the new user. Throws an exception on failure, so we can assume
      // success.
      $this->userStorage->save($new_user);
      user_login_finalize($new_user);
      // $this->currentUser = $new_user;
      return $new_user;
    }
    catch (\Exception $e) {
      $this->logger->error('Unable to create a Drupal user for the Shibboleth ID %authname.', ['%authname' => $this->shibAuth->getTargetedId()]);
    }
  }

  /**
   * Finalize user login.
   *
   * @return bool
   *
   * @throws \Exception
   */
  // private function authenticateUser() {
  //   if (empty($this->user)) {
  //     $this->setErrorMessage(t('There was an error logging you in.'));
  //     throw new \Exception('No uid found for user when trying to initialize Drupal session.');
  //   }
  //   user_login_finalize($this->user);
  //   return TRUE;
  // }

  public function logout() {
    user_logout();
  }

  /**
   * Checks the values of users' Shibboleth username fields for a match.
   *
   * @return bool
   *   Returns TRUE if a user was found, FALSE otherwise.
   */
  // public function checkLinkedUser($authname) {
  //   $linked_user_lookup = $this->userStorage
  //     ->loadByProperties([
  //       'shibboleth_username' => $this->shibAuth->getTargetedId(),
  //     ]);
  //   $linked_user = reset($linked_user_lookup);
  //   return empty($linked_user) ? FALSE : User::load($linked_user->id());
  // }

  /**
   * Gets the Drupal user associated with the given Shibboleth authname.
   *
   * Looks for the authname in the user.shibboleth_username field value.
   *
   * @param string $authname
   *   The Shibboleth username.
   *
   * @return \Drupal\user\Entity\User|bool
   *   Returns a User entity if a match was found, FALSE otherwise.
   */
  public function getLinkedUser(string $authname) {
    $linked_user_lookup = $this->userStorage
      ->loadByProperties([
        'shibboleth_username' => $this->shibAuth->getTargetedId(),
      ]);
    $linked_user = reset($linked_user_lookup);
    return empty($linked_user) ? FALSE : User::load($linked_user->id());
  }

  /**
   * Gets the Shibboleth ID mapped to a user.
   *
   * @param string $user_id
   *
   * @return string
   */
  public function getShibbolethUsername(string $user_id) {
    /** @var UserInterface $account */
    $account = $this->userStorage->load($user_id);
    // @todo Is there a better way to get the property value?
    return isset($account->get('shibboleth_username')->getValue()[0]) ? $account->get('shibboleth_username')->getValue()[0]['value'] : '';
  }

  /**
   * @todo Remove
   */
  public function checkPotentialUserMatch() {
    $user_match = user_load_by_name($this->getTargetedId());
    $this->potential_user_match = $user_match ?? FALSE;
    // $this->potential_user_match = FALSE;
    return $this->potential_user_match;
  }

  /**
   * @todo Remove
   */
  public function getPotentialUserMatch() {
    if (is_null($this->potential_user_match)) {
      $this->checkPotentialUserMatch();
    }
    return $this->potential_user_match;
  }

  /**
   * Generate a random password for the Drupal user account.
   *
   * @return string
   */
  private function genPassword() {
    $rand = new Random();
    return $rand->string(30);
  }

}
