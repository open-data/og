<?php

namespace Drupal\gcnotify\Plugin\WebformHandler;

use Drupal\gcnotify\Utils\NotificationAPIHandler;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\webformSubmissionInterface;
use Drupal\webform\Plugin\WebformHandler\EmailWebformHandler;
use Drupal\webform\Utility\WebformElementHelper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Form submission handler.
 *
 * @WebformHandler(
 *   id = "gc_notify_email_handler",
 *   label = @Translation("GC Notify Email Handler"),
 *   category = @Translation("Form Handler"),
 *   description = @Translation("Sends email notification to user and department using GC Notify service"),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class GCNotifyEmailHandler extends WebformHandlerBase {

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
      return [
          '#markup' => $this->t("Settings for GC Notify to be completed in notification.canada.ca"),
        ];
  }

  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {

    $webform_id = $webform_submission->getWebform()->id();
    $langcode = $webform_submission->getLangcode();
    $notify = new NotificationAPIHandler();
    $personalisation = $this->getRequestOptions($webform_submission);

    $webform = $this->getWebform();
    $handlers = $webform->getHandlers();

    foreach ($handlers as $handler_id => $handler) {

      if ($handler instanceof EmailWebformHandler) {

        $is_default_mail = false;
        $message = $handler->getMessage($webform_submission);
        $to = $message['to_mail'];

        $response = $notify->sendGCNotifyEmail($to, $webform_id, $personalisation, $langcode);

        if ($response === False || in_array($response->getStatusCode(), ['200', '201']) === False )
          $is_default_mail = $this->sendDefaultEmail($handler_id);

        $this->addNotesToWebformSubmission($webform_submission, $is_default_mail, $handler_id, $response);

      }
    }

  }

  protected function getRequestOptions($webform_submission) {

    $langcode = $webform_submission->getLangcode();
    $webform_translation_manager = \Drupal::service('webform.translation_manager');
    $webform = $webform_submission->getWebform();
    $webform_values = $webform_submission->getData();
    $translation = $webform_translation_manager->getTranslationElements($webform, $langcode);

    $created = $webform_submission->get('created')->value;

    // hide deprecated fields from ATI template update
    // https://github.com/open-data/ckanext-canada/blob/master/ckanext/canada/tables/ati.yaml
    $hidden_fields = ['solr_core', 'organization', 'request_summary', 'number_of_pages', 'e_mail_ati_recipient', ];

    $data = '';

    foreach($webform_values as $key => $value) {
      $element = $webform->getElement($key);

      // check if not hidden or private
      if (!in_array($key, $hidden_fields) &&
         ( array_key_exists('#access', $element) === false || $element['#access'] === true )) {

        // get translated label of form element
        $element_label = (array_key_exists($key, $translation))
          ? $translation[$key]['#title']
          : $webform->getElement($key)['#title'];

        // generate element in pattern [key]: value
        $data .= '**' . $element_label . '**' . "\r\n";
        if ($element['#type'] == 'webform_address') {
          $data .= $value['address'];
          if (!empty($value['address_2']))
            $data .= "\r\n" . $value['address_2'];
          $data .= "\r\n" . $value['city'];
          $data .= ", " . $value['state_province'];
          $data .= ". " . $value['postal_code'];
          $data .= "\r\n" . $value['country'] . "\r\n";
        } elseif ($element['#type'] == 'select') {
          $data .= (array_key_exists($key, $translation) && array_key_exists('#options', $translation[$key]))
            ? $translation[$key]['#options'][$value]
            : $element['#options'][$value];
        }
        else
          $data .= $value;

        $data .= "\r\n \r\n";

      }
    }

    $personalisation = [
      'webform_submission_sid' => $webform_submission->id(),
      'webform_submission_created' => \Drupal::service('date.formatter')->format($created, 'medium'),
      'webform_submission_values' => $data,
    ];

    return $personalisation;
  }

  protected function addNotesToWebformSubmission($webform_submission, $default_mail, $handler_id, $response) {

    // add GC Notify response as Administrative notes to the webform submission

    $notes = ($webform_submission->hasNotes()) ? $webform_submission->getNotes() : '';

    if ($default_mail)
      $notes .= "\r\n" . 'Unable to send ' . $handler_id . ' email using GC Notify. Email sent using PHP mail.';

    if ($response) {

      if ($response->getStatusCode() == '200' || $response->getStatusCode() == '201') {

        $response_data = json_decode($response->getBody()->getContents(), TRUE);
        $note = "\r\n" . ucfirst($handler_id) . " email sent using GCNotify. Details:\r\n";
        $note .= json_encode([
          'handler_id' => $handler_id,
          'status_code' => $response->getStatusCode(),
          'gcnotify_response' => $response_data,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
      }

      else {

        $note = "\r\n" . ucfirst($handler_id) . " email failed to send using GCNotify. Details:\r\n";
        $response_data = json_decode($response->getBody(), TRUE);
        $note .= json_encode([
          'handler_id' => $handler_id,
          'status_code' => $response->getStatusCode(),
          'gcnotify_response' => $response_data,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
      }

      $notes .= "\r\n" . $note;
    }

    else
      $notes .= "\r\n" . 'Unable to send email using GC Notify. GC Notify Settings not available.';

    $webform_submission->setNotes($notes);
    $webform_submission->resave();

  }

  protected function sendDefaultEmail($handler_id) {

    // if GC Notify service fails, default to email handler

    $webform = $this->getWebform();
    $handler = $webform->getHandler($handler_id);
    $webform_submission = $handler->getWebformSubmission();
    $message = $handler->getMessage($webform_submission);
    $handler->sendMessage($webform_submission, $message);

    return true;

  }

}
