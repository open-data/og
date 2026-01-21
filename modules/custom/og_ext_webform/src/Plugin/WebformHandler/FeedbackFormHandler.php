<?php

namespace Drupal\og_ext_webform\Plugin\WebformHandler;

use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Form submission handler.
 *
 * @WebformHandler(
 *   id = "feedback_form_handler",
 *   label = @Translation("Feedback Form Handler"),
 *   category = @Translation("Form Handler"),
 *   description = @Translation("Populates the email address for the feedback form."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class FeedbackFormHandler extends WebformHandlerBase {

  /**
   * {@inheritdoc}
   */
  public function preSave(WebformSubmissionInterface $webform_submission) {

    $url = $webform_submission->getElementData('feedback_webpage');
    if (empty($url)) {
      \Drupal::logger('feedback')->warning(
        'No URL provided in feedback_webpage field for feedback submission.'
      );
      return;
    }

    $path = parse_url($url, PHP_URL_PATH);
    if (empty($path)) {
      \Drupal::logger('feedback')->warning(
        'Invalid URL provided in feedback_webpage field: @url',
        ['@url' => $url]
      );
      return;
    }

    // Trim trailing slash to safely extract UUID
    $uuid = basename(rtrim($path, '/'));

    // Validate UUID
    if (!preg_match(
      '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
      $uuid
    )) {
      \Drupal::logger('feedback')->warning(
        'Invalid UUID provided for feedback dataset URL: @url',
        ['@url' => $url]
      );
      return;
    }

    // Build CKAN API URL.
    $api_url = \Drupal::request()->getSchemeAndHttpHost()
      . '/data/api/action/package_show?id='
      . $uuid;

    if (!filter_var($api_url, FILTER_VALIDATE_URL)) {
      \Drupal::logger('feedback')->error(
        'Invalid CKAN API URL generated for feedback dataset: @api, @url',
        ['@api' => $api_url, '@url' => $url]
      );
      return;
    }

    try {
      $response = \Drupal::httpClient()->get($api_url, [
        'timeout' => 10,
        'headers' => [
          'Accept' => 'application/json',
        ],
      ]);

      if ($response->getStatusCode() !== 200) {
        \Drupal::logger('feedback')->error(
          'CKAN API returned HTTP @code for dataset feedback request: @api',
          [
            '@code' => $response->getStatusCode(),
            '@api' => $api_url,
          ]
        );
        return;
      }

      $data = json_decode($response->getBody()->getContents(), TRUE);

      // Set the ati_email field on the webform submission.
      if (
        json_last_error() === JSON_ERROR_NONE &&
        !empty($data['result']) &&
        !empty($data['result']['maintainer_email']) &&
        filter_var($data['result']['maintainer_email'], FILTER_VALIDATE_EMAIL)
      ) {
        $webform_submission->setElementData(
          'ati_email',
          $data['result']['maintainer_email']
        );
      }
      else {
        \Drupal::logger('feedback')->warning(
          'Invalid or missing maintainer_email returned from CKAN API for dataset: @api',
          ['@api' => $api_url]
        );
      }

    }
    catch (RequestException $e) {
      \Drupal::logger('feedback')->error(
        'Unable to fetch maintainer_email from CKAN API for dataset @api. Exception: @message',
        [
          '@api' => $api_url,
          '@message' => $e->getMessage(),
        ]
      );
    }
  }

}
