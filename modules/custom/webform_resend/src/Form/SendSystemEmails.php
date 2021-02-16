<?php

namespace Drupal\webform_resend\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class SendSystemEmails.
 */
class SendSystemEmails extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'send_system_emails';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['email_from'] = [
      '#type' => 'email',
      '#title' => 'Email address from which emails will be sent',
      '#default_value' => \Drupal::config('system.site')->get('mail'),
      '#attributes' => [ 'disabled' => 'true', 'required' => 'true' ],
    ];
    $form['email_to'] = [
      '#type' => 'select',
      '#title' => $this
        ->t('Send email to'),
      '#options' => [
        'ati' => $this
          ->t('Users with ATI email in Registry'),
        'opengov' => $this
          ->t('Users with Opengov email in Registry'),
        'registry' => $this
          ->t('All Registry users'),
        'other' => 'Other',
      ],
      '#default_value' => 'other',
    ];
    $form['email_to_other'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Enter all email addresses to send email, separated with comma (,)'),
      '#attributes' => [ 'rows' => '5', 'cols' => '100' ],
    ];
    $form['email_subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#attributes' => [ 'required' => 'true' ],
    ];
    $form['email_body'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Body'),
      '#format' => 'rich_text',
      '#attributes' => [ 'required' => 'true' ],
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send System Emails'),
    ];

    return $form;
  }

  /**+
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // check user permission
    $user_role = \Drupal::currentUser()->getRoles();
    if (!in_array('business_owner', $user_role, TRUE) && !in_array('administrator', $user_role, TRUE) ) {
      $form_state->setErrorByName('submit', $this->t('Insufficient user permissions'));
    }

    // validate email_from field
    if (empty($form_state->getValue('email_from'))) {
      $form_state->setErrorByName('email_from', $this->t('Missing "From" email address'));
    }

    // validate email_to field
    if (empty($form_state->getValue('email_to'))) {
      $form_state->setErrorByName('email_to', $this->t('Please select an option to send email(s)'));
    }

    // validate email_to_other field
    if ($form_state->getValue('email_to') == 'other') {
      if (empty($form_state->getValue('email_to_other'))) {
        $form_state->setErrorByName('email_to', $this->t('Missing "To" email address'));
      } else {
        $email_list = explode(',', $form_state->getValue('email_to_other'));
        $emails_clean_flag = NULL;
        foreach($email_list as $email_to_individual) {
          $email_to_individual = trim($email_to_individual);
          if (!filter_var($email_to_individual, FILTER_VALIDATE_EMAIL)) {
            $emails_clean_flag = $email_to_individual;
          }
        }
        if ($emails_clean_flag) {
          $form_state->setErrorByName('email_to', $this->t('Invalid email address: ') . $emails_clean_flag);
        }
      }
    }

    // validate email_subject
    if (empty($form_state->getValue('email_subject'))) {
      $form_state->setErrorByName('email_subject', $this->t('Missing "Subject" for email'));
    }

    // validate email_body
    $email_body = $form_state->getValue('email_body');
    if (empty($email_body['value'])) {
      $form_state->setErrorByName('email_body', $this->t('Missing "Body" for email'));
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $mailManager = \Drupal::service('plugin.manager.mail');
    $langcode =  \Drupal::currentUser()->getPreferredLangcode();
    $params['from'] = $form_state->getValue('email_from');
    $params['subject'] = $form_state->getValue('email_subject');
    $params['message'] = $form_state->getValue('email_body')['value'];

    $email_to = $form_state->getValue('email_to');
    $email_list = [];

    switch ($email_to) {
      case 'ati':
        $filename = \Drupal\Core\Site\Settings::get('ckan_public_path') . '/transitional_orgs.jsonl';
        if (file_exists($filename) && $contents = file($filename)) {
          foreach ($contents as $line) {
            $data = json_decode($line);
            
            foreach ($data->extras as $a) {
              if ($a->key == 'ati_email' && $a->value && !in_array($a->value, $email_list) 
                && filter_var($a->value, FILTER_VALIDATE_EMAIL)) {
                array_push($email_list, $a->value);
              }
            }
          }
        }
        break;

      case 'opengov':
        $filename = \Drupal\Core\Site\Settings::get('ckan_public_path') . '/transitional_orgs.jsonl';
        if (file_exists($filename) && $contents = file($filename)) {
          foreach ($contents as $line) {
            $data = json_decode($line);
            
            foreach ($data->extras as $a) {
              if ($a->key == 'opengov_email' && $a->value && !in_array($a->value, $email_list) 
                && filter_var($a->value, FILTER_VALIDATE_EMAIL)) {
                array_push($email_list, $a->value);
              }
            }
          }
        }
        break;

      case 'registry':
        $filename = \Drupal\Core\Site\Settings::get('ckan_public_path') . '/users_list.json';
        if (file_exists($filename) && $contents = file_get_contents($filename)) {
          $data = json_decode($contents, true);
          foreach ($data as $user) {
            if (array_key_exists('email', $user) && $user['email'] && !in_array($user['email'], $email_list) 
              && filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
              array_push($email_list, $user['email']); 
            }
          }
        }
        break;

      case 'other':
        $email_list = explode(',', $form_state->getValue('email_to_other'));
        break;
    }

    $total = sizeof($email_list);
    $batch_size = 10;
    $pause = 1;

    for ($i=0; $i<$total; $i+=$batch_size) {
      $batch = array_slice($email_list, $i, $batch_size);
      foreach($batch as $email_to_individual) {
        $email_to_individual = trim($email_to_individual);
        $params['to'] = $email_to_individual;
        if ($mailManager->mail('webform_resend', 'systemmail', $email_to_individual, $langcode, $params, $params['from'], TRUE)) {
          \Drupal::messenger()->addMessage("Successfully sent email to " . $email_to_individual);
          \Drupal::logger('webform_resend')->notice("Successfully sent email to " . $email_to_individual);
        }
        else {
          \Drupal::messenger()->addMessage("Failed to send email to " . $email_to_individual, 'error');
          \Drupal::logger('webform_resend')->notice("Failed to send email to " . $email_to_individual);
          $form_state->setRebuild();
        }
      }
      sleep($pause);
    }
  }

}
