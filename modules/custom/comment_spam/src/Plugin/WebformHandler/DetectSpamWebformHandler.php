<?php

  namespace Drupal\comment_spam\Plugin\WebformHandler;

  use Drupal\Core\Form\FormStateInterface;
  use Drupal\webform\Plugin\WebformHandlerBase;
  use Drupal\Component\Utility\Html;
  use Drupal\webform\WebformSubmissionInterface;
  use Drupal\Core\StringTranslation\StringTranslationTrait;

  /**
   * Detect Spam Webform validate handler.
   *
   * @WebformHandler(
   *   id = "detect_spam_validator",
   *   label = @Translation("Detect spam validation"),
   *   category = @Translation("Settings"),
   *   description = @Translation("Detect if submissions are coming as spam"),
   *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
   *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
   *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
   * )
   */
  class DetectSpamWebformHandler extends WebformHandlerBase {

    use StringTranslationTrait;

    /**
     * {@inheritdoc}
     */
    public function validateForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
      $spam = False;
      $webform_id = $this->getWebform()->id();
      $limit = $this->getWebform()->getSetting('entity_limit_user');
      if (!$limit)
        $limit = 5;
      $interval = $this->getWebform()->getSetting('entity_limit_user_interval');
      if (!$interval)
        $interval = 86400;
      $submission_addr = $webform_submission->get('remote_addr')->value;
      $timestamp = \Drupal::time()->getCurrentTime();

      try {
        $connection = \Drupal::database();
        // get submissions in the configured interval
        $query = $connection->select('webform_submission', 'w');
        $query->condition('w.webform_id', $webform_id, '=');
        $query->condition('w.remote_addr', $submission_addr, '=');
        $query->condition('w.created', [$timestamp - $interval, $timestamp], 'BETWEEN');
        $query->fields('w', ['remote_addr']);
        $result = $query->countQuery()->execute()->fetchField();

        if ($result > $limit)
          $spam = True;
      }

      catch (Exception $e) {
        \Drupal::logger('comment_spam')->
        warning('Unable to get total submissions for '
          . $submission_addr . '\n'
          . $e->getMessage());
      }

      if ($spam) {
        $limit_user_message = $this->getWebform()->getSetting('limit_user_message');

        $form_state->setErrorByName('', $this->t($limit_user_message));
        \Drupal::logger('comment_spam')->warning('Spam suspected: Submission prevented on webform "' .
          $webform_id . '" from address ' . $submission_addr);
      }
    }
  }
