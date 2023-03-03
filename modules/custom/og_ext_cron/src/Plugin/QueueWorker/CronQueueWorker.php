<?php

namespace Drupal\og_ext_cron\Plugin\QueueWorker;

use \Drupal\og_ext_cron\Utils\CronFunctions;
use \Drupal\Core\Queue\QueueWorkerBase;

/**
 * @file
 * Contains \Drupal\mymodule\Plugin\QueueWorker\EmailQueue.
 */

/**
 * Runs memory intensive functions in a worker queue.
 * Heavily intensive functions should use the queue
 * and not the above cron hook.
 * 
 * The cron time is the number of seconds that
 * Drupal should spend on calling this worker.
 *
 * @QueueWorker(
 *   id = "ogp_custom_queue",
 *   title = @Translation("OGP Custom Queue"),
 *   cron = {"time" = 300}
 * )
 */
final class CronQueueWorker extends QueueWorkerBase{

  /**
   * {@inheritdoc}
   * 
   * Double check that the cron_function exists in our class.
   * It could be possible that createItem was called directly.
   */
  public function processItem($data) {

    if(
      ! is_array( $data )
      || ! array_key_exists( 'cron_function', $data )
    ){

      \Drupal::logger('cron')->error('No cron_function supplied to worker queue ogp_custom_queue');
      return;

    }

    if( ! method_exists('\Drupal\og_ext_cron\Utils\CronFunctions', $data['cron_function'] ) ){

      \Drupal::logger('cron')->error('Method ' . $data['cron_function'] . ' not found in class \Drupal\og_ext_cron\Utils\CronFunctions');
      return;

    }

    CronFunctions::$data['cron_function']();

  }

}
