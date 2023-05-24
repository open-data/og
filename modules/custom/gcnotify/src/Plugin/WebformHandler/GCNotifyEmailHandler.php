<?php

namespace Drupal\gcnotify\Plugin\WebformHandler;

use Drupal\gcnotify\Utils\NotificationAPIHandler;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\webformSubmissionInterface;
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

  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {

    $webform_id = $webform_submission->getWebform()->id();
    $is_default_mail = false;
    $notify = new NotificationAPIHandler();
    $personalisation = $this->getRequestOptions($webform_submission);

    // 1. send notification email to department

    $dept_email = $webform_submission->getElementData('ati_email');
    if ($dept_email) {
      $dept_response = $notify->sendGCNotifyEmail($dept_email, $webform_id, $personalisation);

      if ($dept_response === False || in_array($dept_response->getStatusCode(), ['200', '201']) === False )
        $is_default_mail = $this->sendDefaultEmail('notifications');
    }
    else
      $dept_response = '';

    // 2. send confirmation email to user

    $user_email = $webform_submission->getElementData('your_e_mail_address');
    if ($user_email) {
      $user_response = $notify->sendGCNotifyEmail($user_email, $webform_id, $personalisation);

      if ($user_response === False || in_array($user_response->getStatusCode(), ['200', '201']) === False )
        $is_default_mail = $this->sendDefaultEmail('confirmation');
    }
    else
      $user_response = '';

    // 3. add response to webform submission notes

    $this->addNotesToWebformSubmission(
      $webform_submission,
      $is_default_mail,
      [
        'notification' => $dept_response,
        'confirmation' => $user_response,
      ]
    );

  }

  protected function getRequestOptions($webform_submission) {

    $langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();
    $webform_translation_manager = \Drupal::service('webform.translation_manager');
    $webform = $webform_submission->getWebform();
    $webform_values = $webform_submission->getData();
    $translation = $webform_translation_manager->getTranslationElements($webform, $langcode);

    $created = $webform_submission->get('created')->value;

    // hide deprecated fields from ATI template update
    // https://github.com/open-data/ckanext-canada/blob/master/ckanext/canada/tables/ati.yaml
    $ati_hide_fields = ['solr_core', 'organization', 'request_summary', 'number_of_pages', 'e_mail_ati_recipient', ];

    $ati_data = '';

    foreach($webform_values as $key => $value) {
      $element = $webform->getElement($key);
      if (!in_array($key, $ati_hide_fields)) {

        // get translated label of form element
        $element_label = (in_array($key, $translation, false))
          ? $translation[$key]['#title']
          : $webform->getElement($key)['#title'];

        // generate element in pattern [key]: value
        $ati_data .= '**' . $element_label . '**' . "\r\n";
        if ($element['#type'] == 'webform_address' ) {
          $ati_data .= $value['address'];
          if (!empty($value['address_2']))
            $ati_data .= "\r\n" . $value['address_2'];
          $ati_data .= "\r\n" . $value['city'];
          $ati_data .= ", " . $value['state_province'];
          $ati_data .= ". " . $value['postal_code'];
          $ati_data .= "\r\n" . $value['country'] . "\r\n";
        }

	elseif ($element['#type'] == 'select' )
	  $ati_data .= empty($value) ? '' : $element['#options'][$value];

        else
          $ati_data .= $value;

        $ati_data .= "\r\n \r\n";
      }
    }

    $personalisation = [
      'webform_submission_sid' => $webform_submission->id(),
      'webform_submission_created' => \Drupal::service('date.formatter')->format($created, 'medium'),
      'webform_submission_values' => $ati_data,
    ];

    return $personalisation;
  }

  protected function addNotesToWebformSubmission($webform_submission, $default_mail, $responses) {

    // add GC Notify response as Administrative notes to the webform submission

    $notes = ($webform_submission->hasNotes()) ? $webform_submission->getNotes() : '';

    foreach ($responses as $handler_id => $response) {

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

    }

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
