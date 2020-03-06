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
    \Drupal::logger('export')->notice('Comments export to csv file completed');
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
        if ($node->hasTranslation('fr')) {
          $node_fr = $node->getTranslation('fr');

          $data = [
            'suggestion_id' => $node->id(),
            'date_created' => date('Y-m-d', $node->getCreatedTime()),
            'title_en' => $node->getTitle(),
            'title_fr' => $node_fr->getTitle(),
            'organization' => $node->get('field_organization')->getValue()[0]['value'],
            'description_en' => strip_tags($node->get('body')->getValue()[0]['value']),
            'description_fr' => strip_tags($node_fr->get('body')->getValue()[0]['value']),
            'dataset_suggestion_status' => $node->get('field_sd_status')->getValue()[0]['value'],
            'dataset_suggestion_status_link' => $node->get('field_status_link')->getValue()[0]['value'],
            'Dataset released date' => $node->get('field_date_published')->getValue()[0]['value'],
            'votes' => $node->get('field_vote_up_down')->getValue()[0]['value'],
            'subject' => $this->implodeAllValues($node->get('field_dataset_subject')->getValue()),
            'keywords_en' => $this->implodeAllValues($node->get('field_dataset_keywords')->getValue()),
            'keywords_fr' => $this->implodeAllValues($node_fr->get('field_dataset_keywords')->getValue()),
            'additional_comments_and_feedback_en' =>  $node->get('field_feedback')->getValue()[0]['value'],
            'additional_comments_and_feedback_fr' =>  $node_fr->get('field_feedback')->getValue()[0]['value'],
          ];

          // get webform submission for suggested datasets
          if ($wid = $node->get('field_webform_submission_id')->getValue()[0]['value']) {
            $webform_submission = WebformSubmission::load($wid);
            $reason = $webform_submission->getElementData('reason');

            $webform_data = [
              'webform_submission_id' => $wid,
              'reason' => $reason,
            ];
            $data = array_merge($data, $webform_data);
          }
          $export_data[] = $data;
        }
      }
    }

    $header = [
      'suggestion_id',
      'date_created',
      'title_en',
      'title_fr',
      'organization',
      'description_en',
      'description_fr',
      'dataset_suggestion_status',
      'dataset_suggestion_status_link',
      'Dataset released date',
      'votes',
      'subject',
      'keywords_en',
      'keywords_fr',
      'additional_comments_and_feedback_en',
      'additional_comments_and_feedback_fr',
      'webform_submission_id',
      'reason',
    ];

    // export as csv
    $this->write_to_csv('suggested-dataset.csv', $export_data, $header);

    // log results
    \Drupal::logger('export')->notice('Suggested datasets exported');
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
      \Drupal::logger('export')->notice('Dataset ratings exported');
    }

    catch (Exception $e) {
      \Drupal::logger('export')->error('Unable to export dataset ratings ' . $e->getMessage());
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

      if (!empty($options)) {
        // Write to choices folder
        $module_handler = \Drupal::service('module_handler');
        $module_path = $module_handler->getModule('og_ext_cron')->getPath();
        $filename = $module_path . '/choices/organizations.json';
        if (file_put_contents($filename, json_encode($options, JSON_PRETTY_PRINT)))
          \Drupal::logger('fetch from api')->notice('Organizations list updated from CKAN');
        else
          \Drupal::logger('fetch from api')->error('Unable to write organizations list from file ' . $filename);
      }
    }
    else
      \Drupal::logger('fetch from api')->error('Unable to read file ' . $filename);
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
              \Drupal::logger('fetch from api')->notice($field_name . ' list updated from CKAN');
            else
              \Drupal::logger('fetch from api')->error('Unable to write ' . $field_name . '.json');
          }
          else
            \Drupal::logger('fetch from api')->error('Unable to fetch ' . $field_name . ' from CKAN');
        }
      }
      catch (Exception $e) {
        \Drupal::logger('fetch from api')->error('Unable to fetch from api for ' . $url
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
  private function write_to_csv($filename, $data_to_write, $csv_header) {
    try {
      // create output csv
      $public_path = \Drupal::service('file_system')->realpath(file_default_scheme() . "://");
      $output = fopen($public_path . '/' . $filename, 'w');
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
      \Drupal::logger('export')->error('Unable to create ' . $filename . ' ' . $e->getMessage());
    }
  }

}
