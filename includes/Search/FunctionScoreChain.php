<?php

namespace CirrusSearch\Search;

use CirrusSearch\Profile\SearchProfileService;
use Elastica\Query\FunctionScore;
use Hooks;

/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

class FunctionScoreChain {
	/**
	 * List of allowed function_score param
	 * we keep boost and boost_mode even if they do not make sense
	 * here since we do not allow to specify the query param.
	 * The query will be MatchAll with a score to 1.
	 *
	 * @var string[]
	 */
	private static $functionScoreParams = [
		'boost',
		'boost_mode',
		'max_boost',
		'score_mode',
		'min_score'
	];

	/**
	 * @var SearchContext
	 */
	private $context;

	/**
	 * @var FunctionScoreDecorator
	 */
	private $functionScore;

	/**
	 * @var array the function score chain
	 */
	private $chain;

	/**
	 * @var string the name of the chain
	 */
	private $chainName;

	/**
	 * Builds a new function score chain.
	 *
	 * @param SearchContext $context
	 * @param string $chainName the name of the chain (must be a valid
	 *  chain in wgCirrusSearchRescoreFunctionScoreChains)
	 */
	public function __construct( SearchContext $context, $chainName ) {
		$this->chainName = $chainName;
		$this->context = $context;
		$this->functionScore = new FunctionScoreDecorator();
		$this->chain = $context->getConfig()
			->getProfileService()
			->loadProfileByName( SearchProfileService::RESCORE_FUNCTION_CHAINS, $chainName );

		$params = array_intersect_key( $this->chain, array_flip( self::$functionScoreParams ) );
		foreach ( $params as $param => $value ) {
			$this->functionScore->setParam( $param, $value );
		}
	}

	/**
	 * @return FunctionScore|null the rescore query or null none of functions were
	 *  needed.
	 * @throws InvalidRescoreProfileException
	 */
	public function buildRescoreQuery() {
		if ( !isset( $this->chain['functions'] ) ) {
			throw new InvalidRescoreProfileException( "No functions defined in chain {$this->chainName}." );
		}
		foreach ( $this->chain['functions'] as $func ) {
			$impl = $this->getImplementation( $func );
			$impl->append( $this->functionScore );
		}
		// Add extensions
		if ( !empty( $this->chain['add_extensions'] ) ) {
			foreach ( $this->context->getExtraScoreBuilders() as $extBuilder ) {
				$extBuilder->append( $this->functionScore );
			}
		}
		if ( !$this->functionScore->isEmptyFunction() ) {
			return $this->functionScore;
		}
		return null;
	}

	/**
	 * @param array $func
	 * @return FunctionScoreBuilder
	 * @throws InvalidRescoreProfileException
	 * @suppress PhanTypeMismatchReturn phan does not understand hooks and by-ref parameters
	 */
	private function getImplementation( $func ) {
		$weight = isset( $func['weight'] ) ? $func['weight'] : 1;
		switch ( $func['type'] ) {
			case 'boostlinks':
				return new IncomingLinksFunctionScoreBuilder( $this->context, $weight );
			case 'recency':
				return new PreferRecentFunctionScoreBuilder( $this->context, $weight );
			case 'templates':
				return new BoostTemplatesFunctionScoreBuilder( $this->context, $weight );
			case 'namespaces':
				return new NamespacesFunctionScoreBuilder( $this->context, $weight );
			case 'language':
				return new LangWeightFunctionScoreBuilder( $this->context, $weight );
			case 'custom_field':
				return new CustomFieldFunctionScoreBuilder( $this->context, $weight, $func['params'] );
			case 'script':
				return new ScriptScoreFunctionScoreBuilder( $this->context, $weight, $func['script'] );
			case 'logscale_boost':
				return new LogScaleBoostFunctionScoreBuilder( $this->context, $weight,  $func['params'] );
			case 'satu':
				return new SatuFunctionScoreBuilder( $this->context, $weight,  $func['params'] );
			case 'log_multi':
				return new LogMultFunctionScoreBuilder( $this->context, $weight,  $func['params'] );
			case 'geomean':
				return new GeoMeanFunctionScoreBuilder( $this->context, $weight,  $func['params'] );
			case 'term_boost':
				return new TermBoostScoreBuilder( $this->context, $weight,  $func['params'] );
			default:
				$builder = null;
				Hooks::run( 'CirrusSearchScoreBuilder', [ $func, $this->context, &$builder ] );
				if ( !$builder ) {
					throw new InvalidRescoreProfileException( "Unknown function score type {$func['type']}." );
				}
				/**
				 * @var $builder FunctionScoreBuilder
				 */
				return $builder;
		}
	}
}
