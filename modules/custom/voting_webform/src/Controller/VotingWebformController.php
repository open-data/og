<?php

namespace Drupal\voting_webform\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\node\NodeInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;
use Drupal\webform\WebformSubmissionForm;

/**
 * Class VotingWebformController.
 */
class VotingWebformController extends ControllerBase
{

    public function getAverageVote(Request $request, $uuid)
    {
        $imgsrc = '/profiles/og/modules/custom/voting_webform/images/';
        $renderHTML = '';
        $uuid = explode('?', $uuid);
        $uuid = $uuid[0];

        // only display the results if validated
        if ($this->validate($request, $uuid, 'Vote-Rating (external)')) {
            // get average vote result for uuid
            try {

                // get current vote count and average
                $connection = \Drupal::database();
                $query = $connection->select('external_rating', 'v');
                $query->condition('v.uuid', $uuid, '=');
                $query->fields('v', ['vote_count', 'vote_average']);
                $result = $query->execute();
                $vote_average = 0;

                foreach ($result as $record) {
                    $vote_average = round($record->vote_average);
                }

                switch ($vote_average) {
                case '1':
                    $renderHTML .= '<img class="image-actual" alt="This dataset is currently ranked one star" src="' . $imgsrc . 'onestar.png">';
                    break;
                case '2':
                    $renderHTML .= '<img class="image-actual" alt="This dataset is currently ranked two star" src="' . $imgsrc . 'twostar.png">';
                    break;
                case '3':
                    $renderHTML .= '<img class="image-actual" alt="This dataset is currently ranked three star" src="' . $imgsrc . 'threestar.png">';
                    break;
                case '4':
                    $renderHTML .= '<img class="image-actual" alt="This dataset is currently ranked four star" src="' . $imgsrc . 'fourstar.png">';
                    break;
                case '5':
                    $renderHTML .= '<img class="image-actual" alt="This dataset is currently ranked five star" src="' . $imgsrc . 'fivestar.png">';
                    break;
                default:
                    $renderHTML .= '<img class="image-actual" alt="This dataset is currently unrated" src="' . $imgsrc . 'zerostar.png">';
                    break;
                }
            }
            catch (\Exception $e) {
                \Drupal::logger('vote')
                ->warning(
                    'Vote-Rating (external): Exception thrown while trying to get average vote with uuid: '
                    . $uuid
                );
                $renderHTML .= '<img class="image-actual" alt="This dataset is currently unrated" src="'
                  . $imgsrc
                  . 'zerostar.png">';
            }
        }

        // return response with HTML
        return new Response($renderHTML);
    }

    /**
     * Validation function for requests from external systems
     */
    public function validate(Request $request, $id, $type)
    {
        // get url, id and domain of request object
        $host_domain = $request->getHttpHost();
        $referer_url = $request->headers->get('referer');
        $url_explode = explode("/", $referer_url ?? "");
        $referer_id = end($url_explode);
        $referer_id = explode('?', $referer_id ?? "");
        $referer_id = $referer_id[0];

        if ($referer_url) {
            $url_components = parse_url($referer_url);
            $referer_domain = $url_components['host'];
            if (array_key_exists('port', $url_components)) {
                $referer_domain .= ':' . $url_components['port'];
            }
        } else {
            $referer_domain = '';
        }

        if ((empty($referer_url))) {
            // condition 1 - no url for referrer
            \Drupal::logger('vote')
              ->warning($type . ': No referrer found for vote');
            return false;

        } elseif ($referer_domain != $host_domain) {
            // condition 2 - domain name of both request and referrer are same
            \Drupal::logger('vote')
            ->warning(
                $type
                . ': Host domain name and referrer domain name do not match'
            );
            return false;

        } elseif (empty($id)) {
            // condition 3 - uuid has a value
            \Drupal::logger('vote')
              ->warning($type. ': No uuid given for vote');
            return false;

        } elseif ($id != $referer_id || !in_array(strlen($id), [ 36, 32])) {
            // condition 4 - invalid uuid
            \Drupal::logger('vote')
              ->warning($type . ': Invalid UUID');
            return false;
        }

        return true;
    }
}
