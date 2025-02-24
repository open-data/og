<?php

namespace Drupal\gcnotify\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Drupal\gcnotify\Utils\NotificationAPIHandler;


/**
 * Class GCNotifyRestController.
 */
class GCNotifyRestController extends ControllerBase
{

    /**
     * Receive notification status from REST GC Notify Callback service.
     *
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\Response
     *   The HTTP response object.
     *
     * @throws Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
     *   Throws AccessDeniedHttpException in case of error.
     */
    public function postNotificationStatus(Request $request)
    {

        // 1. Authenticate POST request using Bearer token

        $notify = new NotificationAPIHandler();
        $auth = $notify->authenticate_api_request($request);

        if (!is_bool($auth)) {
            \Drupal::logger('gcnotify')->error(
                'Unable to process POST request from GC Notify Callback API: '
                . $auth
            );
            throw new AccessDeniedHttpException($auth);
        }

        // 2. Log the payload

        \Drupal::logger('gcnotify')->notice(
            'Received GC Notify Callback '
            . \Drupal::service('date.formatter')
            ->format(\Drupal::time()->getRequestTime())
        );

        // 3. Return response

        return new Response(
            'Callback received',
            Response::HTTP_OK,
            ['Content-Type', 'text/html']
        );

    }

}
