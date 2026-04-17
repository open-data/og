<?php

namespace Drupal\og_ext_webform\Plugin\WebformHandler;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform\WebformTranslationManagerInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\Plugin\WebformHandlerMessageInterface;
use Drupal\webform\Plugin\WebformHandler\EmailWebformHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\search_api\Entity\Index;
use Drupal\gcnotify\Utils\NotificationAPIHandler;

/**
 * Form submission handler.
 *
 * @WebformHandler(
 *   id = "feedback_form_handler",
 *   label = @Translation("Feedback Form Handler"),
 *   category = @Translation("Form Handler"),
 *   description = @Translation("Email the feedback form to the dataset owner."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class FeedbackFormHandler extends WebformHandlerBase implements ContainerFactoryPluginInterface {

    protected static $isProcessing = FALSE;
    protected $loggerFactory;
    protected DateFormatterInterface $dateFormatter;
    protected WebformTranslationManagerInterface $translationManager;

    public function __construct(
        array $configuration,
        $plugin_id,
        $plugin_definition,
        LoggerChannelFactoryInterface $logger_factory,
        ConfigFactoryInterface $config_factory,
        EntityTypeManagerInterface $entity_type_manager,
        DateFormatterInterface $date_formatter,
        WebformTranslationManagerInterface $translation_manager
    ) {
        parent::__construct($configuration, $plugin_id, $plugin_definition);

        $this->configFactory = $config_factory;
        $this->entityTypeManager = $entity_type_manager;
        $this->loggerFactory = $logger_factory;
        $this->dateFormatter = $date_formatter;
        $this->translationManager = $translation_manager;
    }

    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $container->get('logger.factory'),
            $container->get('config.factory'),
            $container->get('entity_type.manager'),
            $container->get('date.formatter'),
            $container->get('webform.translation_manager')
        );
    }

    protected function getFeedbackLogger() {
        return $this->loggerFactory->get('feedback');
    }

    /**
     * Provide handler summary for admin UI.
     */
    public function getSummary() {
        return [
            '#markup' => $this->t('Email the feedback form to the dataset owner.'),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function preSave(WebformSubmissionInterface $webform_submission) {

        $url = $webform_submission->getElementData('feedback_webpage');
        if (empty($url)) {
            $this->getFeedbackLogger()->error(
              'No URL provided in feedback_webpage field for feedback submission.'
            );
            return;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (empty($path)) {
            $this->getFeedbackLogger()->error(
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
            $this->getFeedbackLogger()->error(
              'Invalid UUID @uuid provided for feedback dataset URL: @url',
              ['@uuid' => $uuid, '@url' => $url]
            );
            return;
        }

        // Get maintainer_email from the Solr index
        $maintainer_email = $this->getContactEmail($uuid, $url);

        // Set the ati_email field on the webform submission.
        if (!empty($maintainer_email) &&
            filter_var($maintainer_email, FILTER_VALIDATE_EMAIL) ) {
            $webform_submission->setElementData('ati_email', $maintainer_email);
        }
        else {
            $webform_submission->setElementData('ati_email', '');
            $this->getFeedbackLogger()->error(
              'Invalid or missing maintainer_email returned from CKAN Solr index for dataset: @url',
              ['@url' => $url]
            );
        }

    }

    /**
     * {@inheritdoc}
     */
    public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {

        // If we are already in the middle of processing this class, stop immediately.
        if (static::$isProcessing) {
            return;
        }

        // Standard update check (unless it's our special Drush migration)
        $is_drush = isset($webform_submission->in_drush_mode) && $webform_submission->in_drush_mode === TRUE;
        if ($update && !$is_drush) {
            return;
        }

        // Set the lock
        static::$isProcessing = TRUE;

        try {
            $to = $webform_submission->getElementData('ati_email');
            if (!$to) {
                return;
            }

            $helpdesk_email = \Drupal\Core\Site\Settings::get('ati_email');

            // Do not send feedback to support inboxes
            if ($to == 'open-ouvert@tbs-sct.gc.ca' || $to == $helpdesk_email) {
                return;
            }

            $notify = new NotificationAPIHandler();
            $response = $notify->sendGCNotifyEmail(
                $to,
                $this->getWebform()->id(),
                $this->getRequestOptions($webform_submission),
                $webform_submission->getLangcode()
            );

            $handler_id = 'notifications';
            $webform = $this->getWebform();
            $handler = $webform->getHandler($handler_id);
            $is_default_mail = false;

            if (!$response || $response->getStatusCode() >= 300) {
                if (!$handler instanceof EmailWebformHandler) {
                    $this->getFeedbackLogger()->error(
                        'Email handler "@id" not found.',
                        ['@id' => $handler_id]);
                    return;
                }

                $message = $handler->getMessage($webform_submission);
                $handler->sendMessage($webform_submission, $message);
                $is_default_mail = true;
            }

            $this->addNotesToWebformSubmission(
                $webform_submission,
                $is_default_mail,
                $handler_id,
                $response
            );

        } catch (\Exception $e) {
            $this->getFeedbackLogger()->error('Failed to process SID @sid: @msg', [
                '@sid' => $webform_submission->id(),
                '@msg' => $e->getMessage(),
            ]);
        } finally {
            static::$isProcessing = FALSE;
        }

    }

    protected function getContactEmail($uuid, $url) {

        $index_name = \Drupal\Core\Site\Settings::get('feedback_index', 'ckan_portal');
        $index = Index::load($index_name);

        if (!$index) {
            $this->getFeedbackLogger()->error(
              'Solr index not provided for feedback dataset URL: @url',
              ['@url' => $url]
            );
            return null;
        }

        $query = $index->query();
        $query->addCondition('id', $uuid);
        $results = $query->execute();
        $items = $results->getResultItems();
        $row = !empty($items) ? $items[array_key_first($items)] : null;

        if (!$row) {
            $this->getFeedbackLogger()->error(
                'UUID @uuid not found in CKAN Solr index for feedback dataset URL: @url',
                ['@uuid' => $uuid, '@url' => $url]
            );
            return null;
        }

        $maintainer_email = $row->getField('maintainer_email')?->getValues();
        return $maintainer_email[0] ?? null;
    }

    protected function getRequestOptions(WebformSubmissionInterface $webform_submission)
    {

        $langcode = $webform_submission->getLangcode();
        $webform = $webform_submission->getWebform();
        $webform_values = $webform_submission->getData();
        $translation = $this->translationManager->getTranslationElements($webform, $langcode);

        $created = $webform_submission->get('created')->value;

        $data = '';

        foreach ($webform_values as $key => $value) {
            $element = $webform->getElement($key);
            if (!$element) {
                continue;
            }

            // check if not private
            if (
                ( array_key_exists('#access', $element) === false
                || $element['#access'] === true )
            ) {

                // get translated label of form element
                $element_label = (array_key_exists($key, $translation))
                ? $translation[$key]['#title']
                : $element['#title'];

                // generate element in pattern [key]: value
                $data .= '**' . $element_label . '**' . "\r\n";
                if ($element['#type'] == 'select') {
                    $data .= (array_key_exists($key, $translation)
                      && array_key_exists('#options', $translation[$key]))
                    ? $translation[$key]['#options'][$value]
                    : $element['#options'][$value];
                } else {
                      $data .= $value;
                }

                $data .= "\r\n \r\n";

            }
        }

        $personalisation = [
            'webform_submission_sid' => $webform_submission->id(),
            'webform_submission_created' => $this->dateFormatter->format($created, 'medium', '', 'America/Toronto'),
            'webform_submission_values' => $data,
            'webform_submission_reference' => (!empty($webform_submission->in_drush_mode) && $webform_submission->in_drush_mode === TRUE)
                ? $webform_submission->getElementData('feedback_webpage')
                : '',
        ];

        return $personalisation;
    }

    protected function addNotesToWebformSubmission(
        WebformSubmissionInterface $webform_submission,
        $default_mail,
        $handler_id,
        $response
    ) {

        // add GC Notify response as Administrative notes to the webform submission

        $notes = $webform_submission->getNotes() ?? '';

        if ($default_mail) {
            $notes .= "\r\n"
              . 'Unable to send '
              . $handler_id
              . ' email using GC Notify. Email sent using PHP mail.';
        }

        if ($response) {

            $body = $response->getBody()->getContents();
            $response_data = json_decode($body, true);
            $note = "\r\n" . ucfirst($handler_id);

            if ($response->getStatusCode() < 300) {
                $note .= " email sent using GCNotify. Details:\r\n";
            } else {
                $note .= " email failed to send using GCNotify. Details:\r\n";
            }

            $note .= json_encode([
                'handler_id' => $handler_id,
                'status_code' => $response->getStatusCode(),
                'gcnotify_response' => $response_data,
                ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            );
            $notes .= "\r\n" . $note;

        } else {
            $notes .= "\r\n"
              . 'Unable to send email using GC Notify. GC Notify Settings not available.';
        }

        $webform_submission->setNotes($notes);
        if ($webform_submission->getElementData('status') === 'outstanding') {
            $webform_submission->setElementData('status', 'helpdesk');
        }
        $webform_submission->setSyncing(TRUE);
        $webform_submission->save();
    }

}
