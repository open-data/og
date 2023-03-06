<?php

namespace Drupal\og_ext_cron\Plugin\QueueWorker;

use \Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use \Drupal\Core\Queue\QueueWorkerBase;

/**
 * @file
 * Contains \Drupal\og_ext_cron\Plugin\QueueWorker\CronQueueWorker.
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
final class CronQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface{

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new class instance.
   *
   * @param array $_configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $_pluginID
   *   The plugin_id for the plugin instance.
   * @param mixed $_pluginDefinition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $_entityTypeManager
   *   Entity type manager service.
   */
  public function __construct($_configuration, $_pluginID, $_pluginDefinition, $_entityTypeManager) {
    parent::__construct($_configuration, $_pluginID, $_pluginDefinition);
    $this->entityTypeManager = $_entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create($container, $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

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

    call_user_func(['\Drupal\og_ext_cron\Utils\CronFunctions', $data['cron_function']]);

  }

}
