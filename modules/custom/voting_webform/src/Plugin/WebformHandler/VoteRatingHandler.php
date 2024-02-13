<?php

namespace Drupal\voting_webform\Plugin\WebformHandler;

use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\webformSubmissionInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Webform voteSubmission handler.
 *
 * @WebformHandler(
 *   id = "vote_rating_handler",
 *   label = @Translation("Vote Maple Leaf Rating Submission Handler"),
 *   category = @Translation("voteSubmission"),
 *   description = @Translation("voteSubmission of a webform submission handler."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_IGNORED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_REQUIRED,
 * )
 */

class VoteRatingHandler extends WebformHandlerBase
{

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission)
    {
        $node = \Drupal::routeMatch()
            ->getParameter(
                $webform_submission
                    ->getSourceEntity()
                    ->getEntityTypeId()
            );
        if ($node instanceof \Drupal\node\NodeInterface) {
            $rating = $form_state->getValue('rating');

            if ($webform_submission->getSourceEntity()->id() === $node->id()
                && in_array($rating, [1,2,3,4,5])
            ) {
                $vote_count = $node->get('field_vote_count')->value;
                $vote_avg = $node
                    ->get('field_vote_average')
                    ->value;
                $weighted_average = $vote_avg * $vote_count;
                $new_avg = ($weighted_average + $rating) / ($vote_count + 1);
                $node->field_vote_average = $new_avg;
                $node->field_vote_count = $vote_count + 1;
                $node->save();
            }
        }
    }
}
