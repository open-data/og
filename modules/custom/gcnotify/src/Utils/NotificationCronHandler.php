<?php

namespace Drupal\gcnotify\Utils;

use Drupal\Component\Utility\Html;
use Drupal\Core\Datetime\DrupalDateTime;
use GuzzleHttp\Client;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\webform\Entity\WebformSubmission;
use Drupal\webform\WebformSubmissionInterface;


/**
 * Class NotificationCronHandler.
 * use GC Notify API to get all notification statuses
 */
class NotificationCronHandler
{

    /**
     * Retrieve notification details from GC Notify API
     */
    public function get_notifications_status() {

        $database = \Drupal::service('database');

        $notify = new NotificationAPIHandler();
        $api_notifications = $notify->getEmailNotifications();
        $received_time = \Drupal::time()->getRequestTime();
    
        foreach($api_notifications as $notification) {

            $notification['to'] = $notification['email_address'];
            $notification['notification_type'] = $notification['type'];
            $notification['callback_received'] = $received_time;
            $notification['created_at'] = strtotime($notification['created_at']);
            $notification['completed_at'] = strtotime($notification['completed_at']);
            $notification['sent_at'] = strtotime($notification['sent_at']);            
            $notification['api_response'] = json_encode($notification,
                                                JSON_PRETTY_PRINT
                                                | JSON_UNESCAPED_UNICODE
                                                | JSON_UNESCAPED_SLASHES
                                                | JSON_UNESCAPED_LINE_TERMINATORS);
            $notification['api_response_date'] = (new DrupalDateTime())->getTimestamp();
            $notification['environment'] = $this->get_environment($notification);

            if (strpos($notification['reference'], 'search/ati/reference') !== false)
                $notification['webform_sid'] = explode(' # ', $notification['subject'])[1];

            $extra_fields = ['email_address', 'type', 'phone_number', 'line_1', 'line_2',
            'line_3', 'line_4', 'line_5', 'line_6', 'postcode', 'template',
            'body', 'subject', 'created_by_name', 'scheduled_for', 'postage'];

            foreach($extra_fields as $field) {
                if (array_key_exists($field, $notification))
                    unset($notification[$field]);
            }

            $database->upsert('gcnotify')
                ->fields(array_keys($notification))
                ->values($notification)
                ->key('id')
                ->execute();

            if (array_key_exists('webform_sid', $notification))
                $this->update_webform_notes($notification['webform_sid']);

        }
    
        \Drupal::logger('gcnotify')->notice('Completed update of notifications from GC Notify API');
    }

    protected function get_environment($status) {

        if (array_key_exists('reference', $status) === false)
            return '';
 
            if (strpos($status['reference'], 'tbs-sct.gc.ca') !== false)
            return 'SERVER';

        if (filter_var($status['reference'], FILTER_VALIDATE_URL) === false)
            return  'UNABLE TO RESOLVE';
        
        $url_components = parse_url($status['reference']);
        $domain = isset($url_components['host'])
                  ? $url_components['host']
                  : '';
    
        if ($domain === "open.canada.ca"
            || $domain === "ouvert.canada.ca")
            return  'PRODUCTION PORTAL';

        if ($domain === "staging.open.canada.ca"
            || $domain === "stadification.ouvert.canada.ca")
            return  'STAGING PORTAL';

        if ($domain === "test.open.canada.ca"
            || $domain === "essai.ouvert.canada.ca")
            return  'TEST PORTAL';

        if ($domain === "registry.open.canada.ca"
            || $domain === "registre.ouvert.canada.ca")
            return  'PRODUCTION REGISTRY';

        if ($domain === "registry-staging.open.canada.ca"
            || $domain === "stadification-registre.ouvert.canada.ca")
            return  'STAGING REGISTRY';

        if ($domain === "test-registry.open.canada.ca"
            || $domain === "essai-registre.ouvert.canada.ca")
            return  'TEST REGISTRY';

        return  $domain;

    }

    protected function update_webform_notes($sid)
    {
        $webform_submission = WebformSubmission::load($sid);
        if ($webform_submission) {
            $notes = ($webform_submission->hasNotes())
                ? $webform_submission->getNotes()
                : '';
            $notes = "\r\nGC Notify callback received. Details:\r\n"
                . json_encode($status, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
                . $notes;
            $webform_submission->setNotes($notes);
            $webform_submission->resave();
        }
    }
}