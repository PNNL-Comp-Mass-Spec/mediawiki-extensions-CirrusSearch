<?php

namespace CirrusSearch\Query;

use CirrusSearch\BuildDocument\Completion\SuggestBuilder;
use CirrusSearch\CompletionRequestLog;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\SearchConfig;
use Elastica\ResultSet;
use Elastica\Suggest;
use Elastica\Suggest\Completion;
use SearchSuggestion;
use SearchSuggestionSet;

/**
 * Suggest (Completion) query builder.
 * Unlike classic query builders it will :
 * - handle limit differently as offsets are not accepted during suggest queries
 * - store a mutable state in mergedProfiles
 *
 */
class CompSuggestQueryBuilder {
	use QueryBuilderTraits;

	const VARIANT_EXTRA_DISCOUNT = 0.0001;

	/** @var SearchContext $searchContext (final) */
	private $searchContext;

	/** @var array $profile (final) */
	private $profile;

	/** @var int $limit (final) */
	private $limit;

	/** @var int $hardLimit (final) */
	private $hardLimit;

	/** @var int $offset (final) */
	private $offset;

	/** @var array $mergedProfiles (mutable) state built after calling self::build */
	private $mergedProfiles;

	/**
	 * @param SearchContext $context
	 * @param array $profile settings as definied in profiles/SuggestProfiles.php
	 * @param int $limit the number of results to display
	 * @param int $offset
	 */
	public function __construct( SearchContext $context, array $profile, $limit, $offset = 0 ) {
		$this->searchContext = $context;
		$this->profile = $profile;
		$this->limit = $limit;
		$this->offset = $offset;
		$this->hardLimit = self::computeHardLimit( $limit, $offset, $context->getConfig() );
	}

	/**
	 * Check the builder settings to determine if results are possible.
	 * If this method returns false the query must not have to be sent to elastic
	 *
	 * @return bool true if results are possible false otherwise
	 */
	public function areResultsPossible() {
		// If the offset requested is greater than the hard limit
		// allowed we will always return an empty set so let's do it
		// asap.
		return $this->offset < $this->hardLimit;
	}

	/**
	 * Build the suggest query
	 * @param string $term
	 * @param string[]|null $variants
	 * @return Suggest
	 */
	public function build( $term, $variants = null ) {
		$this->checkTitleSearchRequestLength( $term );
		$origTerm = $term;
		if ( mb_strlen( $term ) > SuggestBuilder::MAX_INPUT_LENGTH ) {
			// Trim the query otherwise we won't find results
			$term = mb_substr( $term, 0, SuggestBuilder::MAX_INPUT_LENGTH );
		}

		$queryLen = mb_strlen( trim( $term ) ); // Avoid cheating with spaces

		$this->mergedProfiles = $this->profile;
		$suggest = $this->buildSuggestQueries( $this->profile, $term, $queryLen );

		// Handle variants, update the set of profiles and suggest queries
		if ( !empty( $variants ) ) {
			$this->handleVariants( $suggest, $variants, $queryLen, $origTerm );
		}
		return $suggest;
	}

	/**
	 * Builds a set of suggest query by reading the list of profiles
	 * @param array $profiles
	 * @param string $query
	 * @param int $queryLen the length to use when checking min/max_query_len
	 * @return Suggest a set of suggest queries ready to for elastic
	 */
	private function buildSuggestQueries( array $profiles, $query, $queryLen ) {
		$suggest = new Suggest();
		foreach ( $profiles as $name => $config ) {
			$sugg = $this->buildSuggestQuery( $name, $config, $query, $queryLen );
			if ( $sugg === null ) {
				continue;
			}
			$suggest->addSuggestion( $sugg );
		}
		return $suggest;
	}

	/**
	 * Builds a suggest query from a profile
	 * @param string $name name of the suggestion
	 * @param array $config Profile
	 * @param string $query
	 * @param int $queryLen the length to use when checking min/max_query_len
	 * @return Completion|null suggest query ready to for elastic or null
	 */
	private function buildSuggestQuery( $name, array $config, $query, $queryLen ) {
		// Do not remove spaces at the end, the user might tell us he finished writing a word
		$query = ltrim( $query );
		if ( $config['min_query_len'] > $queryLen ) {
			return null;
		}
		if ( isset( $config['max_query_len'] ) && $queryLen > $config['max_query_len'] ) {
			return null;
		}
		$field = $config['field'];
		$sug = new Completion( $name, $field );
		$sug->setPrefix( $query );
		$sug->setSize( $this->hardLimit * $config['fetch_limit_factor'] );
		if ( isset( $config['fuzzy'] ) ) {
			$sug->setFuzzy( $config['fuzzy'] );
		}
		return $sug;
	}

	/**
	 * Update the suggest queries and return additional profiles flagged the 'fallback' key
	 * with a discount factor = originalDiscount * 0.0001/(variantIndex+1).
	 * @param Suggest $suggests
	 * @param array $variants
	 * @param int $queryLen the original query length
	 * @param string $term original term (used to dedup)
	 * @internal param array $profiles the default profiles
	 */
	private function handleVariants( Suggest $suggests, array $variants, $queryLen, $term ) {
		$variantIndex = 0;
		$done = [ $term ];
		foreach ( $variants as $variant ) {
			if ( in_array( $variant, $done, true ) ) {
				continue;
			}
			$done[] = $variant;
			$variantIndex++;
			foreach ( $this->profile as $name => $profile ) {
				$variantProfName = $name . '-variant-' . $variantIndex;
				$profile = $this->buildVariantProfile( $profile, self::VARIANT_EXTRA_DISCOUNT / $variantIndex );
				$suggest = $this->buildSuggestQuery(
					$variantProfName, $profile, $variant, $queryLen
				);
				if ( $suggest !== null ) {
					$suggests->addSuggestion( $suggest );
					$this->mergedProfiles[$variantProfName] = $profile;
				}
			}
		}
	}

	/**
	 * Creates a copy of $profile[$name] with a custom '-variant-SEQ' suffix.
	 * And applies an extra discount factor of 0.0001.
	 * The copy is added to the profiles container.
	 * @param array $profile profile to copy
	 * @param float $extraDiscount extra discount factor to rank variant suggestion lower.
	 * @return array
	 */
	protected function buildVariantProfile( array $profile, $extraDiscount = 0.0001 ) {
		// mark the profile as a fallback query
		$profile['fallback'] = true;
		$profile['discount'] *= $extraDiscount;
		return $profile;
	}

	/**
	 * Post process the response from elastic to build the SearchSuggestionSet.
	 *
	 * Merge top level multi-queries and resolve returned pageIds into Title objects.
	 *
	 * @param ResultSet $results
	 * @param $indexName
	 * @param CompletionRequestLog $log
	 * @return SearchSuggestionSet a set of Suggestions
	 */
	public function postProcess( ResultSet $results, $indexName, CompletionRequestLog $log ) {
		$log->setResponse( $results->getResponse() );
		$suggestResp = $results->getSuggests();
		if ( $suggestResp === [] ) {
			// Edge case where the index contains 0 documents and does not even return the 'suggest' field
			return SearchSuggestionSet::emptySuggestionSet();
		}
		$suggestionsByDocId = [];
		$suggestionProfileByDocId = [];
		$hitsTotal = 0;
		foreach ( $suggestResp as $name => $sug ) {
			$discount = $this->mergedProfiles[$name]['discount'];
			foreach ( $sug  as $suggested ) {
				$hitsTotal += count( $suggested['options'] );
				foreach ( $suggested['options'] as $suggest ) {
					$page = $suggest['text'];
					$targetTitle = $page;
					$targetTitleNS = NS_MAIN;
					if ( isset( $suggest['_source']['target_title'] ) ) {
						$targetTitle = $suggest['_source']['target_title']['title'];
						$targetTitleNS = $suggest['_source']['target_title']['namespace'];
					}
					list( $docId, $type ) = $this->decodeId( $suggest['_id'] );
					$score = $discount * $suggest['_score'];
					if ( !isset( $suggestionsByDocId[$docId] ) ||
						$score > $suggestionsByDocId[$docId]->getScore()
					) {
						$pageId = $this->searchContext->getConfig()->makePageId( $docId );
						$suggestion = new SearchSuggestion( $score, null, null, $pageId );
						// Use setText, it'll build the Title
						if ( $type === SuggestBuilder::TITLE_SUGGESTION && $targetTitleNS === NS_MAIN ) {
							// For title suggestions we always use the target_title
							// This is because we may encounter default_sort or subphrases that are not valid titles...
							// And we prefer to display the title over close redirects
							// for CrossNS redirect we prefer the returned suggestion
							$suggestion->setText( $targetTitle );

						} else {
							$suggestion->setText( $page );
						}
						$suggestionsByDocId[$docId] = $suggestion;
						$suggestionProfileByDocId[$docId] = $name;
					}
				}
			}
		}

		// simply sort by existing scores
		uasort( $suggestionsByDocId, function ( SearchSuggestion $a, SearchSuggestion $b ) {
			return $b->getScore() - $a->getScore();
		} );

		$suggestionsByDocId = $this->offset < $this->hardLimit
			? array_slice( $suggestionsByDocId, $this->offset, $this->hardLimit - $this->offset,
				true )
			: [];

		$log->setResult( $indexName, $suggestionsByDocId, $suggestionProfileByDocId );

		return new SearchSuggestionSet( $suggestionsByDocId );
	}

	/**
	 * @param string $id compacted id (id + $type)
	 * @return array 2 elt array [ $id, $type ]
	 */
	private function decodeId( $id ) {
		return [ intval( substr( $id, 0, -1 ) ), substr( $id, -1 ) ];
	}

	/**
	 * (public for tests)
	 * @return array
	 */
	public function getMergedProfiles() {
		return $this->mergedProfiles;
	}

	/**
	 * Get the hard limit
	 * The completion api does not supports offset we have to add a hack
	 * here to work around this limitation.
	 * To avoid ridiculously large queries we set also a hard limit.
	 * Note that this limit will be changed by fetch_limit_factor set to 2 or 1.5
	 * depending on the profile.
	 * @param int $limit limit requested
	 * @param int $offset offset requested
	 * @param SearchConfig $config
	 * @return int the number of results to fetch from elastic
	 */
	private static function computeHardLimit( $limit, $offset, SearchConfig $config ) {
		$limit = $limit + $offset;
		$hardLimit = $config->get( 'CirrusSearchCompletionSuggesterHardLimit' );
		if ( $hardLimit === null ) {
			$hardLimit = 50;
		}
		if ( $limit > $hardLimit ) {
			return $hardLimit;
		}
		return $limit;
	}
}
