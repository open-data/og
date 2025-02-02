<?php

namespace Drupal\og_ext_cron\Utils;

use \Drupal\Core\Cache\Cache;
use \Drupal\Core\Site\Settings;
use \Drupal\node\Entity\Node;
use \Drupal\views\Views;
use \Drupal\webform\Entity\WebformSubmission;
use \Drupal\search_api\Entity\Index;

/**
 * Class CronFunctions.
 */
class CronFunctions
{
    /**
     * @method clearViewCaches
     * @return void
     *
     * clear cache of views generated using Solr
     */
    public function clearViewCaches()
    {
        $pd_views = [
        'pd_core_ati',
        'pd_core_ati_details',
        ];

        foreach ($pd_views as $view_name) {
            $view = Views::getView($view_name);
            if ($view) {
                $tags = $view->getCacheTags();
                Cache::invalidateTags($tags);
                \Drupal::logger('cron')
                    ->notice(
                        'Cache cleared for '
                        . ( \strlen($view->getTitle()) > 0
                        ? $view->getTitle()
                        : $view_name
                        )
                    );
            }
        }
    }

    private function _getNodeFieldValue(&$_node, $_field, $_stripTags = false, $_default = null)
    {

        $fieldValue = $_node->get($_field)->getValue();

        if (! is_array($fieldValue)
            || count($fieldValue) === 0
            || is_null($fieldValue[0])
            || ! array_key_exists('value', $fieldValue[0])
        ) {
            return $_default;
        }

        if (! $_stripTags ) {
            return $fieldValue[0]['value'];
        }

        return strip_tags($fieldValue[0]['value']);

    }

    /**
     * @method exportCumulativeDatasetRatings
     * @return void
     * 
     * Export dataset ratings as CSV with cumulative ratings and vote count
     */
    public function exportCumulativeDatasetRatings()
    {
        try {
            // fetch ratings from database
            $database = \Drupal::database();
            $result = $database->query(
                "SELECT uuid, vote_average, vote_count,
                          CONCAT(
                          'https://open.canada.ca/data/en/dataset/',
                          uuid) as url_en,
                          CONCAT(
                          'https://ouvert.canada.ca/data/fr/dataset/',
                          uuid) as url_fr
                        FROM {external_rating}
                        WHERE type = :type
                        ORDER BY vote_average DESC, vote_count DESC",
                [':type' => 'dataset',]
            );

            if (!$result) {
                  throw new \Exception('Failed to return results from database.');
            }

            // fetch dataset titles from ckan
            $datasets = [];
            $filename = \Drupal\Core\Site\Settings::get('ckan_public_path')
              . '/od-do-canada.jl.gz';
            $handle = gzopen($filename, 'r');
            if (!$handle) {
                throw new \Exception('Failed to open Portal Catalogue dataset.');
            }

            while (!gzeof($handle)) {
                $line = gzgets($handle);
                $data = json_decode($line, true);
                if (is_array($data)
                    && array_key_exists('title_translated', $data)
                ) {
                    $englishTitle = array_key_exists('en', $data['title_translated'])
                      ? $data['title_translated']['en']
                      : null;
                    $frenchTitle = array_key_exists('fr', $data['title_translated'])
                      ? $data['title_translated']['fr']
                      : null;
                    $datasets[$data['id']] = [
                      'en' => $englishTitle,
                      'fr' => $frenchTitle
                    ];
                }
            }
            gzclose($handle);

            if (!sizeof($datasets)) {
                throw new \Exception(
                    'Failed to read content from Portal Catalogue dataset.'
                );
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

            $header = [
            'title_en / titre_en',
            'title_fr / titre_fr',
            'uuid',
            'avg_user_rating / coter_moyen',
            'rating_count / nombre_coter',
            'url_en',
            'url_fr',
            ];

            // export as csv
            $this->_writeToCsv('dataset-ratings.csv', $output_data, $header);

            // log results
            \Drupal::logger('cron')->notice('Dataset ratings exported');

            $result = null;
            $datasets = null;
            $output_data = null;
        }

        catch (\Exception $e) {
            \Drupal::logger('cron')
                ->error(
                    'Unable to export dataset ratings '
                    . $e->getMessage()
                );
        }
    }

    /**
     * @method fetchFromCkan
     * @param  string $field_name
     * @param  string $field_type
     * @return array
     * 
     * Set dynamic allowed values for given field
     * The options will be same as CKAN
     */
    public function fetchFromCkan($field_name, $field_type)
    {
        $options = [];

        $url = ($field_name == 'organizations')
        ? 'https://open.canada.ca/data/en/api/action/datastore_search?resource_id=04cbec5c-5a3d-4d34-927d-e41c9e6e3736&limit=500'
        : 'https://open.canada.ca/data/api/action/scheming_dataset_schema_show?type=dataset';

        $url = filter_var($url, FILTER_SANITIZE_URL);
        if (filter_var($url, FILTER_VALIDATE_URL) !== false) {
            $client = \Drupal::httpClient();
            try {
                $request = $client->get($url);
                if ($request->getStatusCode() == '200') {
                    $response = $request->getBody()->getContents();
                    $data = json_decode($response, true);

                    if ($field_name == 'organizations') {
                        foreach ($data['result'][$field_type] as $rsc) {
                            $options[$rsc['open_canada_id']] = [
                            'en' => $rsc['title_en'],
                            'fr' => $rsc['title_fr']
                            ];
                        }

                    } else {
                        foreach ($data['result'][$field_type] as $rsc) {
                            if ($rsc['field_name'] == $field_name) {
                                foreach ($rsc['choices'] as $choice) {
                                    if (array_key_exists('label', $choice)) {
                                        $label_en = is_array($choice['label'])
                                          ? $choice['label']['en']
                                          : $choice['label'];
                                        $label_fr = is_array($choice['label'])
                                          ? $choice['label']['fr']
                                          : $choice['label'];
                                        $options[$choice['value']] = [
                                          'en' => $label_en,
                                          'fr' => $label_fr
                                        ];
                                    } else {
                                        $options[$choice['value']] = $choice['value'];
                                    }
                                }
                                break;
                            }
                        }
                    }

                    if (!empty($options)) {
                        // Write to choices folder
                        $module_handler = \Drupal::service('module_handler');
                        $module_path = $module_handler
                            ->getModule('og_ext_cron')
                            ->getPath();
                        if (file_put_contents(
                            $module_path
                            . '/choices/'
                            . $field_name
                            . '.json',
                            json_encode(
                                $options,
                                JSON_PRETTY_PRINT
                            )
                        )
                        ) {
                            \Drupal::logger('cron')
                              ->notice($field_name . ' list updated from CKAN');
                        } else {
                            \Drupal::logger('cron')
                              ->error('Unable to write ' . $field_name . '.json');
                        }
                    } else {
                        \Drupal::logger('cron')
                          ->error('Unable to fetch ' . $field_name . ' from CKAN');
                    }
                }
            }
            catch (\Exception $e) {
                \Drupal::logger('cron')->error(
                    'Unable to fetch from api for '
                    . $url
                    . ' Exception: '
                    . $e->getMessage()
                );
            }
        }

        return $options;
    }

    /**
     * Helper function to combine all values in a nested array in a string
     *
     * @return string
     */
    private function _implodeAllValues($parentArray)
    {
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
     *
     * @return void
     */
    private function _writeToCsv($filename, $data_to_write, $csv_header, $public = true, $_append = false)
    {
        try {
            // create output csv
            $path = $public
            ? \Drupal::service('file_system')
                ->realpath(
                    \Drupal::config('system.file')
                    ->get('default_scheme')
                    . "://"
                )
            : \Drupal\Core\Site\Settings::get('file_private_path');
            $fileMode = $_append ? 'a' : 'w';
            $output = fopen($path . '/' . $filename, $fileMode);
            if (!$output) {
                throw new \Exception('Failed to create export file.');
            }

            if (! $_append ) {
                // add BOM to fix UTF-8 in Excel
                fputs($output, $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF)));
                // add csv header columns
                fputcsv($output, $csv_header, ',', '"');
            }

                \Drupal::logger('cron')
                ->notice(
                    'Writing '
                    . count($data_to_write)
                    . ' rows with mode '
                    . $fileMode
                );

            // write to csv
            foreach ($data_to_write as $row) {
                fputcsv($output, $row, ',', '"');
            }

            fclose($output);
        }

        catch (\Exception $e) {
            \Drupal::logger('cron')
                ->error(
                    'Unable to create '
                    . $filename . ' '
                    . $e->getMessage()
                );
        }
    }

    /**
     * @return mixed
     */
    private function _getItemInterfaceFieldValue( &$_itemInterface, $_field, $_singleValue = true )
    {

        if ($_field == 'id' ) {

            $return = $_itemInterface->getId();
            $return = str_replace('solr_document/', '', $return);
            return $return;

        }

        $return = $_itemInterface->getField($_field);

        if (is_null($return) ) {

            return null;

        }

        $return = $return->getValues();

        if (! is_array($return) || count($return) == 0 ) {

            return null;

        }

        if ($_singleValue ) {

            return $return[0];

        }

        return $return;

    }

    /**
     * @return array
     */
    private function _getAtiRequestSubmissionCountsByDate()
    {

        //get webform submissions from `ati_records` form
        $atiSubmissionsQuery = \Drupal::database()
          ->select('webform_submission', 'n');
        $atiSubmissionsQuery
          ->innerJoin('webform_submission_data', 'nt', 'nt.sid = n.sid');
        $atiSubmissions = $atiSubmissionsQuery
            ->fields('n', ['sid', 'completed'])
            ->fields('nt', ['value'])
            ->condition('n.webform_id', 'ati_records')
            ->condition('nt.name', 'entity_id')
            ->execute()->fetchAllAssoc('sid');

        \Drupal::logger('cron')
        ->notice(
            'Collected '
            . count($atiSubmissions)
            . ' Informal ATI Request submissions.'
        );

        $atiSubmissionCounts = [];
        foreach ( $atiSubmissions as $_sid => $_atiSubmission ) {

            $year = gmdate("Y", $_atiSubmission->completed);
            $month = gmdate("n", $_atiSubmission->completed);
            // value is `entity_id`
            $atiSubmissionCounts[$_atiSubmission->value][$year][$month] = isset($atiSubmissionCounts[$_atiSubmission->value][$year][$month])
              ? intval($atiSubmissionCounts[$_atiSubmission->value][$year][$month]) + 1
              : 1;

        }

        $atiSubmissionsQuery = null;
        $atiSubmissions = null;

        return $atiSubmissionCounts;

    }

    /**
     * @return int|null
     */
    private function _getAtiIndexRecordCount()
    {

        //get solr index data for `core_ati`
        $atiIndexCount = \Drupal\search_api\Entity\Index::load('pd_core_ati')
            ->query()
            ->execute()
            ->getResultCount();

        \Drupal::logger('cron')
        ->notice(
            "
          Found $atiIndexCount ATI Summaries in the pd_core_ati solr index.
          "
        );

        return $atiIndexCount;

    }

    /**
     * @return array
     */
    private function _getAtiIndexRecords(&$_offset, &$_limit)
    {

        $atiIndexItems = \Drupal\search_api\Entity\Index::load('pd_core_ati')
            ->query()
            ->range($_offset, $_limit)
            ->execute()
            ->getResultItems();

        $parsedAtiIndexItems = [];
        foreach ( $atiIndexItems as $_uuid => $_atiIndexItem ) {
            /**
             * @var \Drupal\search_api\Item\ItemInterface $_atiIndexItem
             */

            $id = $this->_getItemInterfaceFieldValue($_atiIndexItem, 'id');
            $requestNumber = $this->_getItemInterfaceFieldValue(
                $_atiIndexItem,
                'request_number'
            );
            $summaryEn = $this->_getItemInterfaceFieldValue(
                $_atiIndexItem,
                'summary_en'
            );
            $summaryFr = $this->_getItemInterfaceFieldValue(
                $_atiIndexItem,
                'summary_fr'
            );
            $ownerOrgCode = $this->_getItemInterfaceFieldValue(
                $_atiIndexItem,
                'org_name_code'
            );
            $ownerOrgNameEn = $this->_getItemInterfaceFieldValue(
                $_atiIndexItem,
                'org_name_en'
            );
            $ownerOrgNameFr = $this->_getItemInterfaceFieldValue(
                $_atiIndexItem,
                'org_name_fr'
            );

            if (is_null($id)
                || is_null($requestNumber) 
                || is_null($summaryEn) 
                || is_null($summaryFr) 
                || is_null($ownerOrgCode) 
                || is_null($ownerOrgNameEn) 
                || is_null($ownerOrgNameFr)
            ) {

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
     * @return array
     */
    private function _parseAtiSubmissionCountsAndIndexRecordsToRows(&$_submissionCounts, &$_indexRecords)
    {

        $rows = [];
        $missingIndexItemsCounter = 0;
        foreach ( $_indexRecords as $_id => $_data ) {

            if (! array_key_exists($_id, $_submissionCounts) ) {
                $missingIndexItemsCounter++;
                continue;
            }

            foreach ( $_submissionCounts[$_id] as $_year => $_months ) {

                foreach ( $_months as $_month => $_count ) {

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

        if ($missingIndexItemsCounter > 0 ) {
            \Drupal::logger('cron')
            ->notice(
                "$missingIndexItemsCounter requests not matched. 
                ATI Summaries not found in the pd_core_ati solr index..."
            );
        }

        return $rows;

    }

    /**
     * Generate ATI informal requests CSV file
     *
     * @return void
     */
    public function generateAtiRequestsCsvFile()
    {
        // Due to the 40k+ records, we have to append to a csv file
        // similar to an output stream. This prevents us from being
        // able to sort the csv rows before writing them.

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

            $atiSubmissionCounts = $this->_getAtiRequestSubmissionCountsByDate();
            $atiIndexCount = $this->_getAtiIndexRecordCount();

            $offset = 0;
            $limit = 500;
            while ($offset < $atiIndexCount) {

                $atiIndexItems = $this->_getAtiIndexRecords($offset, $limit);

                if (count($atiIndexItems) === 0 ) {
                    \Drupal::logger('cron')
                    ->notice(
                        'Collected zero(0) ATI Summaries from the 
                        pd_core_ati solr index...finishing up...'
                    );
                    break;
                }

                    \Drupal::logger('cron')
                    ->notice(
                        'Collected '
                        . count($atiIndexItems)
                        . ' ATI Summaries from the pd_core_ati solr index. ('
                        . $offset . '-'
                        . ( $offset + $limit )
                        . ' of '
                        . $atiIndexCount . ')'
                    );

                $rows = $this->_parseAtiSubmissionCountsAndIndexRecordsToRows(
                    $atiSubmissionCounts,
                    $atiIndexItems
                );
                $append = !($offset === 0);
                $this->_writeToCsv(
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

            $filePath = \Drupal::service('file_system')
                ->realpath(
                    \Drupal::config('system.file')
                    ->get('default_scheme')
                    . "://"
                )
              . '/'
              . $filename;
            $ckanFilePath = \Drupal\Core\Site\Settings::get('ckan_public_path')
              . '/'
              . $filename;

            $success = copy($filePath, $ckanFilePath);

            if (!$success) {
                \Drupal::logger('cron')
                  ->notice("Failed to copy $filePath to $ckanFilePath");
            }

            // log results
            \Drupal::logger('cron')
              ->notice('ATI informal requests csv file completed');


        } catch( \Exception $_exception ) {

            \Drupal::logger('cron')
                ->error(
                    'Unable to create ATI informal requests CSV file:'
                    . $_exception->getMessage()
                );

        }

    }

}
