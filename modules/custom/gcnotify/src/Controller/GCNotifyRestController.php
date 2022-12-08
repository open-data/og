<?php

namespace Drupal\gcnotify\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Drupal\Component\Utility\Html;
use \Drupal\webform\entity\WebformSubmission;
use \Drupal\webform\WebformSubmissionInterface;

/**
 * Class GCNotifyRestController.
 */
class GCNotifyRestController extends ControllerBase {

  /**
   * Process notification status from REST GC Notify Callback service.
   *
   * @param Request $request
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The HTTP response object.
   *
   * @throws Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Throws AccessDeniedHttpException in case of error.
   */
  public function postNotificationStatus(Request $request) {

    // 1. authorize post request using Bearer token

    $auth = $this->authenticate_api_request($request);
    if (!empty($auth)) {
      \Drupal::logger('gcnotify')->error('Unable to process POST request from GC Notify Callback API: ' . $auth);
      throw new AccessDeniedHttpException($auth);
    }

    // 2. Find webform with id and update notes

    $gcnotify_status = json_decode($request->getContent(), TRUE);
    $gcnotify_status['callback_received'] = \Drupal::time()->getRequestTime();

    $sid = $this->update_webform_notes($gcnotify_status);

    if (empty($sid))
      \Drupal::logger('gcnotify')->notice('Received GC Notify Callback id: '
        . $gcnotify_status['id']);
    else {
      $gcnotify_status['webform_sid'] = $sid;
      \Drupal::logger('gcnotify')->notice('Received GC Notify Callback id: '
        . $gcnotify_status['id']
        . ' for webform submission# : ' . $sid);
    }

    // 3. Save response to database

    $this->save_gcnotify_status($gcnotify_status);

    // 4. Return response

    return new Response(
      'Callback received',
      Response::HTTP_OK,
      ['Content-Type', 'text/html']);
  }

  protected function authenticate_api_request($request) {
    $authorization_header = $request->headers->get('AUTHORIZATION');
    $token = \Drupal\Core\Site\Settings::get('gcnotify')['bearer_token'];

    if (empty($token))
      return 'MISSING BEARER TOKEN CONFIGURATION';

    if ( $request->headers->get('AUTHORIZATION') == null
      || empty($authorization_header)
      || $authorization_header == "" )
      return 'MISSING AUTHORIZATION HEADER';

    if (!preg_match('/\Bearer\b/', $authorization_header))
      return 'INVALID AUTHORIZATION HEADER TOKEN TYPE';

    $authorization_header = Html::escape($authorization_header);
    $authorization_header_values = explode( " ", $authorization_header );
    $authorization_token = $authorization_header_values[1];

    if (!hash_equals(hash('sha256', $token), $authorization_token))
      return 'INVALID BEARER TOKEN';

    return '';

  }

  protected function update_webform_notes($status) {
    $database = \Drupal::service('database');
    $status['callback_received'] = \Drupal::service('date.formatter')->format($status['callback_received']);

    $gcnotify_submission = $database->select('webform_submission', 'w')
      ->fields('w', ['sid'])
      ->condition('w.notes', "%" . $database->escapeLike('"id": "' . $status['id'] . '"') . "%", 'LIKE')
      ->execute()
      ->fetchAll();

    if ($gcnotify_submission) {
      $sid = $gcnotify_submission[0]->sid;
      /** @var \Drupal\webform\WebformSubmissionInterface $webform_submission */
      $webform_submission = \Drupal\webform\Entity\WebformSubmission::load($sid);
      $notes = ($webform_submission->hasNotes()) ? $webform_submission->getNotes() : '';
      $notes = "\r\nGC Notify callback received. Details:\r\n"
        . json_encode($status, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . $notes;
      $webform_submission->setNotes($notes);
      $webform_submission->resave();
      return $sid;
    }

    return '';
  }

  protected function save_gcnotify_status($status) {
    $status['created_at'] = strtotime($status['created_at']);
    $status['completed_at'] = strtotime($status['completed_at']);
    $status['sent_at'] = strtotime($status['sent_at']);

    $database = \Drupal::service('database');
    $database->insert('gcnotify')
      ->fields(array_keys($status))
      ->values($status)
      ->execute();
  }
}
