<?php

namespace Drupal\gcnotify\Utils;

use Drupal\Component\Utility\Html;
use GuzzleHttp\Client;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class NotificationAPIHandler.
 * use GC Notify service to send and receive notification
 * receive callbacks from GC Notify
 */
class NotificationAPIHandler
{

    /**
     * get notifications from GC Notify API
     */
    public function getEmailNotifications()
    {

        $gcnotify_settings = \Drupal\Core\Site\Settings::get('gcnotify');
        if (!isset($gcnotify_settings)) {

            \Drupal::logger('gcnotify')->error(
                'Unable to send email using GC Notify. GC Notify Settings not available.'
            );
            return false;

        }

        $header = [
        'headers' => [
        'Authorization' => $gcnotify_settings['authorization'],
        'Content-Type' => 'application/json',
        ],
        ];

        $api_endpoint = $gcnotify_settings['base_uri'] . '/notifications';
        $client = new Client();
        $api_notifications = [];

        while (!empty($api_endpoint)) {

            try {

                $response = $client->get($api_endpoint, $header);

                if ($response->getStatusCode() == '200' || $response->getStatusCode() == '201') {
                    $data = json_decode($response->getBody()->getContents(), true);
                    $api_endpoint = isset($data['links']['next']) ? $data['links']['next'] : '';
                    $api_notifications += $data['notifications'];
                }

                else {
                    \Drupal::logger('gcnotify')->error(
                        'Failed to get notifications from GC Notify API ' .
                        $response->getStatusCode() . $response->getMessage()
                    );
                }

            }

            catch (\Exception $exception) {

                \Drupal::logger('gcnotify')->error(
                    'Failed to get notifications from GC Notify API ' .
                    $exception->getMessage()
                );
            }

        }

        return $api_notifications;

    }


    /**
     * use GC Notify API to send notification
     */
    public function sendGCNotifyEmail($recipient, $template, $personalisation, $langcode)
    {

        $gcnotify_settings = \Drupal\Core\Site\Settings::get('gcnotify');
        if (!isset($gcnotify_settings)) {

            \Drupal::logger('gcnotify')->error(
                'Unable to send email using GC Notify. GC Notify Settings not available.'
            );
            return false;

        }

        $api_endpoint = $gcnotify_settings['base_uri'] . '/notifications/email';
        $authorization = $gcnotify_settings['authorization'];
        $template_id = $gcnotify_settings['template_id'][$template][$langcode];

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
            if ($response->getStatusCode() == '200' || $response->getStatusCode() == '201') {

                \Drupal::logger('gcnotify')->notice(
                    'Email notification sent using GC Notify.'
                );

            }

            return $response;

        }

        catch (\Exception $exception) {

            $response = $exception->getResponse();
            $error_info = $response
            ? '<pre><code>' .
            print_r(json_decode($response->getBody()->getContents(), true), true) .
            '</code></pre>'
            : $exception->getMessage();

            \Drupal::logger('gcnotify')->error(
                'Unable to send email using GC Notify.' . $error_info
            );

            return $response;

        }
    }

    /**
     * authenticate API request from GC Notify
     */
    public function authenticate_api_request($request)
    {

        $authorization_header = $request->headers->get('AUTHORIZATION');
        $token = \Drupal\Core\Site\Settings::get('gcnotify')['bearer_token'];

        if (empty($token)) {
            return 'Callback Authentication: MISSING BEARER TOKEN CONFIGURATION';
        }

        if ($request->headers->get('AUTHORIZATION') == null
            || empty($authorization_header)
            || $authorization_header == ""
        ) {
            return 'Callback Authentication: MISSING AUTHORIZATION HEADER';
        }

        if (!preg_match('/\Bearer\b/', $authorization_header)) {
            return 'Callback Authentication: INVALID AUTHORIZATION HEADER TOKEN TYPE';
        }

        $authorization_header = Html::escape($authorization_header);
        $authorization_header_values = explode(" ", $authorization_header);
        $authorization_token = $authorization_header_values[1];

        if (!hash_equals(hash('sha256', $token), $authorization_token)) {
            return 'Callback Authentication: INVALID BEARER TOKEN';
        }

        return true;

    }

}
