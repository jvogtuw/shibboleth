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
use Drupal\shibboleth\Exception\ShibbolethAutoRegisterException;
use Drupal\user\Entity\User;
use mysql_xdevapi\Exception;

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
   * @var \Drupal\Core\Logger\LoggerChannelInterface
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

  protected $potentialUserMatch;

  /**
   * @var string
   */
  protected $errorMessage;

  /**
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * MySQL error code for duplicate entry.
   */
  // const MYSQL_ER_DUP_KEY = 23000;


  /**
   * LoginHandler constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface              $config_factory
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface          $etm
   * @param \Drupal\Core\Logger\LoggerChannelInterface              $logger
   * @param \Drupal\shibboleth\Authentication\ShibbolethAuthManager $shib_auth_manager
   * @param \Drupal\Core\Session\SessionManagerInterface            $session_manager
   * @param \Drupal\Core\Session\AccountInterface                   $current_user
   * @param \Drupal\Core\Messenger\MessengerInterface               $messenger
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, LoggerChannelInterface $logger, ShibbolethAuthManager $shib_auth_manager, SessionManagerInterface $session_manager, AccountInterface $current_user, MessengerInterface $messenger) {
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

  // public function getShibAuthManager() {
  //   return $this->shib_auth_manager;
  // }

  public function getLoginUrl() {

  }

  public function getLogoutUrl() {
    return $this->shibAuth->getLoginUrl();
  }

  public function loginRegister() {

    // No Shibboleth session
    if (!$this->shibAuth->sessionExists()) {
      $this->logger->error('Shibboleth login attempt failed. No Shibboleth session was found.');
      return FALSE;
    }

    // Attempt to log in the user.
    $account = $this->login();
    if ($account) {
      // $this->logger->status('It thinks $account is true.');
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
   * @return \Symfony\Component\HttpFoundation\RedirectResponse|false
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

    // There's already a logged in Drupal user
    if ($this->currentUser->isAuthenticated()) {
      // The Shibboleth user doesn't match the Drupal user and the Drupal user
      // doesn't have permission to bypass that conflict. Log out.
      if ($this->currentUser->getAccountName() !== $authname && !$this->currentUser->hasPermission('bypass shibboleth login')) {

      }
    }

    // Login access is blocked due to the user's attributes.
    // @see ShibPath

    // Log in the Drupal user.
    user_login_finalize($linked_user);
    return $linked_user;


    // $error_message = '';
    // try {
    //   // $this->setErrorMessage('you shall not pass');
    //   // throw new \Exception('test exception');
    //   $user_registered = FALSE;
    //   // Register new user if user does not exist.
    //   if (!$this->checkProvisionedUser()) {
    //
    //     // Check if there's a likely match with an existing Drupal user.
    //     if ($this->checkPotentialUserMatch()) {
    //       //@todo return the route instead of an error message.
    //
    //       // Set the redirect to the Account map request form.
    //       // $redirect = Url::fromRoute('shib_auth.account_map_request');
    //       // echo $redirect;
    //       $error_msg = t('Login unsuccessful. See below for more information.');
    //       $this->setErrorMessage($error_msg);
    //       // return 11000;
    //       throw new \Exception('Shibboleth user is not mapped to a Drupal user, however, there is a potential matching account.', 11000);
    //       // $this->messenger->addWarning($error_msg);
    //       // $error_url = Url::fromRoute('shibboleth.drupal_login_error');
    //       // return $error_url->toString();
    //       // return new RedirectResponse($error_url->toString());
    //     }
    //     else {
    //       // echo 'trying to create user';
    //       $user_registered = $this->registerNewUser();
    //     }
    //   }
    //   else {
    //     $user_registered = TRUE;
    //   }
    //
    //   if ($user_registered) {
    //     $this->authenticateUser();
    //     return FALSE;
    //   }
    // }
    // catch (\Exception $e) {
    //   // Log the error to Drupal log messages.
    //   $this->logger->error($e);
    //
    //   // Shibboleth user not mapped to a Drupal user, but a potential match
    //   // exists.
    //   if ($e->getCode() == 11000) {
    //     // $this->setErrorMessage(t('There was an error logging you in and we were unable to create a user for you.'));
    //     $this->messenger->addError($this->getErrorMessage());
    //     return $e->getCode();
    //   }
    //   else {
    //     // $user = \Drupal::currentUser();
    //     // if ($user->isAuthenticated()) {
    //     // if ($this->current_user->isAuthenticated()) {
    //     //   // Kill the Drupal session.
    //     //   // @todo - Do we need to kill the session for anonymous users, too? If so, how do we set the error message?
    //     //   user_logout();
    //     // }
    //
    //     if ($this->getErrorMessage()) {
    //       $this->messenger->addError($this->getErrorMessage());
    //     }
    //
    //     $return_url = '';
    //     // if ($this->config->get('url_redirect_logout')) {
    //     //   $return_url = '?return=' . $this->config->get('url_redirect_logout');
    //     // }
    //     // Redirect to shib logout url.
    //     // @todo do we really want this?
    //     // return new TrustedRedirectResponse($this->config->get('shibboleth_logout_handler_url') . $return_url);
    //     // return new TrustedRedirectResponse($this->shib_auth_manager->getLogoutUrl() . $return_url);
    //     // return Url::fromRoute('shibboleth.drupal_login_error')->toString();
    //     // $error_url = Url::fromRoute('shibboleth.drupal_login_error', ['error_message']);
    //     // return $error_url->toString();
    //   }
    // }
    // return FALSE;
  }

  /**
   * Creates a new Drupal user linked to the current Shibboleth session.
   *
   * The newly created user will be logged in.
   *
   * @return \Drupal\user\UserInterface
   *
   * @throws \ShibbolethAutoRegisterException
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
   * Check shib_authmap table for user, return true if user found.
   *
   * @return bool
   *
   * @throws \Exception
   */
  public function checkLinkedUser($authname) {
    $linked_user_lookup = $this->userStorage
      ->loadByProperties([
        'shibboleth_username' => $this->shibAuth->getTargetedId(),
      ]);
    $linked_user = reset($linked_user_lookup);
    // if (empty($shib_user)) {
    //   return FALSE;
    // }
    // $this->linkedUser = User::load($shib_user->id());
    // return $this->linkedUser;
    return empty($linked_user) ? FALSE : User::load($linked_user->id());
  }

  /**
   * Check shib_authmap table for user, return true if user found.
   *
   * @return bool
   *
   * @throws \Exception
   */
  public function getLinkedUser($authname) {
    $linked_user_lookup = $this->userStorage
      ->loadByProperties([
        'shibboleth_username' => $this->shibAuth->getTargetedId(),
      ]);
    $linked_user = reset($linked_user_lookup);
    // if (empty($shib_user)) {
    //   return FALSE;
    // }
    // $this->linkedUser = User::load($shib_user->id());
    // return $this->linkedUser;
    return empty($linked_user) ? FALSE : User::load($linked_user->id());
  }

  public function checkPotentialUserMatch() {
    $user_match = user_load_by_name($this->getTargetedId());
    $this->potential_user_match = $user_match ?? FALSE;
    // $this->potential_user_match = FALSE;
    return $this->potential_user_match;
  }

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
  
  /**
   *
   */
  // private function setErrorMessage($message) {
  //   $this->error_message = $message;
  // }

  /**
   *
   */
  // public function getErrorMessage() {
  //   return $this->error_message;
  // }
}
