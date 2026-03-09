<?php

namespace Drupal\og_ext_webform\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;

/**
 * A Drush commandfile.
 */
final class OgExtWebformCommands extends DrushCommands {

    use AutowireTrait;

    protected EntityTypeManagerInterface $entityTypeManager;
    protected Connection $database;

    public function __construct(EntityTypeManagerInterface $entity_type_manager, Connection $database) {
        parent::__construct();
        $this->entityTypeManager = $entity_type_manager;
        $this->database = $database;
    }

    /**
     * Sends outstanding feedback emails with optional delay between consecutive emails.
     */
    #[CLI\Command(name: 'feedback_webform:send', aliases: ['fws'])]
    #[CLI\Option(name: 'delay', description: 'Delay between consecutive emails, defaults to 2 seconds.')]
    #[CLI\Usage(name: 'drush fws --delay=5', description: 'Send feedback emails with a 5 second delay.')]
    public function sendOutstandingFeedback(array $options = ['delay' => 2]) {

        $total_processed = 0;
        $delay = max(2, (int) $options['delay']);
        $storage = $this->entityTypeManager->getStorage('webform_submission');

        $sids = $this->database->select('webform_submission_data', 'wsd')
            ->distinct()
            ->fields('wsd', ['sid'])
            ->condition('wsd.webform_id', 'feedback')
            ->condition('wsd.name', 'status')
            ->condition('wsd.value', 'outstanding')
            ->orderBy('wsd.sid')
            ->execute()
            ->fetchCol();

        if (empty($sids)) {
            $this->io()->writeln("No outstanding feedback to send.");
            return;
        }

        $this->io()->progressStart(count($sids));
        $batches = array_chunk($sids, 50);

        foreach ($batches as $batch) {
            $submissions = $storage->loadMultiple($batch);
            foreach ($submissions as $sid => $submission) {

                if (!$submission) {
                    continue;
                }

                $submission->in_drush_mode = TRUE;
                $submission->save();

                $this->io()->writeln(
                    "Outstanding feedback submission #{$submission->id()} sent for dataset {$submission->getElementData('feedback_webpage')}"
                    );

                $total_processed++;
                $storage->resetCache([$sid]);
                sleep($delay);
            }

            $this->io()->progressAdvance(count($batch));
        }

        $this->io()->progressFinish();
        $this->io()->writeln("Done! Total submissions processed: $total_processed");

    }

}
