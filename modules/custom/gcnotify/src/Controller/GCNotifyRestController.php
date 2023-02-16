<?php

namespace Drupal\gcnotify\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Drupal\gcnotify\Utils\NotificationAPIHandler;
use Drupal\webform\Entity\WebformSubmission;
use Drupal\webform\WebformSubmissionInterface;

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

    $notify = new NotificationAPIHandler();
    $auth = $notify->authenticate_api_request($request);

    if (!is_bool($auth)) {
      \Drupal::logger('gcnotify')->error(
        'Unable to process POST request from GC Notify Callback API: ' . $auth
      );
      throw new AccessDeniedHttpException($auth);
    }

    $gcnotify_status = json_decode($request->getContent(), TRUE);
    $gcnotify_status['callback_received'] = \Drupal::time()->getRequestTime();

    \Drupal::logger('gcnotify')->notice(
      'Received GC Notify Callback for notification id: ' . $gcnotify_status['id']
    );

    // 2. Find webform with id and update notes

    $sid = $this->update_webform_notes($gcnotify_status);
    if ($sid)
      $gcnotify_status['webform_sid'] = $sid;

    // 3. Save response to database

    $this->save_gcnotify_status($gcnotify_status);

    // 4. Return response

    return new Response(
      'Callback received',
      Response::HTTP_OK,
      ['Content-Type', 'text/html']);
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
      $webform_submission = WebformSubmission::load($sid);
      $notes = ($webform_submission->hasNotes()) ? $webform_submission->getNotes() : '';
      $notes = "\r\nGC Notify callback received. Details:\r\n" .
        json_encode($status, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . $notes;
      $webform_submission->setNotes($notes);
      $webform_submission->resave();
      return $sid;
    }

    return false;
  }

  protected function save_gcnotify_status($status) {

    $status['created_at'] = strtotime($status['created_at']);
    $status['completed_at'] = strtotime($status['completed_at']);
    $status['sent_at'] = strtotime($status['sent_at']);

    if (array_key_exists('reference', $status)) {
      if (filter_var($status['reference'], FILTER_VALIDATE_URL) !== FALSE) {
        $url_components = parse_url($status['reference']);
        $domain = isset($url_components['host']) ? $url_components['host'] : '';
        if ($domain === "open.canada.ca" || $domain === "ouvert.canada.ca")
          $status['environment'] = 'PRODUCTION PORTAL';
        elseif ($domain === "staging.open.canada.ca" || $domain === "stadification.ouvert.canada.ca")
          $status['environment'] = 'STAGING PORTAL';
        elseif ($domain === "test.open.canada.ca" || $domain === "essai.ouvert.canada.ca")
          $status['environment'] = 'TEST PORTAL';
        elseif ($domain === "registry.open.canada.ca" || $domain === "registre.ouvert.canada.ca")
          $status['environment'] = 'PRODUCTION REGISTRY';
        elseif ($domain === "registry-staging.open.canada.ca" || $domain === "stadification-registre.ouvert.canada.ca")
          $status['environment'] = 'STAGING REGISTRY';
        elseif ($domain === "test-registry.open.canada.ca" || $domain === "essai-registre.ouvert.canada.ca")
          $status['environment'] = 'TEST REGISTRY';
        else
          $status['environment'] = $domain;
      }
      elseif (strpos($status['reference'], 'tbs-sct.gc.ca') !== FALSE)
        $status['environment'] = 'SERVER';
      else
        $status['environment'] = 'UNABLE TO RESOLVE';
    }
    else
      $status['environment'] = '';

    $database = \Drupal::service('database');

    $database->upsert('gcnotify')
      ->fields(array_keys($status))
      ->values($status)
      ->key('id')
      ->execute();
  }
}
