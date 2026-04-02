<?php

namespace Drupal\artsci_search\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns responses for Artsci Search routes.
 */
class ArtsciSearchResultsController extends ControllerBase {

  /**
   * Builds the response.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return array
   *   The render array for the search results page.
   */
  public function build(Request $request) {
    $config = $this->config('artsci_search.settings')->get('artsci_search');
    $search_terms = $request->get('terms');

    $display_search_all_artsci = $config['display_search_all_artsci'] ?? TRUE;

    if ($display_search_all_artsci) {
      $build['search'] = [
        '#type' => 'link',
        '#title' => $this->t('Search all University of artsci for @terms', ['@terms' => $search_terms]),
        '#url' => Url::fromUri('https://artsci.wustl.edu/search', ['query' => ['terms' => $search_terms]]),
        '#attributes' => [
          'target' => '_blank',
        ],
      ];
    }

    $build['results_container'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'search-results',
      ],
    ];

    $build['#attached']['library'][] = 'artsci_search/search-results';
    $build['#attached']['drupalSettings']['artsciSearch']['engineId'] = $config['cse_engine_id'];
    $build['#attached']['drupalSettings']['artsciSearch']['cseScope'] = $config['cse_scope'];

    // Cache by URL query arguments since that controls the link markup above.
    $build['#cache']['contexts'][] = 'url.query_args';

    return $build;
  }

}
