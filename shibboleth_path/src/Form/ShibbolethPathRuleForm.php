<?php

namespace Drupal\shibboleth_path\Form;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Protected path form.
 *
 * @property \Drupal\shibboleth_path\ShibbolethPathRuleInterface $entity
 */
class ShibbolethPathRuleForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    $locked = $this->entity->get('locked');
    if ($locked) {
      $this->messenger()->addWarning('This protected path rule was defined by the Shibboleth module and the pattern cannot be modified.');
    }

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $this->entity->label(),
      '#description' => $this->t('A label for the rule'),
      '#required' => TRUE,
      '#disabled' => $locked,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $this->entity->id(),
      '#machine_name' => [
        'exists' => '\Drupal\shibboleth_path\Entity\ShibbolethPathRule::load',
      ],
      '#disabled' => !$this->entity->isNew(),
    ];

    $form['pattern'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Pattern'),
      '#maxlength' => 255,
      '#default_value' => $this->entity->get('pattern'),
      '#description' => $this->t('This must be an internal path such as /node/add. You can also start typing the title of a piece of content to select it. Enter \<front\> to link to the front page. The path can include wildcards (*). Add a forward slash (/) at the end of the path to protect only the descendants. If there is no ending slash, the path and its descendants will be protected.'),
      '#required' => TRUE,
      '#disabled' => $locked,
    ];

    $form['criteria_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Additionally restrict by an attribute'),
      '#description' => $this->t('Add additional restrictions for this rule based on values of the selected attribute.'),
      '#options' => [
        'affiliation' => $this->t('Affiliation'),
        'groups' => $this->t('Groups'),
      ],
      '#empty_option' => '- None -',
      '#default_value' => $this->entity->get('criteria_type'),
    ];

    // The visible and required conditions are the same.
    $form_state_conditions = [
      ':input[name="criteria_type"]' => [
        ['value' => 'affiliation'],
        'or',
        ['value' => 'groups'],
      ],
    ];
    $form['criteria'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed values of the selected attribute.'),
      '#default_value' => $this->entity->get('criteria'),
      '#description' => $this->t('Enter one value per line.'),
      '#states' => [
        'visible' => $form_state_conditions,
        'required' => $form_state_conditions,
      ],
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      // '#description' => $locked ?? $this->t('<strong>This protected path rule is critical to Shibboleth\'s functionality.'),
      '#default_value' => $this->entity->status(),
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Validate the path pattern format
    $pattern = $form_state->getValue('pattern');
    // The path must be internal...
    if (UrlHelper::isExternal($pattern)) {
      $form_state->setErrorByName('pattern', $this->t('The path pattern must be an internal, absolute path. An external path was entered.'));
    }
    // and start with a /...
    elseif (!str_starts_with($pattern, '/')) {
      $form_state->setErrorByName('path', $this->t('The path pattern must start with a slash (/).'));
    }
    // and unique to the other protected path rule patterns.
    // Note that it only checks for exact matches. These two paths are not
    // considered matches: /categories/flowers; /*/flowers
    else {
      $storage = $this->entityTypeManager->getStorage('shibboleth_path_rule');
      $query = $storage->getQuery()
        ->condition('pattern', $pattern);
      if (!$this->entity->isNew()) {
        $query->condition('id', $this->entity->id(), '<>');
      }
      $matching_patterns = $query->execute();
      if (!empty($matching_patterns)) {
        $form_state->setErrorByName('pattern', $this->t('This path pattern is already used by another rule. Enter a unique pattern.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $result = parent::save($form, $form_state);
    $message_args = ['%label' => $this->entity->label()];
    $message = $result == SAVED_NEW
      ? $this->t('Created new Shibboleth protected path rule for %label.', $message_args)
      : $this->t('Updated Shibboleth protected path rule for %label.', $message_args);
    $this->messenger()->addStatus($message);
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }
}
