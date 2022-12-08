<?php

namespace Drupal\gcnotify\Plugin\WebformHandler;

use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\webformSubmissionInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Drupal\webform\Utility\WebformElementHelper;

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
class GCNotifyEmailHandler extends WebformHandlerBase
{

  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE)
  {

    $personalisation = $this->getRequestOptions($webform_submission);

    // 1. send notification email to department
    $dept_email = $webform_submission->getElementData('ati_email');
    $dept_response = $this->sendGCNotifyEmail($dept_email, $personalisation, 'notifications');

    // 2. send confirmation email to user
    $user_email = $webform_submission->getElementData('your_e_mail_address');
    $user_response = $this->sendGCNotifyEmail($user_email, $personalisation, 'confirmation');

    $this->addNotesToWebformSubmission($webform_submission,
      [ 'notification' => $dept_response,
        'confirmation' => $user_response,
      ]);

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
          $ati_data .= $element['#options'][$value];

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

  protected function sendGCNotifyEmail($recipient, $personalisation, $handler_id)
  {

    $gcnotify_settings = \Drupal\Core\Site\Settings::get('gcnotify');

    if ($gcnotify_settings) {

      $api_endpoint = $gcnotify_settings['api_endpoint'];
      $authorization = $gcnotify_settings['authorization'];
      $langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();
      $template_id = $gcnotify_settings['template_id'][$langcode];
      $options = [
        'json' => [
          'email_address' => $recipient,
          'template_id' => $template_id,
          'reference' => \Drupal::request()->headers->get('referer'),
          'personalisation' => $personalisation,
        ],
        'headers' => [
          'Authorization' => $authorization,
          'Content-Type' => 'application/json',
        ],
      ];

      $client = new Client();

      try {

        $response = $client->post($api_endpoint, $options);
        if ($response->getStatusCode() == '200' || $response->getStatusCode() == '201')
          \Drupal::logger('gcnotify')->notice($handler_id . ' sent for webform "' . $this->getWebform()->label() . '" using GC Notify.');

        return $response;
      }

      catch (RequestException $request_exception) {

        $response = $request_exception->getResponse();
        $response_data = json_decode($response->getBody()->getContents(), TRUE);

        \Drupal::logger('gcnotify')->error(
          'Unable to send email ' . $handler_id . ' for webform "' . $this->getWebform()->label() . '" using GC Notify.'
          . '<pre><code>' . print_r($response_data, TRUE) . '</code></pre>'
        );

        $this->sendDefaultEmail($handler_id);
        return $response;
      }
    }
    else {

      \Drupal::logger('gcnotify')->error(
        'Unable to send email ' . $handler_id . ' for webform "' . $this->getWebform()->label() . '" using GC Notify. 
        GC Notify Settings not available.');

      $this->sendDefaultEmail($handler_id);
    }

  }

  protected function sendDefaultEmail($handler_id) {
    // if GC Notify service fails, default to email handler
    $webform = $this->getWebform();
    $handler = $webform->getHandler($handler_id);
    $webform_submission = $handler->getWebformSubmission();
    $message = $handler->getMessage($webform_submission);
    $handler->sendMessage($webform_submission, $message);
  }

  protected function addNotesToWebformSubmission($webform_submission, $responses) {
    // add GC Notify response as Administrative notes to the webform submission
    $notes = ($webform_submission->hasNotes()) ? $webform_submission->getNotes() : '';

    foreach ($responses as $handler_id => $response) {
      if ($response) {
        if ($response->getStatusCode() == '200' || $response->getStatusCode() == '201') {
          $note = "\r\n" . ucfirst($handler_id) . " email sent using GCNotify. Details:\r\n";
          $response_data = json_decode($response->getBody()->getContents(), TRUE);
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
    }

    $webform_submission->setNotes($notes);
    $webform_submission->resave();
  }

}
