<?php

namespace CirrusSearch\Query;

use CirrusSearch\CrossSearchStrategy;
use CirrusSearch\Parser\AST\KeywordFeatureNode;
use CirrusSearch\Query\Builder\QueryBuildingContext;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\SearchConfig;
use Elastica\Query;
use Elastica\Query\AbstractQuery;

/**
 * File type features:
 *  filetype:bitmap
 * Selects only files of these specified features.
 */
class FileTypeFeature extends SimpleKeywordFeature implements FilterQueryFeature {
	/**
	 * @return string[]
	 */
	protected function getKeywords() {
		return [ 'filetype' ];
	}

	/**
	 * @param KeywordFeatureNode $node
	 * @return CrossSearchStrategy
	 */
	public function getCrossSearchStrategy( KeywordFeatureNode $node ) {
		return CrossSearchStrategy::allWikisStrategy();
	}

	/**
	 * @param SearchContext $context
	 * @param string $key The keyword
	 * @param string $value The value attached to the keyword with quotes stripped
	 * @param string $quotedValue The original value in the search string, including quotes
	 *     if used
	 * @param bool $negated Is the search negated? Not used to generate the returned
	 *     AbstractQuery, that will be negated as necessary. Used for any other building/context
	 *     necessary.
	 * @return array Two element array, first an AbstractQuery or null to apply to the
	 *  query. Second a boolean indicating if the quotedValue should be kept in the search
	 *  string.
	 */
	protected function doApply( SearchContext $context, $key, $value, $quotedValue, $negated ) {
		$query = $this->doGetFilterQuery( $context->getConfig(), $key, $value, $quotedValue );

		return [ $query, false ];
	}

	/**
	 * @param SearchConfig $config
	 * @param string $key
	 * @param string $value
	 * @param string $quotedValue
	 * @return Query\Match|Query\MatchPhrase
	 */
	protected function doGetFilterQuery( SearchConfig $config, $key, $value, $quotedValue ) {
		$aliases = $config->get( 'CirrusSearchFiletypeAliases' );
		if ( is_array( $aliases ) && isset( $aliases[$value] ) ) {
			$value = $aliases[$value];
		}
		return new Query\Match( 'file_media_type', [ 'query' => $value ] );
	}

	/**
	 * @param KeywordFeatureNode $node
	 * @param QueryBuildingContext $context
	 * @return AbstractQuery|null
	 */
	public function getFilterQuery( KeywordFeatureNode $node, QueryBuildingContext $context ) {
		return $this->doGetFilterQuery( $context->getSearchConfig(), $node->getKey(),
			$node->getValue(), $node->getQuotedValue() );
	}
}
