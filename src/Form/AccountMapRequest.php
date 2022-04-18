<?php

namespace Drupal\shibboleth\Form;

use Drupal\Component\Utility\EmailValidator;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Url;
use Drupal\shibboleth\Authentication\ShibbolethDrupalAuthManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;

class AccountMapRequest extends FormBase {

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The email validator.
   *
   * @var \Drupal\Component\Utility\EmailValidator
   */
  protected $emailValidator;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The login handler.
   *
   * @var \Drupal\shibboleth\Authentication\ShibbolethDrupalAuthManager
   */
  private $shib_drupal_auth;

  /**
   * Shibboleth config settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  private $shib_settings;

  /**
   * Constructs a new EmailExampleGetFormPage.
   *
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Component\Utility\EmailValidator $email_validator
   *   The email validator.
   */
  public function __construct(MailManagerInterface $mail_manager, LanguageManagerInterface $language_manager, EmailValidator $email_validator, ShibbolethDrupalAuthManager $shib_drupal_auth) {
    $this->mailManager = $mail_manager;
    $this->languageManager = $language_manager;
    $this->emailValidator = $email_validator;
    $this->shib_drupal_auth = $shib_drupal_auth;
    $this->shib_settings = $this->config('shibboleth.settings');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $form = new static(
      $container->get('plugin.manager.mail'),
      $container->get('language_manager'),
      $container->get('email.validator'),
      $container->get('shibboleth.drupal_auth_manager')
    );
    $form->setMessenger($container->get('messenger'));
    $form->setStringTranslation($container->get('string_translation'));
    return $form;
  }
  /**
   * @inheritDoc
   */
  public function getFormId() {
    return 'shibboleth_account_map_request';
  }

  /**
   * @inheritDoc
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    if ($this->shib_drupal_auth->getShibAuthManager()->sessionExists()) {
      $form['#title'] = 'Account map request';
      $id_label = $this->shib_settings->get('shibboleth_id_label');
      $shib_username = $this->shib_drupal_auth->getShibAuthManager()->getSession()->getTargetedId();
      // $logout_url = $this->shib_settings->get('shibboleth_logout_handler_url');
      // $logout_url = Url::fromRoute('shib_auth.logout_controller_logout')->toString();
      $logout_url = $this->shib_drupal_auth->getLogoutUrl();
      $form['intro'] = [
        '#markup' => $this->t('<p>No Drupal account was found mapped to this @id_label. However, there may be an existing Drupal account that could be mapped. Click the button below to request that the site admins attempt to map your @id_label to a Drupal account.</p>', ['@id_label' => $id_label]),
      ];
      $form['shibboleth_username'] = [
        '#type' => 'item',
        '#title' => t('Logged in with the @id_label', ['@id_label' => $id_label]),
        '#markup' => t('@shib_username. If this isn\'t you, <a href="@logout_url">log out</a> to try again.', ['@shib_username' => $shib_username, '@logout_url' => $logout_url]),
      ];
      $form['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Please map my account!'),
        '#button_type' => 'primary',
      ];
    }

    return $form;
  }

  /**
   * @inheritDoc
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    global $base_url;
    $form_values = $form_state->getValues();
    $id_label = $this->shib_settings->get('shibboleth_id_label');
    $shib_username = $this->shib_drupal_auth->getShibSession()->getTargetedId();
    $shib_email = $this->loginHandler->getShibSession()->getEmail();
    $possible_match = $this->loginHandler->checkPotentialUserMatch();
    $possible_match_url = $base_url . $possible_match->toUrl()->toString();
    $body = t('<p>The following @id_label user has attempted to log into @site. There is no Drupal user mapped to this @id_label, but one couldn\'t be created automatically because there is a Drupal user with a name matching the @id_label. Please review the Drupal user account and map it to the @id_label if appropriate. Otherwise, manually create a user mapped to the @id_label.</p><p>@id_label: @shib_username<br>Email: @shib_email<br>Drupal user: @possible_match_url</p><p>Follow up with the user at the email above when you\'re done.</p>', ['@id_label' => $id_label, '@site' => $base_url, '@shib_username' => $shib_username, '@shib_email' => $shib_email, '@possible_match_url' => $possible_match_url]);

    // All system mails need to specify the module and template key (mirrored
    // from hook_mail()) that the message they want to send comes from.
    $module = 'shib_auth';
    $key = 'account_map_request';

    // Specify 'to' and 'from' addresses.
    $to = $this->config('system.site')->get('mail');
    $from = 'nobody@uw.edu';

    // "params" loads in additional context for email content completion in
    // hook_mail(). In this case, we want to pass in the values the user entered
    // into the form, which include the message body in $form_values['message'].
    $params = [
      'message' => $body,
    ];
    $language_code = $this->languageManager->getDefaultLanguage()->getId();
    $send_now = TRUE;
    $result = $this->mailManager->mail($module, $key, $to, $language_code, $params, $from, $send_now);
    if ($result['result'] == TRUE) {
      $this->messenger()->deleteByType('warning');
      $this->messenger()->addMessage($this->t('Your message has been sent.'));
    }
    else {
      $this->messenger()->addMessage($this->t('There was a problem sending your message and it was not sent.'), 'error');
    }
  }

  /**
   * Checks access.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently logged in account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  // public function access(AccountInterface $account) {
  //   /**
  //    * @todo Is this check redundant?
  //    * We check these things to send a user to this form, but we also don't want
  //    * just anyone accessing the path directly.
  //    */
  //   // Access requirements
  //   // * Has Shibboleth session
  //   // * Has potential matching user
  //   return AccessResult::allowedIf($this->loginHandler->getShibSession()->getSessionId()
  //     && !$this->loginHandler->checkUserExists()
  //     && $this->loginHandler->checkPotentialUserMatch());
  // }
}
