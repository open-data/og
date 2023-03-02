<?php

namespace Drupal\og_ext_cron\Utils;

use Drupal\Core\Cache\Cache;
use \Drupal\node\Entity\Node;
use Symfony\Component\Yaml\Parser;
use Drupal\views\Views;
use \Drupal\webform\Entity\WebformSubmission;
use \Drush\Drush;

/**
 * Class CronFunctions.
 */
final class CronFunctions {

  /**
   * clear cache of views generated using Solr
   */
  public static function clear_view_caches() {
    $pd_views = [
      'pd_core_ati',
      'pd_core_contracts',
      'pd_core_inventory',
      'pd_core_hospitalityq',
      'pd_core_reclassification',
      'pd_core_travela',
      'pd_core_travelq',
      'pd_core_wrongdoing',
      'pd_core_ati_details',
      'pd_core_contracts_details',
      'pd_core_hospitalityq_details',
      'pd_core_reclassification_details',
      'pd_core_travelq_details',
      'pd_core_wrongdoing_details',
      'pd_core_travela_details',
      'pd_core_inventory_details',
    ];

    foreach ($pd_views as $view_name) {
      $view = Views::getView($view_name);
      if ($view) {
        $tags = $view->getCacheTags();
        Cache::invalidateTags($tags);
        \Drupal::logger('cron')->notice('Cache cleared for ' . ( \strlen($view->getTitle()) > 0 ? $view->getTitle() : $view_name));
      }
    }
  }

  /**
   * Export all published comments into a csv file
   */
  public static function export_external_comments() {
    // fetch comments
    $comment_ids = \Drupal::entityQuery('comment')
      ->condition('entity_type', 'node')
      ->condition('status', 1)
      ->execute();

    $comments_data = [];

    // if comments exist
    if ($comment_ids) {
      $comments = \Drupal::entityTypeManager()
        ->getStorage('comment')
        ->loadMultiple($comment_ids);

      /**
       * @var \Drupal\comment\CommentInterface $comment
       */
      foreach($comments as $comment) {
        $node = $comment->getCommentedEntity();

        // Loop over and get fields for published comments
        /**
         * @var \Drupal\node\Entity\Node $node
         */
        if ($comment->isPublished() && $node->isPublished()) {
          $url_en = ($node->hasTranslation('en') ? 'https://open.canada.ca' . $node->getTranslation('en')->url() : '');
          $url_fr = ($node->hasTranslation('fr') ? 'https://ouvert.canada.ca' . $node->getTranslation('fr')->url() : '');
	  $uuid = ($node->type->entity->id() === 'external') ? $node->field_uuid->value : '';
          $comments_data[] = [
            'comment_id' => $comment->id(),
            'page_en' => $url_en,
            'page_fr' => $url_fr,
            'subject' => $comment->getSubject(),
            'comment_body' => $comment->get('comment_body')->getValue()[0]['value'],
            'comment_posted_by' => $comment->getAuthorName(),
            'date_posted' => \Drupal::service('date.formatter')->format($comment->getCreatedTime(), 'html_date'),
	    'node_id' => $node->id(),
	    'node_type' => $node->type->entity->label(),
	    'dataset_uuid' => $uuid,
          ];
        }
      }
    }

    $header = [
      'Comment id',
      'English page',
      'French page',
      'Subject',
      'Body',
      'Posted by',
      'Date posted',
      'Node ID',
      'Node type',
      'Dataset ID',
    ];

    // export as csv
    self::write_to_csv('export_comments.csv', $comments_data, $header);

    // log results
    \Drupal::logger('cron')->notice('Comments export to csv file completed');

    $comment_ids = null;
    $comments_data = null;
  }

  /**
   * Export all published comments into a csv file
   */
  public static function export_suggested_datasets() {
    // fetch suggested dataset nodes
    $nids = \Drupal::entityQuery('node')
      ->condition('status', 1)
      ->condition('type', 'suggested_dataset')
      ->execute();

    $export_data = [];

    // if dataset suggestions exist
    if ($nids) {
      $nodes = Node::loadMultiple($nids);

      foreach($nodes as $node) {
        // get translation of node
	$node_en = $node->hasTranslation('en') ? $node->getTranslation('en') : $node;
	$node_fr = $node->hasTranslation('fr') ? $node->getTranslation('fr') : null;

	if ($node_en && $node_fr) {
          // set default values
          $subject = $node->get('field_dataset_subject')->getValue()
            ? self::implodeAllValues($node->get('field_dataset_subject')->getValue())
            : 'information_and_communications';
          $keywords_en = $node_en->get('field_dataset_keywords')->getValue()
            ? self::implodeAllValues($node_en->get('field_dataset_keywords')->getValue())
            : 'dataset';
          $keywords_fr = $node_fr->get('field_dataset_keywords')->getValue()
            ? self::implodeAllValues($node_fr->get('field_dataset_keywords')->getValue())
            : 'Jeu de données';
          $status = $node->get('field_sd_status')->getValue()
            ? $node->get('field_sd_status')->getValue()[0]['value']
            : 'department_contacted';
          $organization = is_array($fv = $node->get('field_organization')->getValue()) && array_key_exists('value', $fv[0])
            ? $fv[0]['value'] : null;
          $description_en = is_array($fv = $node_en->get('body')->getValue()) && array_key_exists('value', $fv[0])
            ? strip_tags($fv[0]['value']) : null;
          $description_fr = is_array($fv = $node_fr->get('body')->getValue()) && array_key_exists('value', $fv[0])
            ? strip_tags($fv[0]['value']) : null;
          $status_link = is_array($fv = $node->get('field_status_link')->getValue()) && array_key_exists('value', $fv[0])
            ? $fv[0]['value'] : null;
          $date_published = is_array($fv = $node->get('field_date_published')->getValue()) && array_key_exists('value', $fv[0])
            ? $fv[0]['value'] : null;
          $votes = is_array($fv = $node->get('field_vote_up_down')->getValue()) && array_key_exists('value', $fv[0])
            ? $fv[0]['value'] : null;
          $additional_comments_and_feedback_en = is_array($fv = $node_en->get('field_feedback')->getValue()) && array_key_exists('value', $fv[0])
            ? $fv[0]['value'] : null;
          $additional_comments_and_feedback_fr = is_array($fv = $node_fr->get('field_feedback')->getValue()) && array_key_exists('value', $fv[0])
            ? $fv[0]['value'] : null;
          $data = [
            'uuid' => $node->uuid(),
            'suggestion_id' => $node->id(),
            'date_created' => date('Y-m-d', $node->getCreatedTime()),
            'title_en' => $node_en->getTitle(),
            'title_fr' => $node_fr->getTitle(),
            'organization' => $organization,
            'description_en' => $description_en,
            'description_fr' => $description_fr,
            'dataset_suggestion_status' => $status,
            'dataset_suggestion_status_link' => $status_link,
            'dataset_released_date' => $date_published,
            'votes' => $votes,
            'subject' => $subject,
            'keywords_en' => $keywords_en,
            'keywords_fr' => $keywords_fr,
            'additional_comments_and_feedback_en' =>  $additional_comments_and_feedback_en,
            'additional_comments_and_feedback_fr' =>  $additional_comments_and_feedback_fr,
          ];

          // get webform submission for suggested datasets
          if( 
            is_array($fv = $node->get('field_webform_submission_id')->getValue())
            && array_key_exists('value', $fv[0])
          ){
            $wid = $fv[0]['value'];
            if ($webform_submission = WebformSubmission::load($wid)) {
              $webform_data = [
                'webform_submission_id' => $wid,
                'reason' => $webform_submission->getElementData('reason'),
                'email' => $webform_submission->getElementData('e_mail_address'),
              ];
              $data = array_merge($data, $webform_data);
            }
          }
          $export_data[] = $data;
        }

        $node_en = null;
        $node_fr = null;
      }

      $nodes = null;
    }

    $header = [
      'uuid',
      'suggestion_id',
      'date_created',
      'title_en',
      'title_fr',
      'organization',
      'description_en',
      'description_fr',
      'dataset_suggestion_status',
      'dataset_suggestion_status_link',
      'dataset_released_date',
      'votes',
      'subject',
      'keywords_en',
      'keywords_fr',
      'additional_comments_and_feedback_en',
      'additional_comments_and_feedback_fr',
      'webform_submission_id',
      'reason',
      'email',
    ];

    // export as csv
    self::write_to_csv('suggested-dataset.csv', $export_data, $header, FALSE);

    // log results
    \Drupal::logger('cron')->notice('Suggested datasets exported');

    $nids = null;
    $export_data = null;
  }

  /**
   * Export dataset ratings as CSV with cumulative ratings and vote count
   */
  public static function export_cumulative_dataset_ratings() {
    try {
      // fetch ratings from database
      $database = \Drupal::database();
      $result = $database->query("SELECT uuid, vote_average, vote_count,
                          CONCAT('https://open.canada.ca/data/en/dataset/', uuid) as url_en,
                          CONCAT('https://ouvert.canada.ca/data/fr/dataset/', uuid) as url_fr
                        FROM {external_rating}
                        WHERE type = :type
                        ORDER BY vote_average DESC, vote_count DESC", [':type' => 'dataset',]);

      if (!$result) {
        throw new \Exception('Failed to return results from database.');
      }

      // fetch dataset titles from ckan
      $datasets = [];
      $filename = \Drupal\Core\Site\Settings::get('ckan_public_path') . '/od-do-canada.jl.gz';
      $handle = gzopen($filename, 'r');
      if (!$handle) {
        throw new \Exception('Failed to open Portal Catalogue dataset.');
      }

      while (!gzeof($handle)) {
        $line = gzgets($handle);
        $data = json_decode($line, TRUE);
        $datasets[$data['id']] = ['en' => $data['title_translated']['en'], 'fr' => $data['title_translated']['fr']];
      }
      gzclose($handle);

      if (!sizeof($datasets)) {
        throw new \Exception('Failed to read content from Portal Catalogue dataset.');
      }

      // generate output data stream
      $output_data = [];
      while ($row = $result->fetchAssoc()) {
        if (array_key_exists($row['uuid'], $datasets)) {
          $row = [ 'fr' => $datasets[$row['uuid']]['fr'] ] + $row;
          $row = [ 'en' => $datasets[$row['uuid']]['en'] ] + $row;
          $output_data[] = $row;
        }
      }

      $header = ['title_en / titre_en',
        'title_fr / titre_fr',
        'uuid',
        'avg_user_rating / coter_moyen',
        'rating_count / nombre_coter',
        'url_en',
        'url_fr',
      ];

      // export as csv
      self::write_to_csv('dataset-ratings.csv', $output_data, $header);

      // log results
      \Drupal::logger('cron')->notice('Dataset ratings exported');

      $result = null;
      $datasets = null;
      $output_data = null;
    }

    catch (\Exception $e) {
      \Drupal::logger('cron')->error('Unable to export dataset ratings ' . $e->getMessage());
    }
  }

  /**
   * Set dynamic allowed values for organization field
   * The options will be same as CKAN
   */
  public static function fetch_orgs_from_ckan() {
    $options = [];
    $filename = \Drupal\Core\Site\Settings::get('ckan_public_path') . '/od-do-orgs.jsonl';
    if (file_exists($filename) && $contents = file($filename)) {
      foreach ($contents as $line) {
        $data = json_decode($line);
        $title = explode('|', $data->title);
        $options[trim($data->name)] = [ 'en' => $title[0], 'fr' => $title[1]];
      }

      // remove external-externe organization
      if (array_key_exists('external-externe', $options)) {
        unset($options['external-externe']);
      }

      if (!empty($options)) {
        // Write to choices folder
        $module_handler = \Drupal::service('module_handler');
        $module_path = $module_handler->getModule('og_ext_cron')->getPath();
        $filename = $module_path . '/choices/organizations.json';
        if (file_put_contents($filename, json_encode($options, JSON_PRETTY_PRINT)))
          \Drupal::logger('cron')->notice('Organizations list updated from CKAN');
        else
          \Drupal::logger('cron')->error('Unable to write organizations list from file ' . $filename);
      }
    }
    else
      \Drupal::logger('cron')->error('Unable to read file ' . $filename);
  }

  /**
   * Set dynamic allowed values for given field
   * The options will be same as CKAN
   */
  public static function fetch_from_ckan($field_name, $field_type) {
//    $langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();
    $options = [];
    $url = 'https://open.canada.ca/data/api/action/scheming_dataset_schema_show?type=dataset';
    $url = filter_var($url, FILTER_SANITIZE_URL);
    if (filter_var($url, FILTER_VALIDATE_URL) !== FALSE) {
      $client = \Drupal::httpClient();
      try {
        $request = $client->get($url);
        if ($request->getStatusCode() == '200') {
          $response = $request->getBody()->getContents();
          $data = json_decode($response, TRUE);

          foreach ($data['result'][$field_type] as $rsc) {
            if ($rsc['field_name'] == $field_name) {
              foreach ($rsc['choices'] as $choice) {
                if (array_key_exists('label', $choice)) {
                  $label_en = is_array($choice['label']) ?  $choice['label']['en'] : $choice['label'];
                  $label_fr = is_array($choice['label']) ?  $choice['label']['fr'] : $choice['label'];
                  $options[$choice['value']] = [ 'en' => $label_en, 'fr' => $label_fr];
                }
                else {
                  $options[$choice['value']] = $choice['value'];
                }
              }
              break;
            }
          }

          if (!empty($options)) {
            // Write to choices folder
            $module_handler = \Drupal::service('module_handler');
            $module_path = $module_handler->getModule('og_ext_cron')->getPath();
            if (file_put_contents($module_path . '/choices/' . $field_name . '.json', json_encode($options, JSON_PRETTY_PRINT)))
              \Drupal::logger('cron')->notice($field_name . ' list updated from CKAN');
            else
              \Drupal::logger('cron')->error('Unable to write ' . $field_name . '.json');
          }
          else
            \Drupal::logger('cron')->error('Unable to fetch ' . $field_name . ' from CKAN');
        }
      }
      catch (\Exception $e) {
        \Drupal::logger('cron')->error('Unable to fetch from api for ' . $url
          . ' Exception: ' . $e->getMessage());
      }
    }

    return $options;
  }

  /**
   * Helper function to combine all values in a nested array in a string
   * @param $parentArray
   * @return string
   */
  private static function implodeAllValues($parentArray) {
    $values = '';
    $size = sizeof($parentArray);
    $i=0;

    if ($parentArray) {
      foreach ($parentArray as $childArray) {
        $i++;
        $values .= $childArray['value'];
        if ($i < $size) {
          $values .=  ',';
        }
      }
    }

    return $values;
  }

  /**
   * Generate output file for given data and headers
   */
  private static function write_to_csv($filename, $data_to_write, $csv_header, $public = TRUE, $_append = FALSE) {
    try {
      // create output csv
      $path = $public
        ? \Drupal::service('file_system')->realpath(\Drupal::config('system.file')->get('default_scheme') . "://")
        : \Drupal\Core\Site\Settings::get('file_private_path');
      $fileMode = $_append ? 'a' : 'w';
      $output = fopen($path . '/' . $filename, $fileMode);
      if (!$output) {
        throw new \Exception('Failed to create export file.');
      }

      if( ! $_append ){
        // add BOM to fix UTF-8 in Excel
        fputs($output, $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF)));
        // add csv header columns
        fputcsv($output, $csv_header, ',', '"');
      }

      if( Drush::verbose() ){
        \Drupal::logger('cron')->notice('Writing ' . count($data_to_write) . ' rows with mode ' . $fileMode);
      }

      // write to csv
      foreach($data_to_write as $row) {
        fputcsv($output, $row, ',', '"');
      }

      fclose($output);
    }

    catch (\Exception $e) {
      \Drupal::logger('cron')->error('Unable to create ' . $filename . ' ' . $e->getMessage());
    }
  }

  /**
   * @method generate_vote_count_json_file()
   * @return void
   * Generate vote count JSON file
   */
  public static function generate_vote_count_json_file()
  {

    #FIXME: uses a lot of memory...make an output stream for json files??

    try{

      $output = [];

      $inventroyIndexCount = \Drupal\search_api\Entity\Index::load('pd_core_inventory')
        ->query()
        ->execute()
        ->getResultCount();

      // inventroyIndexItems, if successful, will return an associative array
      // key is generate from the search_api query class, value is a \Drupal\search_api\Item\ItemInterface object
      $inventroyIndexItems = \Drupal\search_api\Entity\Index::load('pd_core_inventory')
        ->query()
        ->range(0,$inventroyIndexCount)
        ->execute()
        ->getResultItems();

      // localVoteCounts, if successful, will return an associative array
      // key is the uuid, value is a standard object with properties:
      //  - type
      //  - uuid
      //  - vote_count
      $localVoteCounts = \Drupal::database()->select( 'external_voting', 'n' )
        ->fields( 'n', ['type', 'uuid', 'vote_count'] )
        ->execute()->fetchAllAssoc( 'uuid' );

      foreach( $inventroyIndexItems as $_index => $_inventroyIndexItem ){

        $id = $_inventroyIndexItem->getField('id')->getValues()[0];

        $referenceNumber = $_inventroyIndexItem->getField('ref_number');
        $organizationNameCode = $_inventroyIndexItem->getField('org_name_code');

        if(
          is_null( $referenceNumber ) ||
          is_null( $organizationNameCode )
        ){

          continue;

        }

        $referenceNumber = $referenceNumber->getValues();
        $organizationNameCode = $organizationNameCode->getValues();

        if(
          ! is_array( $referenceNumber ) ||
          ! is_array( $organizationNameCode )
        ){

          continue;

        }

        $referenceNumber = count( $referenceNumber ) === 0 ? "" : $referenceNumber[0];
        $organizationNameCode = count( $organizationNameCode ) === 0 ? "" : $organizationNameCode[0];

        // the inventory has a vote count in the drupal database
        if( array_key_exists( $id, $localVoteCounts ) ){

          if(
            ! isset( $localVoteCounts[$id]->vote_count ) ||
            ! is_numeric( $localVoteCounts[$id]->vote_count )
          ){

            $output["$organizationNameCode"]["$referenceNumber"] = 0;

          }else{

            $output["$organizationNameCode"]["$referenceNumber"] = intval($localVoteCounts[$id]->vote_count);

          }

        // there is no vote count in the drupal database, set to zero
        }else{

          $output["$organizationNameCode"]["$referenceNumber"] = 0;

        }

      }

      if(
        count( $output ) > 0 &&
        ( $json = json_encode($output) ) !== false
      ){

        $filePath = \Drupal\Core\Site\Settings::get('file_private_path') . '/inventory_vote_count.json';
        file_put_contents( $filePath, $json );

        \Drupal::logger('cron')->notice('Inventory Vote Count JSON Generated: saved to ' . $filePath );

      }

      $output = null;
      $inventroyIndexCount = null;
      $inventroyIndexItems = null;
      $localVoteCounts = null;

    }catch( \Exception $_exception ){

      \Drupal::logger('cron')->error( 'Unable to create vote count json file:' . $_exception->getMessage() );

    }

  }

  /**
   * @method get_item_interface_value(
   * @param \Drupal\search_api\Item\ItemInterface $_itemInterface
   * @param string $_field
   * @return mixed
   */
  private static function get_item_interface_field_value( &$_itemInterface, &$_field, &$_singleValue = True ){

    if( $_field == 'id' ){

      $return = $_itemInterface->getId();
      $return = str_replace( 'solr_document/', '', $return );
      return $return;

    }

    $return = $_itemInterface->getField($_field);

    if( is_null($return) ){

      return null;

    }

    $return = $return->getValues();

    if( ! is_array($return) || count($return) == 0 ){

      return null;

    }

    if( $_singleValue ){

      return $return[0];

    }

    return $return;

  }

  /**
   * @method get_ati_request_submission_counts_by_date
   * @return array
   */
  private static function get_ati_request_submission_counts_by_date(){

    //get webform submissions from `ati_records` form
    $atiSubmissionsQuery = \Drupal::database()->select( 'webform_submission', 'n' );
    $atiSubmissionsQuery->innerJoin( 'webform_submission_data', 'nt', 'nt.sid = n.sid' );
    $atiSubmissions = $atiSubmissionsQuery
      ->fields( 'n', ['sid', 'completed'] )
      ->fields( 'nt', ['value'] )
      ->condition( 'n.webform_id', 'ati_records' )
      ->condition( 'nt.name', 'entity_id' )
      ->execute()->fetchAllAssoc( 'sid' );

    \Drupal::logger('cron')->notice('Collected ' . count($atiSubmissions) . ' Informal ATI Request submissions.');

    $atiSubmissionCounts = [];
    foreach( $atiSubmissions as $_sid => $_atiSubmission ){

      $year = gmdate("Y", $_atiSubmission->completed);
      $month = gmdate("n", $_atiSubmission->completed);
      // value is `entity_id`
      $atiSubmissionCounts[$_atiSubmission->value][$year][$month] = isset( $atiSubmissionCounts[$_atiSubmission->value][$year][$month] ) ? intval($atiSubmissionCounts[$_atiSubmission->value][$year][$month]) + 1 : 1;

    }

    $atiSubmissionsQuery = null;
    $atiSubmissions = null;

    return $atiSubmissionCounts;

  }

  /**
   * @method get_ati_index_record_count
   * @return int|null
   */
  private static function get_ati_index_record_count(){

    //get solr index data for `core_ati`
    $atiIndexCount = \Drupal\search_api\Entity\Index::load('pd_core_ati')
    ->query()
    ->execute()
    ->getResultCount();

    \Drupal::logger('cron')->notice("Found $atiIndexCount ATI Summaries in the pd_core_ati solr index.");

    return $atiIndexCount;

  }

  /**
   * @method get_ati_index_records
   * @param int $_offset
   * @param int $_limit
   * @return array
   */
  private static function get_ati_index_records(&$_offset, &$_limit){

    $atiIndexItems = \Drupal\search_api\Entity\Index::load('pd_core_ati')
      ->query()
      ->range($_offset, $_limit)
      ->execute()
      ->getResultItems();

    $parsedAtiIndexItems = [];
    foreach( $atiIndexItems as $_uuid => $_atiIndexItem ){
      /**
       * @var \Drupal\search_api\Item\ItemInterface $_atiIndexItem
       */

      $id = self::get_item_interface_field_value( $_atiIndexItem, 'id' );
      $requestNumber = self::get_item_interface_field_value( $_atiIndexItem, 'request_number' );
      $summaryEn = self::get_item_interface_field_value( $_atiIndexItem, 'summary_en' );
      $summaryFr = self::get_item_interface_field_value( $_atiIndexItem, 'summary_fr' );
      $ownerOrgCode = self::get_item_interface_field_value( $_atiIndexItem, 'org_name_code' );
      $ownerOrgNameEn = self::get_item_interface_field_value( $_atiIndexItem, 'org_name_en' );
      $ownerOrgNameFr = self::get_item_interface_field_value( $_atiIndexItem, 'org_name_fr' );

      if(
        is_null($id) ||
        is_null($requestNumber) ||
        is_null($summaryEn) ||
        is_null($summaryFr) ||
        is_null($ownerOrgCode) ||
        is_null($ownerOrgNameEn) ||
        is_null($ownerOrgNameFr)
      ){

        continue;

      }

      $parsedAtiIndexItems[$id] = [
        'request_number'    => $requestNumber,
        'summary_en'        => $summaryEn,
        'summary_fr'        => $summaryFr,
        'owner_org_code'    => $ownerOrgCode,
        'owner_org_name_en' => $ownerOrgNameEn,
        'owner_org_name_fr' => $ownerOrgNameFr
      ];

    }

    $atiIndexItems = null;

    return $parsedAtiIndexItems;

  }

  /**
   * @method parse_ati_submission_counts_and_index_records_to_rows
   * @param array $_submissions
   * @param array $_records
   * @return array
   */
  private static function parse_ati_submission_counts_and_index_records_to_rows(&$_submissionCounts, &$_indexRecords){

    $rows = [];
    $missingIndexItemsCounter = 0;
    foreach( $_indexRecords as $_id => $_data ){

      if( ! array_key_exists( $_id, $_submissionCounts ) ){
        $missingIndexItemsCounter++;
        continue;
      }

      foreach( $_submissionCounts[$_id] as $_year => $_months ){

        foreach( $_months as $_month => $_count ){

          $rows[] = [
            'year'              => $_year,
            'month'             => $_month,
            'id'                => $_id,
            'request_number'    => $_data['request_number'],
            'summary_en'        => $_data['summary_en'],
            'summary_fr'        => $_data['summary_fr'],
            'owner_org_code'    => $_data['owner_org_code'],
            'owner_org_name_en' => $_data['owner_org_name_en'],
            'owner_org_name_fr' => $_data['owner_org_name_fr'],
            'request_count'     => $_count,
          ];

        }

      }

    }

    if( Drush::verbose() && $missingIndexItemsCounter > 0 ){
      \Drupal::logger('cron')->notice("$missingIndexItemsCounter requests not matched. ATI Summaries not found in the pd_core_ati solr index...");
    }

    return $rows;

  }

  /**
   * @method generate_ati_requests_csv_file()
   * @return void
   * Generate ATI informal requests CSV file
   */
  public static function generate_ati_requests_csv_file(){
    # Due to the 40k+ records, we have to append to a csv file
    # similar to an output stream. This prevents us from being
    # able to sort the csv rows before writing them.

    try{

      $filename = 'ati-informal-requests-analytics.csv';
      $headers = [
        'Year',
        'Month',
        'Unique Identifier',
        'Request Number',
        'Summary - EN',
        'Summary - FR',
        'owner_org',
        'Organization Name - EN',
        'Organization Name - FR',
        'Number of Informal Requests'
      ];

      $atiSubmissionCounts = self::get_ati_request_submission_counts_by_date();
      $atiIndexCount = self::get_ati_index_record_count();

      $offset = 0;
      $limit = 500;
      while($offset < $atiIndexCount){

        $atiIndexItems = self::get_ati_index_records($offset, $limit);

        if( count($atiIndexItems) === 0 ){
          \Drupal::logger('cron')->notice('Collected zero(0) ATI Summaries from the pd_core_ati solr index...finishing up...');
          break;
        }

        if( Drush::verbose() ){
          \Drupal::logger('cron')->notice('Collected ' . count($atiIndexItems) . ' ATI Summaries from the pd_core_ati solr index. (' . $offset . '-' . ( $offset + $limit ) . ' of ' . $atiIndexCount . ')');
        }

        $rows = self::parse_ati_submission_counts_and_index_records_to_rows($atiSubmissionCounts, $atiIndexItems);
        $append = $offset === 0 ? false : true;
        self::write_to_csv(
          $filename,
          $rows,
          $headers,
          true,
          $append
        );

        $offset += count($atiIndexItems);
        $atiIndexItems = null;
        $rows = null;

      }

      $atiSubmissionCounts = null;
      $atiIndexCount = null;

      $filePath = \Drupal::service('file_system')->realpath(\Drupal::config('system.file')->get('default_scheme') . "://") . '/' . $filename;
      $ckanFilePath = \Drupal\Core\Site\Settings::get('ckan_public_path') . '/' . $filename;

      $success = copy($filePath, $ckanFilePath);

      if( ! $success ){
      	\Drupal::logger('cron')->notice("Failed to copy $filePath to $ckanFilePath");
      }

      // log results
      \Drupal::logger('cron')->notice('ATI informal requests csv file completed');


    }catch( \Exception $_exception ){

      \Drupal::logger('cron')->error( 'Unable to create ATI informal requests CSV file:' . $_exception->getMessage() );

    }

  }

}
