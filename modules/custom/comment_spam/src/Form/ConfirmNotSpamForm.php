<?php

namespace Drupal\comment_spam\Form;

use Drupal\comment\Entity\Comment;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class ConfirmSpamForm.
 *
 * @package Drupal\comment_spam\Form
 */
class ConfirmNotSpamForm extends ConfirmFormBase {
  protected $toReportId;
  protected $messenger;

  /**
   * Class constructor.
   */
  public function __construct(MessengerInterface $messenger) {
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'confirm_not_spam_comment';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $comment = '') {
    $this->toReportId = (int) $comment;

    $form = parent::buildForm($form, $form_state);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Do you want to set this comment as not spam but keep it unpublished?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('entity.comment.edit_form', ['comment' => $this->toReportId]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $comment = Comment::load($this->toReportId);
    if ($comment->hasField('field_spam') && $comment->get('field_spam')->value) {
      $comment->set('field_spam', FALSE);
    }

    $comment->setUnpublished();
    $comment->save();

    $this->messenger->addStatus($this->t('The comment "@title" has been marked as not spam and moved to unapproved comments.', ['@title' => $comment->getSubject()]));

    $url = new Url('comment.admin_approval');
    return $form_state->setRedirectUrl($url);
  }

}

