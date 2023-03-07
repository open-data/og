<?php

namespace Drupal\og_ext_cron\Plugin\QueueWorker;

use \Drupal\Core\Queue\DatabaseQueue;
use \Drupal\og_ext_cron\Plugin\QueueWorker\CronQueueWorker;

/**
 * Extends the default DatabaseQueue for checks
 * on creating queue job items, making sure that
 * the passed data matches our CronFunctions methods.
 * 
 * @ingroup queue
 */
class CronQueue extends DatabaseQueue{

  /**
   * The current queue data in the queue table
   * 
   * @var array $currentQueueItemData
   */
  private $currentQueueItemData = [];

  /**
   * {@inheritdoc}
   */
  public function __construct($name, $connection) {

    parent::__construct($name, $connection);
    $this->currentQueueItemData = $this->_get_current_queue_data();

  }

  /**
   * @method get
   * @param string $_variable
   * @return self
   */
  public function get($_variable = 'queue'){

    return new self(CronQueueWorker::$QUEUE_NAME, \Drupal::database());

  }

  /**
   * {@inheritdoc}
   */
  public function createItem($data) {

    if(
      ! is_array( $data )
      || ! array_key_exists( 'cron_function', $data )
    ){
  
      \Drupal::logger('cron')->error('No cron_function supplied to worker queue ' . CronQueueWorker::$QUEUE_NAME);
      return;
  
    }
  
    if( ! method_exists('\Drupal\og_ext_cron\Utils\CronFunctions', $data['cron_function'] ) ){
  
      \Drupal::logger('cron')->error('Method ' . $data['cron_function'] . ' not found in class \Drupal\og_ext_cron\Utils\CronFunctions');
      return;
  
    }

    $unique = array_key_exists( 'be_unique', $data ) ? $data['be_unique'] : false;
  
    if( $unique && in_array( $data, $this->currentQueueItemData ) ){
  
      \Drupal::logger('cron')->warning('Method ' . $data['cron_function'] . ' already in worker queue ' . CronQueueWorker::$QUEUE_NAME . ', skipping.');
      return;
  
    }
  
    $queueID = parent::createItem($data);
  
    if( ! $queueID ){
  
      \Drupal::logger('cron')->error('Failed to add CronFunctions::' . $data['cron_function'] . ' to the worker queue ' . CronQueueWorker::$QUEUE_NAME);
  
    }
  
    \Drupal::logger('cron')->notice('Added CronFunctions::' . $data['cron_function'] . ' to the worker queue ' . CronQueueWorker::$QUEUE_NAME . ' with the ID of: ' . $queueID);
    return $queueID;

  }

  /**
   * @method _get_current_queue_data
   * @return array
   */
  private function _get_current_queue_data(){

    $currentQueueItemData = \Drupal::database()->select( 'queue', 't' )
                                              ->fields( 't', ['data'] )
                                              ->condition( 't.name', CronQueueWorker::$QUEUE_NAME )
                                              ->execute()->fetchAll();

    $return = [];

    foreach( $currentQueueItemData as $_itemData ){

      if( ! is_object( $_itemData ) || ! isset( $_itemData->data ) ){ continue; }

      $data = unserialize($_itemData->data);

      if( ! $data ){

        \Drupal::logger('cron')->warning('Failed to unserialize string ' . $_itemData->data);
        continue;

      }

      $return[] = $data;

    }

    return $return;

  }

}