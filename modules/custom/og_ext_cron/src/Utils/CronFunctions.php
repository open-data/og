<?php

namespace Drupal\og_ext_cron\Utils;

use Drupal\Core\Cache\Cache;
use \Drupal\node\Entity\Node;
use Symfony\Component\Yaml\Parser;
use Drupal\views\Views;
use \Drupal\webform\Entity\WebformSubmission;

/**
 * Class CronFunctions.
 */
class CronFunctions {

  /**
   * clear cache of views generated using Solr
   */
  public function clear_view_caches() {
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
        \Drupal::logger('cron')->notice('Cache cleared for ' . $view->getTitle());
      }
    }
  }

  /**
   * Export all published comments into a csv file
   */
  public function export_external_comments() {
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

      foreach($comments as $comment) {
        $node = $comment->getCommentedEntity();

        // Loop over and get fields for published comments
        if ($comment->getStatus() == 1 && $node->isPublished()) {
          $url_en = ($node->hasTranslation('en') ? 'https://open.canada.ca' . $node->getTranslation('en')->url() : '');
          $url_fr = ($node->hasTranslation('fr') ? 'https://ouvert.canada.ca' . $node->getTranslation('fr')->url() : '');
          $comments_data[] = [
            'comment_id' => $comment->id(),
            'page_en' => $url_en,
            'page_fr' => $url_fr,
            'subject' => $comment->getSubject(),
            'comment_body' => $comment->get('comment_body')->getValue()[0]['value'],
            'comment_posted_by' => $comment->getAuthorName(),
            'date_posted' => \Drupal::service('date.formatter')->format($comment->getCreatedTime(), 'html_date')
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
      'Date posted'
    ];

    // export as csv
    $this->write_to_csv('export_comments.csv', $comments_data, $header);

    // log results
    \Drupal::logger('cron')->notice('Comments export to csv file completed');
  }

  /**
   * Export all published comments into a csv file
   */
  public function export_suggested_datasets() {
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
            ? $this->implodeAllValues($node->get('field_dataset_subject')->getValue())
            : 'information_and_communications';
          $keywords_en = $node_en->get('field_dataset_keywords')->getValue()
            ? $this->implodeAllValues($node_en->get('field_dataset_keywords')->getValue())
            : 'dataset';
          $keywords_fr = $node_fr->get('field_dataset_keywords')->getValue()
            ? $this->implodeAllValues($node_fr->get('field_dataset_keywords')->getValue())
            : 'Jeu de données';
          $status = $node->get('field_sd_status')->getValue()
            ? $node->get('field_sd_status')->getValue()[0]['value']
            : 'department_contacted';

          $data = [
            'uuid' => $node->uuid(),
            'suggestion_id' => $node->id(),
            'date_created' => date('Y-m-d', $node->getCreatedTime()),
            'title_en' => $node_en->getTitle(),
            'title_fr' => $node_fr->getTitle(),
            'organization' => $node->get('field_organization')->getValue()[0]['value'],
            'description_en' => strip_tags($node_en->get('body')->getValue()[0]['value']),
            'description_fr' => strip_tags($node_fr->get('body')->getValue()[0]['value']),
            'dataset_suggestion_status' => $status,
            'dataset_suggestion_status_link' => $node->get('field_status_link')->getValue()[0]['value'],
            'dataset_released_date' => $node->get('field_date_published')->getValue()[0]['value'],
            'votes' => $node->get('field_vote_up_down')->getValue()[0]['value'],
            'subject' => $subject,
            'keywords_en' => $keywords_en,
            'keywords_fr' => $keywords_fr,
            'additional_comments_and_feedback_en' =>  $node_en->get('field_feedback')->getValue()[0]['value'],
            'additional_comments_and_feedback_fr' =>  $node_fr->get('field_feedback')->getValue()[0]['value'],
          ];

          // get webform submission for suggested datasets
          if ($wid = $node->get('field_webform_submission_id')->getValue()[0]['value']) {
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
      }
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
    $this->write_to_csv('suggested-dataset.csv', $export_data, $header, FALSE);

    // log results
    \Drupal::logger('cron')->notice('Suggested datasets exported');
  }

  /**
   * Export dataset ratings as CSV with cumulative ratings and vote count
   */
  public function export_cumulative_dataset_ratings() {
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
        throw new Exception('Failed to return results from database.');
      }

      // fetch dataset titles from ckan
      $datasets = [];
      $filename = \Drupal\Core\Site\Settings::get('ckan_public_path') . '/od-do-canada.jl.gz';
      $handle = gzopen($filename, 'r');
      if (!$handle) {
        throw new Exception('Failed to open Portal Catalogue dataset.');
      }

      while (!gzeof($handle)) {
        $line = gzgets($handle);
        $data = json_decode($line, TRUE);
        $datasets[$data['id']] = ['en' => $data['title_translated']['en'], 'fr' => $data['title_translated']['fr']];
      }
      gzclose($handle);

      if (!sizeof($datasets)) {
        throw new Exception('Failed to read content from Portal Catalogue dataset.');
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
      $this->write_to_csv('dataset-ratings.csv', $output_data, $header);

      // log results
      \Drupal::logger('cron')->notice('Dataset ratings exported');
    }

    catch (Exception $e) {
      \Drupal::logger('cron')->error('Unable to export dataset ratings ' . $e->getMessage());
    }
  }

  /**
   * Set dynamic allowed values for organization field
   * The options will be same as CKAN
   */
  public function fetch_orgs_from_ckan() {
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
  public function fetch_from_ckan($field_name, $field_type) {
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
      catch (Exception $e) {
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
  private function implodeAllValues($parentArray) {
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
  private function write_to_csv($filename, $data_to_write, $csv_header, $public = TRUE) {
    try {
      // create output csv
      $path = $public
        ? \Drupal::service('file_system')->realpath(file_default_scheme() . "://")
        : \Drupal\Core\Site\Settings::get('file_private_path');
      $output = fopen($path . '/' . $filename, 'w');
      if (!$output) {
        throw new Exception('Failed to create export file.');
      }

      // add BOM to fix UTF-8 in Excel
      fputs($output, $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF)));
      // add csv header columns
      fputcsv($output, $csv_header, ',', '"');

      // write to csv
      foreach($data_to_write as $row) {
        fputcsv($output, $row, ',', '"');
      }

      fclose($output);
    }

    catch (Exception $e) {
      \Drupal::logger('cron')->error('Unable to create ' . $filename . ' ' . $e->getMessage());
    }
  }

  /**
   * @method generate_vote_count_json_file()
   * @return void
   * Generate vote count JSON file
   */
  public function generate_vote_count_json_file()
  {

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
  private function get_item_interface_field_value( $_itemInterface, $_field, $_singleValue = True ){

    if( $_field == 'id' ){

      $return = $_itemInterface->getId();
      $return = str_replace( 'solr_document/', '', $return );
      return $return;

    }else{

      $return = $_itemInterface->getField($_field);

    }

    $return = $_itemInterface->getField($_field);

    if( is_null($return) ){

      return null;

    }

    $return = $return->getValues();

    if( ! is_array($return) ){

      return null;

    }

    if( $_singleValue ){

      return $return[0];

    }

    return $return;

  }

  /**
   * @method generate_ati_requests_csv_file()
   * @return void
   * Generate ATI informal requests CSV file
   */
  public function generate_ati_requests_csv_file(){

    try{

      //get webform submissions from `ati_records` form
      $localAtiRequestsQuery = \Drupal::database()->select( 'webform_submission', 'n' );
      $localAtiRequestsQuery->innerJoin( 'webform_submission_data', 'nt', 'nt.sid = n.sid' );
      $localAtiRequests = $localAtiRequestsQuery
        ->fields( 'n', ['sid', 'completed'] )
        ->fields( 'nt', ['value'] )
        ->condition( 'n.webform_id', 'ati_records' )
        ->condition( 'nt.name', 'entity_id' )
        ->execute()->fetchAllAssoc( 'sid' );

      $localAtiRequestCounts = [];
      foreach( $localAtiRequests as $_sid => $_localAtiRequest ){

        $year = gmdate("Y", $_localAtiRequest->completed);
        $month = gmdate("n", $_localAtiRequest->completed);
        // value is `entity_id`
        $localAtiRequestCounts[$_localAtiRequest->value][$year][$month] = isset( $localAtiRequestCounts[$_localAtiRequest->value][$year][$month] ) ? intval($localAtiRequestCounts[$_localAtiRequest->value][$year][$month]) + 1 : 1;

      }

      //get solr index data for `core_ati`
      $atiIndexCount = \Drupal\search_api\Entity\Index::load('pd_core_ati')
        ->query()
        ->execute()
        ->getResultCount();

      $atiIndexItems = \Drupal\search_api\Entity\Index::load('pd_core_ati')
        ->query()
        ->range(0, $atiIndexCount)
        ->execute()
        ->getResultItems();

      $parsedAtiIndexItems = [];
      foreach( $atiIndexItems as $_index => $_atiIndexItem ){

        $id = $this->get_item_interface_field_value($_atiIndexItem, 'id');
        $requestNumber = $this->get_item_interface_field_value($_atiIndexItem, 'request_number');
        $summaryEn = $this->get_item_interface_field_value($_atiIndexItem, 'summary_en');
        $summaryFr = $this->get_item_interface_field_value($_atiIndexItem, 'summary_fr');
        $ownerOrgCode = $this->get_item_interface_field_value($_atiIndexItem, 'org_name_code');
        $ownerOrgNameEn = $this->get_item_interface_field_value($_atiIndexItem, 'org_name_en');
        $ownerOrgNameFr = $this->get_item_interface_field_value($_atiIndexItem, 'org_name_fr');

        if(
          is_null($id) ||
          is_null($requestNumber) ||
          is_null($summaryEn) ||
          is_null($summaryFr) ||
          is_null($ownerOrgCode) ||
          is_null($ownerOrgNameEn) ||
          is_null($ownerOrgNameFr)
        ){

          #TODO: solve issues with the above fields coming back as null all the time...
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

      \Drupal::logger('cron')->notice(gettype($parsedAtiIndexItems['a078e60aa2038a5c3986486c8e2cf9d1']));

      $rows = [];
      $missingIndexItemsCounter = 0;
      foreach( $localAtiRequestCounts as $_id => $_years ){

        if( ! array_key_exists( $_id, $parsedAtiIndexItems ) ){
          $missingIndexItemsCounter++;
          continue;
        }

        foreach( $_years as $_year => $_months ){

          foreach( $_months as $_month => $_count ){

            $rows[] = [
              'year'              => $_year,
              'month'             => $_month,
              'id'                => $id,
              'request_number'    => $parsedAtiIndexItems[$requestNumber]['request_number'],
              'summary_en'        => $parsedAtiIndexItems[$requestNumber]['summary_en'],
              'summary_fr'        => $parsedAtiIndexItems[$requestNumber]['summary_fr'],
              'owner_org_code'    => $parsedAtiIndexItems[$requestNumber]['owner_org_code'],
              'owner_org_name_en' => $parsedAtiIndexItems[$requestNumber]['owner_org_name_en'],
              'owner_org_name_fr' => $parsedAtiIndexItems[$requestNumber]['owner_org_name_fr'],
              'request_count'     => $_count,
            ];

          }

        }

      }

      if( $missingIndexItemsCounter > 0 ){
        \Drupal::logger('cron')->notice("$missingIndexItemsCounter requests not matched. ATI Summaries not found in the core_ati index...");
      }
      \Drupal::logger('cron')->notice(count($localAtiRequestCounts));
      \Drupal::logger('cron')->notice(count($rows));

      $this->write_to_csv(
        'ati-informal-requests-analytics.csv',
        $rows,
        [
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
        ],
        true
      );

      // log results
      \Drupal::logger('cron')->notice('ATI informal requests csv file completed');


    }catch( \Exception $_exception ){

      \Drupal::logger('cron')->error( 'Unable to create ATI informal requests CSV file:' . $_exception->getMessage() );

    }

  }

}
