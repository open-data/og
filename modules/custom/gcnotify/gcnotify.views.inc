<?php

/**
 * @file
 * Provide views data for gcnotify.module.
 */

/**
 * Implements hook_views_data().
 */
function gcnotify_views_data() {
  $data = [];

  // Base data.
  $data['gcnotify'] = [];
  $data['gcnotify']['table'] = [];
  $data['gcnotify']['table']['group'] = t('GC Notify');
  $data['gcnotify']['table']['provider'] = 'gcnotify';
  $data['gcnotify']['table']['base'] = [
    'field' => 'id',
    'title' => t('GC Notify Delivery Status'),
    'help' => t('Contains a list of delivery status posted by GC Notify Callback API.'),
  ];

  // Fields.
  $data['gcnotify']['id'] = [
    'title' => t('id'),
    'help' => t('GC Notify’s id for the status receipts.'),
    'field' => [
      'id' => 'standard',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'string',
    ],
    'argument' => [
      'id' => 'string',
    ],
  ];

  $data['gcnotify']['reference'] = [
    'title' => t('reference'),
    'help' => t('The reference sent by the service.'),
    'field' => [
      'id' => 'standard',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'string',
    ],
    'argument' => [
      'id' => 'string',
    ],
  ];

  $data['gcnotify']['to'] = [
    'title' => t('to'),
    'help' => t('The email address or phone number of the recipient.'),
    'field' => [
      'id' => 'standard',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'string',
    ],
    'argument' => [
      'id' => 'string',
    ],
  ];

  $data['gcnotify']['status'] = [
    'title' => t('status'),
    'help' => t('The status of the notification.'),
    'field' => [
      'id' => 'standard',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'string',
    ],
    'argument' => [
      'id' => 'string',
    ],
  ];

  $data['gcnotify']['provider_response'] = [
    'title' => t('provider_response'),
    'help' => t('The detailed response from the provider. This will only be not null in a case of a technical failure.'),
    'field' => [
      'id' => 'standard',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'string',
    ],
    'argument' => [
      'id' => 'string',
    ],
  ];

  $data['gcnotify']['created_at'] = [
    'title' => t('created_at'),
    'help' => t('The time the service sent the request.'),
    'field' => [
      'id' => 'date',
    ],
    'sort' => [
      'id' => 'date',
    ],
    'filter' => [
      'id' => 'date',
    ],
  ];

  $data['gcnotify']['completed_at'] = [
    'title' => t('completed_at'),
    'help' => t('The last time the status was updated.'),
    'field' => [
      'id' => 'date',
    ],
    'sort' => [
      'id' => 'date',
    ],
    'filter' => [
      'id' => 'date',
    ],
  ];

  $data['gcnotify']['sent_at'] = [
    'title' => t('sent_at'),
    'help' => t('The time the notification was sent.'),
    'field' => [
      'id' => 'date',
    ],
    'sort' => [
      'id' => 'date',
    ],
    'filter' => [
      'id' => 'date',
    ],
  ];

  $data['gcnotify']['callback_received'] = [
    'title' => t('callback_received'),
    'help' => t('The time the gcnotify callback api call was received.'),
    'field' => [
      'id' => 'date',
    ],
    'sort' => [
      'id' => 'date',
    ],
    'filter' => [
      'id' => 'date',
    ],
  ];

  $data['gcnotify']['webform_sid'] = [
    'title' => t('webform_sid'),
    'help' => t('The webform submission id, if found.'),
    'field' => [
      'id' => 'numeric',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'numeric',
    ],
    'argument' => [
      'id' => 'numeric',
    ],
  ];

  $data['gcnotify']['environment'] = [
    'title' => t('environment'),
    'help' => t('The environment from which a request to GC Notify was originated.'),
    'field' => [
      'id' => 'standard',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'string',
    ],
    'argument' => [
      'id' => 'string',
    ],
  ];

  $data['gcnotify']['notification_type'] = [
    'title' => t('notification_type'),
    'help' => t('The notification type.'),
    'field' => [
      'id' => 'standard',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'string',
    ],
    'argument' => [
      'id' => 'string',
    ],
  ];

  return $data;
}
