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
class VotingWebformController extends ControllerBase {

  /**
   * @param Request $request
   * @param $webform_id
   * @param null $uuid
   * @return Response
   */
  public function getAverageVote(Request $request, $uuid) {
    $renderHTML = '';
    $uuid = explode('?' , $uuid);
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
        $imgsrc = '/profiles/og/modules/custom/voting_webform/images/';

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
          case '6':
            $renderHTML .= '<img class="image-actual" alt="This dataset is currently ranked five star" src="' . $imgsrc . 'fivestar.png">';
            break;
          default :
            $renderHTML .= '<img class="image-actual" alt="This dataset is currently unrated" src="' . $imgsrc . 'zerostar.png">';
            break;
        }
      }
      catch (\Exception $e) {
        \Drupal::logger('vote')->warning('Vote-Rating (external): Exception thrown while trying to get average vote with uuid: ' . $uuid);
        $renderHTML .= '<img class="image-actual" alt="This dataset is currently unrated" src="' . $imgsrc . 'zerostar.png">';
      }
    }

    // return response with HTML
    return new Response($renderHTML);
  }

  /**
   * Render the voting webform for external search system
   * @param Request $request
   * @param $ext_type
   * @param NodeInterface $node
   * @return Response
   */
  public function getVotingExposedForm(Request $request, $ext_type, NodeInterface $node) {
    $renderHTML = '';
    $response = new Response();

    if ($this->validate($request, $node->id(), $ext_type)) {
      if ($ext_type == 'suggest-dataset') {
        // retrieve search domain
        $langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();
        $search_domain = $request->getScheme() . '://' . \Drupal\Core\Site\Settings::get('search_domain')[$langcode];

        // render webform
        $vote_webform = [
          '#type' => 'webform',
          '#webform' => 'vote_up_down',
        ];

        $renderHTML = \Drupal::service('renderer')->render($vote_webform);
        $action = $request->getScheme() . '://' . $request->getHttpHost() . $request->getRequestUri() . '/submit';
        $renderHTML = str_replace('form_action_p_pvdeGsVG5zNF_XLGPTvYSKCf43t8qZYSwcfZl2uzM', $action, $renderHTML);
        $renderHTML = str_replace('glyphicon glyphicon-thumbs-up', '', $renderHTML);
        $renderHTML = str_replace('</button>', '<span class="glyphicon glyphicon-thumbs-up"></span></button>', $renderHTML);

        $response->headers->set('Content-Type', 'text/html');
        $response->headers->set('Access-Control-Allow-Origin', $search_domain);
      }
    }

    // return response
    $response->setContent($renderHTML);
    $response->setStatusCode(Response::HTTP_OK);
    return $response;
  }

  /**
   * Submit voting webform for external search system
   * @param Request $request
   * @param $ext_type
   * @param NodeInterface $node
   * @return mixed
   */
  public function submitVotingExposedForm(Request $request, $ext_type, NodeInterface $node) {
    if ($this->validate($request, $node->id(), $ext_type)) {
      if ($ext_type == 'suggest-dataset') {
        $langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();
        $reqtime = \Drupal::time()->getRequestTime();

        // set submission values
        $values = [
          'webform_id' => 'vote_up_down',
          'entity_type' => 'node',
          'entity_id' => $node->id(),
          'in_draft' => FALSE,
          'uid' => '0',
          'langcode' => $langcode,
          'token' => $request->cookies->get('csrftoken'),
          'uri' => '/' . $langcode . '/node/' . $node->id(),
          'remote_addr' => $request->getClientIp(),
          'data' => [],
          'created' => $reqtime,
          'completed' => $reqtime,
          'changed' => $reqtime,
          'current_page' => '',
          'locked' => '0',
          'sticky' => '0',
          'notes' => '',
        ];

        // create submission if webform is open
        $webform = Webform::load($values['webform_id']);
        $is_open = WebformSubmissionForm::isOpen($webform);

        if ($is_open === TRUE) {
          $webform_submission = WebformSubmission::create($values);
          WebformSubmissionForm::submitWebformSubmission($webform_submission);
        }
      }
    }

    $path = $request->getScheme() . '://' . $request->getHttpHost() . '/node/' . $node->id();
    $response = new TrustedRedirectResponse($path);
    return $response->send();
  }

  /**
   * Validation function for requests from external systems
   * @param Request $request
   * @param $uuid
   * @param $type
   * @return bool
   */
  public function validate(Request $request, $id, $type) {
    // get url, id and domain of request object
    $host_domain = $request->getHttpHost();
    $referer_url = $request->headers->get('referer');
    $url_explode = explode("/",$referer_url);
    $referer_id = end($url_explode);
    $referer_id = explode('?' , $referer_id);
    $referer_id = $referer_id[0];

    // map suggested dataset type
    if ($type == 'suggest-dataset'
      && isset($url_explode[count($url_explode)-3])
      && $url_explode[count($url_explode)-3] == 'sd') {
      $langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();
      $domain = \Drupal\Core\Site\Settings::get('search_domain');
      $search_domain = $domain[$langcode];
    } else
      $search_domain = '';

    if ($referer_url) {
      $url_components = parse_url($referer_url);
      $referer_domain = $url_components['host'];
      if (array_key_exists('port', $url_components)) {
        $referer_domain .= ':' . $url_components['port'];
      }
    } else
      $referer_domain = '';

    // condition 1 - no url for referrer
    if ((empty($referer_url))) {
      \Drupal::logger('vote')->warning($type . ': No referrer found for vote');
      return false;
    }

    // condition 2 - domain name of both request and referrer are same
    elseif (!in_array($referer_domain, [$host_domain, $search_domain])) {
      \Drupal::logger('vote')->warning($type. ': Host domain name and referrer domain name do not match');
      return false;
    }

    // condition 3 - uuid has a value
    elseif (empty ($id)){
      \Drupal::logger('vote')->warning($type. ': No uuid given for vote');
      return false;
    }

    // condition 4 - invalid uuid
    elseif ($type != 'suggest-dataset' && ($id != $referer_id || !in_array(strlen($id),[ 36, 32]))) {
      \Drupal::logger('vote')->warning($type . ': Invalid UUID');
      return false;
    }

    return true;
  }
}
