<?php

namespace Drupal\og_ext_webform\Plugin\WebformHandler;

use Drupal\node\Entity\Node;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\webformSubmissionInterface;

/**
 * Form submission handler.
 *
 * @WebformHandler(
 *   id = "suggested_dataset_form_handler",
 *   label = @Translation("Suggested Dataset Form Handler"),
 *   category = @Translation("Form Handler"),
 *   description = @Translation("Create a suggested dataset node from webform submission values"),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class SuggestedDatasetFormHandler extends WebformHandlerBase {

  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    // create new suggested dataset node for the submission
    $node = Node::create(array(
      'type' => 'suggested_dataset',
      'title' => $webform_submission->getElementData('title_of_dataset'),
      'field_organization' => $webform_submission->getElementData('department'),
      'body' => $webform_submission->getElementData('description_dataset'),
      'field_dataset_subject' => $webform_submission->getElementData('dataset_subject'),
      'field_dataset_keywords' => $webform_submission->getElementData('dataset_keywords'),
      'field_feedback' => $webform_submission->getElementData('additional_comments_and_feedback'),
      'field_webform_submission_id'=> $webform_submission->id(),
      'langcode' => \Drupal::languageManager()->getCurrentLanguage()->getId(),
      'status' => 0,
    ));

    $node->save();
  }

}
