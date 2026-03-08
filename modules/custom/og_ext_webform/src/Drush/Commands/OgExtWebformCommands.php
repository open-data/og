<?php

namespace Drupal\og_ext_webform\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Utility\Token;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Drupal\webform\Entity\WebformSubmission;

/**
 * A Drush commandfile.
 */
final class OgExtWebformCommands extends DrushCommands {

    use AutowireTrait;

    /**
     * Constructs an OgExtWebformCommands object.
     */
    public function __construct(
        private readonly Token $token,
        ) {
            parent::__construct();
        }

  /**
   * Command description here.
   */
    #[CLI\Command(name: 'feedback_webform:send', aliases: ['fws'])]
    #[CLI\Option(name: 'delay', description: 'Delay between consecutive emails, defaults to 5 seconds.')]
    #[CLI\Usage(
        name: 'feedback_webform:send',
        description: 'Sends outstanding feedback emails with optional delay between consecutive emails.'
    )]
    public function sendOutstandingFeedback($options = ['delay' => 5]) {

        $total_processed = 0;
        $connection = \Drupal::database();

        $sids = $connection->select('webform_submission_data', 'wsd')
            ->distinct()
            ->fields('wsd', ['sid'])
            ->condition('wsd.webform_id', 'feedback')
            ->condition('wsd.name', 'status')
            ->condition('wsd.value', 'outstanding')
            ->execute()
            ->fetchCol();

        if (empty($sids)) {
            $this->output()->writeln("No outstanding feedback to send.");
            return;
        }

        $sids = array_values($sids);
    
        foreach ($sids as $sid) {
            $submission = WebformSubmission::load($sid);
            $submission->in_drush_mode = TRUE;
            $submission->save();

            $this->output()->writeln(
                "Outstanding feedback submission# {$submission->id()} sent for dataset {$submission->getElementData('feedback_webpage')}"
                );

            $total_processed++;
            sleep((int)$options['delay']);
        }

        \Drupal::entityTypeManager()->getStorage('webform_submission')->resetCache($sids);
        $this->output()->writeln("Done! Total submissions processed: $total_processed");

    }

}
