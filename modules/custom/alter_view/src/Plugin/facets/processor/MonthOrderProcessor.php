<?php

namespace Drupal\alter_view\Plugin\facets\processor;

use Drupal\facets\Processor\SortProcessorPluginBase;
use Drupal\facets\Processor\SortProcessorInterface;
use Drupal\facets\Result\Result;

/**
 * A processor that orders the results by month.
 *
 * @FacetsProcessor(
 *   id = "month_order",
 *   label = @Translation("Sort by month order"),
 *   description = @Translation("Sorts month by natural month order"),
 *   stages = {
 *     "sort" = 30
 *   }
 * )
 */
class MonthOrderProcessor extends SortProcessorPluginBase implements SortProcessorInterface {

  /**
   * {@inheritdoc}
   */
  public function sortResults(Result $a, Result $b) {
    $months = [
      0 => '',
      1 => $this->t('January')->__toString(),
      2 => $this->t('February')->__toString(),
      3 => $this->t('March')->__toString(),
      4 => $this->t('April')->__toString(),
      5 => $this->t('May')->__toString(),
      6 => $this->t('June')->__toString(),
      7 => $this->t('July')->__toString(),
      8 => $this->t('August')->__toString(),
      9 => $this->t('September')->__toString(),
      10 => $this->t('October')->__toString(),
      11 => $this->t('November')->__toString(),
      12 => $this->t('December')->__toString(),
    ];

    if (array_search($a->getRawValue(), $months) == array_search($b->getRawValue(), $months)) {
      return 0;
    }
    return (array_search($a->getRawValue(), $months) < array_search($b->getRawValue(), $months)) ? -1 : 1;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['sort' => 'DESC'];
  }

}
