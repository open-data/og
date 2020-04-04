<?php

namespace Drupal\external_comment\Controller;

use Drupal\comment\Controller\CommentController;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityInterface;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;


/**
 * Class ExternalCommentController.
 * A wrapper class of the Drupal Core CommentController to handle comments from entities outside Drupal
 */
class ExternalCommentController extends CommentController {

  private $types = [
    "dataset" => "Dataset",
    "inventory" => "Open Data Inventory",
    "suggest-dataset" => "Suggest a dataset",
    ];

  private $types_fr = [
    "dataset" => "Jeu de données",
    "inventory" => "Répertoire de données ouvertes",
    "suggest-dataset" => "Proposer un jeu de données",
  ];

  /**
   * Render comment form for entities external to Drupal
   * @param Request $request
   * @param $ext_type
   * @param $uuid
   * @return Response
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function renderExternalComment(Request $request, $ext_type, $uuid) {
    $renderHTML = '';
    // remove any characters after uuid in the request string
    $uuid = explode('?' , $uuid);
    $uuid = $uuid[0];
    $langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();

    // only display the form if validated
    if ($this->validate($request, $ext_type, $uuid)) {
      if ($ext_type == 'suggest-dataset') {
        // load comments for the node
        $node = \Drupal::service('entity.repository')->loadEntityByUuid('node', $uuid);
        $renderHTML = $this->getNodeComments($node, $request, $ext_type);
        // change relative urls to absolute urls
        $renderHTML = str_replace('href="', 'href="' . $request->getScheme() . '://' . $request->getHttpHost(), $renderHTML);
        $renderHTML = str_replace('about="', 'about="' . $request->getScheme() . '://' . $request->getHttpHost(), $renderHTML);
        $renderHTML = str_replace('action="', 'action="' . $request->getScheme() . '://' . $request->getHttpHost(), $renderHTML);
        // remove class icon from button
        $renderHTML = str_replace(
          '<span class="icon glyphicon glyphicon-ok" aria-hidden="true"></span>',
          '<span class="glyphicon glyphicon-ok" aria-hidden="true"></span>',
          $renderHTML);
      }
      else {
        // check if comments exist for this uuid
        $query = \Drupal::entityQuery('node')
          ->condition('type', 'external')
          ->condition('status', 1)
          ->condition('field_type', $ext_type)
          ->condition('field_uuid', $uuid);
        $results = $query->execute();

        // if comments do not exist for this uuid then load the default node
        if (!$results) {
          $query = \Drupal::entityQuery('node')
            ->condition('type', 'external')
            ->condition('status', 1)
            ->condition('field_uuid', 'default');
          $results = $query->execute();
        }

        if ($results) {
          $node_id = $results[array_keys($results)[0]];
          $node = \Drupal::entityTypeManager()->getStorage('node')->load($node_id);
          $renderHTML = $this->getNodeComments($node, $request, $ext_type);
        }
      }
   }

    $response = new Response();
    $response->setContent($renderHTML);
    $response->setStatusCode(Response::HTTP_OK);
    $response->headers->set('Content-Type', 'text/html');
    if ($ext_type == 'suggest-dataset') {
      $search_domain = $request->getScheme() . '://' . \Drupal\Core\Site\Settings::get('search_domain')[$langcode];
      $response->headers->set('Access-Control-Allow-Origin', $search_domain);
    }

    // return response
    return $response;
  }

  /**
   * Wrapper function of the getReplyForm to create new nodes when commenting on default comment form
   * @param Request $request
   * @param EntityInterface $entity
   * @param string $field_name
   * @param null $pid
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function getReplyForm(Request $request, EntityInterface $entity, $field_name, $pid = NULL) {

    // if commented on default node then create a new node and attach comment
    if ($request->getMethod() === 'POST' && $entity->getEntityTypeId() === 'node' && $entity->bundle() === 'external'
      && $entity->get('title')->value === 'default' ) {

      // get url, uuid, title and type of request object
      $url = $request->headers->get('referer');
      $url_explode = explode("/",$url);
      $uuid = end($url_explode);
      $uuid = explode('?' , $uuid);
      $uuid = $uuid[0];
      $ext_type = prev($url_explode);

      if ($this->validate($request,$ext_type, $uuid)) {
        // create node with the information gathered above
        $lang = strpos($url, '/en/') ? 'en' : 'fr';
        $url_en = $lang === 'en' ? $url : str_replace('/fr/', '/en/', $url);
        $url_fr = $lang === 'en' ? str_replace('/en/', '/fr/', $url) : $url;

        $node = Node::create(['type' => 'external']);
        $node->set('title', $this->types[$ext_type]);
        $node->set('field_url', $url_en);
        $node->set('field_type', $ext_type);
        $node->set('field_uuid', $uuid);
        $node->enforceIsNew();
        $node->setPublished();
        $node->set('moderation_state', 'published');
        $node->save();

        // create a translation for the node
        $node_fr = $node->addTranslation('fr');
        $node_fr->set('title', $this->types_fr[$ext_type]);
        $node_fr->set('field_url', $url_fr);
        $node_fr->set('field_type', $ext_type);
        $node_fr->set('field_uuid', $uuid);
        $node_fr->save();

        // call function for the new entity
        \Drupal::logger('external comment')->notice('New node of external content type ' . $ext_type . ' created with uuid ' . $uuid);
        return parent::getReplyForm($request, $node, $field_name, $pid);
      }
      else {
        \Drupal::logger('external comment')->warning('External comment posted for ' . $ext_type . ' with no uuid');
        return [];
      }
    }
    return parent::getReplyForm($request, $entity, $field_name, $pid);
  }

  /**
   * Get render HTML for the comments attached to an entity
   * @param $entity
   * @param $req
   * @param $type
   * @return string
   */
  private function getNodeComments($entity, $req, $type) {
    $renderHTML = '';

    // If node exists then load comments
    if ($entity) {
      $module_handler = \Drupal::service('module_handler');
      $module_path = $module_handler->getModule('external_comment')->getPath();
      $css = '<link rel="stylesheet" type="text/css" href="/' . $module_path . '/css/style.css" />';

      // Load existing comments
      $commentsHTML = comment_node_update_index($entity);
      $renderHTML .= ($commentsHTML) ? $css . '<h2>' . t('Comments') . '</h2>' . $commentsHTML : '';

      // Load comments form
      $commentForm = $this->getReplyForm($req, $entity, 'comment')['comment_form'];

      // Replace action for external comments
      if ($type != 'suggest-dataset' && $entity->get('title')->value === 'default') {
        $commentForm['#action'] = str_replace('/comment/', '/external_comment/', $commentForm['#action']);
      }

      // Get HTML from render array
      $commentFormHTML = \Drupal::service('renderer')->render($commentForm);

      // Concatenate HTML to generate final HTML
      $renderHTML .= '<h2>' . t('Add new comment') . '</h2>' . $commentFormHTML . '<br/>';
    }

    return $renderHTML;
  }

  /**
   * Validate function to check for certain conditions before rendering and posting comments
   * @param Request $request
   * @param $ext_type
   * @param $uuid
   * @return bool
   */
  private function validate(Request $request, $ext_type, $uuid) {
    // get url, type, uuid and domain of request object
    $host_domain = $request->getHttpHost();
    $referer_url = $request->headers->get('referer');
    $url_explode = explode("/",$referer_url);
    $referer_uuid = end($url_explode);
    $referer_uuid = explode('?' , $referer_uuid);
    $referer_uuid = $referer_uuid[0];
    $referer_type = prev($url_explode);

    // map suggested dataset type
    if ($ext_type == 'suggest-dataset' && $url_explode[count($url_explode)-3] == 'sd') {
      $langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();
      $domain = \Drupal\Core\Site\Settings::get('search_domain');
      $search_domain = $domain[$langcode];
      $referer_type = 'suggest-dataset';
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
      \Drupal::logger('external comment')->warning('No referrer found for external comment');
      return false;
    }

    // condition 2 - domain name of both request and referrer are different
    elseif (!in_array($referer_domain, [$host_domain, $search_domain])) {
      \Drupal::logger('external comment')->warning('Host domain name and referrer domain name do not match');
      return false;
    }

    // condition 3 - external type has no value
    elseif (empty ($ext_type)){
      \Drupal::logger('external comment')->warning('No type given for external comment');
      return false;
    }

    // condition 4 - invalid type
    elseif (($ext_type != $referer_type) || !(array_key_exists($ext_type, $this->types))) {
      \Drupal::logger('external comment')->warning('Invalid external application type');
      return false;
    }

    // condition 5 - uuid has no value
    elseif (empty ($uuid)){
      \Drupal::logger('external comment')->warning('No uuid given for external comment');
      return false;
    }

    // condition 6 - invalid uuid
    elseif (($uuid != $referer_uuid) || (strlen($uuid) != 36 && strlen($uuid) != 32)) {
      \Drupal::logger('external comment')->warning('Invalid UUID ' . $uuid);
      return false;
    }

    return true;
  }

  /**
   * Render comments for entities external to Drupal as JSON
   */
  public function renderExternalCommentJSON(Request $request, $ext_type, $uuid) {
    $comments_json = array(
      'title' => 'Comments for ' . $ext_type,
      'uuid' => $uuid,
      'comments' => array()
    );
    // remove any characters after uuid in the request string
    $uuid = explode('?' , $uuid);
    $uuid = $uuid[0];

    // fetch comments for uuid
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'external')
      ->condition('status', 1)
      ->condition('field_type', $ext_type)
      ->condition('field_uuid', $uuid);
    $results = $query->execute();

    if ($results) {
      $node_id = $results[array_keys($results)[0]];
      $node = \Drupal::entityTypeManager()->getStorage('node')->load($node_id);

      // if node exist then load the node with comments
      if ($node) {
        $comment_ids = \Drupal::entityTypeManager()
          ->getStorage('comment')
          ->getQuery('AND')
          ->condition('entity_id', $node->id())
          ->condition('entity_type', 'node')
          ->sort('cid')
          ->execute();

        // if comments exist
        if ($comment_ids) {
          $comments = \Drupal::entityTypeManager()
            ->getStorage('comment')
            ->loadMultiple($comment_ids);
          foreach($comments as $comment) {
            // Loop over and get fields for published comments
            if ($comment->getStatus() == 1) {
              $comments_json['comments'][] = [
                'comment_id' => $comment->id(),
                'parent_id' => $comment->getParentComment() ? $comment->getParentComment()->id() : null,
                'subject' => $comment->getSubject(),
                'comment_body' => $comment->get('comment_body')->getValue()[0]['value'],
                'comment_posted_by' => $comment->getAuthorName(),
                'date_posted' => $comment->getCreatedTime(),
                'thread' => $comment->getThread(),
              ];
            }
          }
        }
      }
    }

    // return response as JSON
    return new JsonResponse($comments_json);
  }
}
