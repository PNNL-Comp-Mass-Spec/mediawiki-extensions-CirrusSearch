<?php

namespace CirrusSearch\Search\Rescore;

use CirrusSearch\OtherIndexes;
use CirrusSearch\SearchConfig;
use CirrusSearch\Util;
use Elastica\Query\FunctionScore;

/**
 * Builds a set of functions with boosted templates
 * Uses a weight function with a filter for each template.
 * The list of boosted templates is read from SearchContext
 */
class BoostTemplatesFunctionScoreBuilder extends FunctionScoreBuilder {

	/**
	 * @var BoostedQueriesFunction
	 */
	private $boostedQueries;

	/**
	 * BoostTemplatesFunctionScoreBuilder constructor.
	 * @param SearchConfig $config
	 * @param int[]|null $requestedNamespaces
	 * @param bool $localSearch
	 * @param bool $withDefaultBoosts false to disable the use of default boost templates
	 * @param float $weight
	 */
	public function __construct( SearchConfig $config, $requestedNamespaces, $localSearch, $withDefaultBoosts, $weight ) {
		parent::__construct( $config, $weight );
		// Use the boosted templates from extra indexes if available
		$queries = [];
		$weights = [];
		if ( $withDefaultBoosts ) {
			$boostTemplates = Util::getDefaultBoostTemplates( $config );
			if ( $boostTemplates ) {
				foreach ( $boostTemplates as $name => $weight ) {
					$match = new \Elastica\Query\Match();
					$match->setFieldQuery( 'template', $name );
					$weights[] = $weight * $this->weight;
					$queries[] = $match;
				}
			}
		}

		$otherIndices = [];
		if ( $requestedNamespaces && !$localSearch ) {
			$otherIndices = OtherIndexes::getExtraIndexesForNamespaces(
				$requestedNamespaces
			);
		}

		$extraIndexBoostTemplates = [];
		foreach ( $otherIndices as $extraIndex ) {
			$extraIndexBoosts = $this->config->getElement( 'CirrusSearchExtraIndexBoostTemplates', $extraIndex );
			if ( isset( $extraIndexBoosts['wiki'], $extraIndexBoosts['boosts'] ) ) {
				$extraIndexBoostTemplates[$extraIndexBoosts['wiki']] = $extraIndexBoosts['boosts'];
			}
		}

		foreach ( $extraIndexBoostTemplates as $wiki => $boostTemplates ) {
			foreach ( $boostTemplates as $name => $weight ) {
				$bool = new \Elastica\Query\BoolQuery();
				$bool->addMust( ( new \Elastica\Query\Match() )->setFieldQuery( 'wiki', $wiki ) );
				$bool->addMust( ( new \Elastica\Query\Match() )->setFieldQuery( 'template',
					$name ) );
				$weights[] = $weight * $this->weight;
				$queries[] = $bool;
			}
		}
		$this->boostedQueries = new BoostedQueriesFunction( $queries, $weights );
	}

	public function append( FunctionScore $functionScore ) {
		$this->boostedQueries->append( $functionScore );
	}
}
