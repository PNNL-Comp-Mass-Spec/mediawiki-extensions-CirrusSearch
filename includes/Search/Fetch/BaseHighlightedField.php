<?php

namespace CirrusSearch\Search\Fetch;

use CirrusSearch\Search\SearchQuery;
use CirrusSearch\SearchConfig;
use Elastica\Query\AbstractQuery;
use Elastica\Query\BoolQuery;

class BaseHighlightedField extends HighlightedField {
	const TYPE = 'highlighting';

	const FVH_HL_TYPE = 'fvh';

	/** @var int|null */
	private $numberOfFragments;

	/** @var string */
	private $highlighterType;

	/** @var string|null $fragmenter */
	private $fragmenter;

	/** @var int|null fragmentSize */
	private $fragmentSize;

	/** @var int|null $noMatchSize */
	private $noMatchSize;

	/** @var string[] */
	private $matchedFields = [];

	/** @var array */
	protected $options = [];

	/** @var AbstractQuery|null */
	private $highlightQuery;

	/**
	 * @var string|null
	 */
	private $order;

	/**
	 * @param string $fieldName
	 * @param string $highlighterType
	 * @param string $target
	 * @param int $priority
	 */
	public function __construct( $fieldName, $highlighterType, $target, $priority = self::DEFAULT_TARGET_PRIORITY ) {
		parent::__construct( self::TYPE, $fieldName, $target, $priority );
		$this->highlighterType = $highlighterType;
	}

	/**
	 * @param string $option
	 * @param mixed $value (json serialization value)
	 * @return self
	 */
	public function addOption( $option, $value ): self {
		$this->options[$option] = $value;
		return $this;
	}

	/**
	 * @param string $field
	 * @return self
	 */
	public function addMatchedField( $field ): self {
		$this->matchedFields[] = $field;
		return $this;
	}

	/**
	 * @param string $order
	 * @return self
	 */
	public function setOrder( $order ): self {
		$this->order = $order;
		return $this;
	}

	/**
	 * @param int|null $numberOfFragments
	 * @return self
	 */
	public function setNumberOfFragments( $numberOfFragments ): self {
		$this->numberOfFragments = $numberOfFragments;

		return $this;
	}

	/**
	 * @param string|null $fragmenter
	 * @return self
	 */
	public function setFragmenter( $fragmenter ): self {
		$this->fragmenter = $fragmenter;

		return $this;
	}

	/**
	 * @param int|null $fragmentSize
	 * @return self
	 */
	public function setFragmentSize( $fragmentSize ): self {
		$this->fragmentSize = $fragmentSize;

		return $this;
	}

	/**
	 * @param int|null $noMatchSize
	 * @return self
	 */
	public function setNoMatchSize( $noMatchSize ): self {
		$this->noMatchSize = $noMatchSize;
		return $this;
	}

	/**
	 * @param AbstractQuery $highlightQuery
	 * @return self
	 */
	public function setHighlightQuery( AbstractQuery $highlightQuery ): self {
		$this->highlightQuery = $highlightQuery;

		return $this;
	}

	/**
	 * @return AbstractQuery|null
	 */
	public function getHighlightQuery() {
		return $this->highlightQuery;
	}

	/**
	 * @param BaseHighlightedField $other
	 * @return BaseHighlightedField
	 */
	public function merge( BaseHighlightedField $other ): self {
		if ( $this->getFieldName() !== $other->getFieldName() ) {
			throw new \InvalidArgumentException(
				"HL Field [{$this->getFieldName()}] must have the same field name to " .
				"be mergeable with [{$other->getFieldName()}]" );
		}
		if ( $this->highlighterType !== $other->highlighterType ) {
			throw new \InvalidArgumentException(
				"HL Field [{$this->getFieldName()}] must have the same highlighterType to be mergeable" );
		}
		if ( $this->getTarget() !== $other->getTarget() ) {
			throw new \InvalidArgumentException( "HL Field [{$this->getFieldName()}] must have the same target to be mergeable" );
		}
		if ( $this->highlightQuery === null || $other->highlightQuery === null ) {
			throw new \InvalidArgumentException( "HL Field [{$this->getFieldName()}] must have a query to be mergeable" );
		}
		if ( $this->matchedFields !== $other->matchedFields ) {
			throw new \InvalidArgumentException( "HL Field [{$this->getFieldName()}] must have the same matchedFields to be mergeable" );
		}
		if ( $this->getFragmenter() !== $other->getFragmenter() ) {
			throw new \InvalidArgumentException( "HL Field [{$this->getFieldName()}] must have the same fragmenter to be mergeable" );
		}
		if ( $this->getNumberOfFragments() !== $other->getNumberOfFragments() ) {
			throw new \InvalidArgumentException( "HL Field [{$this->getFieldName()}] must have the same numberOfFragments to be mergeable" );
		}
		if ( $this->getNoMatchSize() !== $other->getNoMatchSize() ) {
			throw new \InvalidArgumentException( "HL Field [{$this->getFieldName()}] must have the same noMatchSize to be mergeable" );
		}
		if ( $this->options !== $other->options ) {
			throw new \InvalidArgumentException( "HL Field [{$this->getFieldName()}] must have the same options to be mergeable" );
		}
		if ( $this->highlightQuery instanceof BoolQuery ) {
			$this->highlightQuery->addShould( $other->highlightQuery );
		} else {
			$thisQuery = $this->highlightQuery;
			$otherQuery = $other->highlightQuery;
			$this->highlightQuery = new BoolQuery();
			$this->highlightQuery->addShould( $thisQuery );
			$this->highlightQuery->addShould( $otherQuery );
		}
		return $this;
	}

	public function setOptions( array $options ) {
		$this->options = $options;
	}

	/**
	 * @return array
	 */
	public function getOptions(): array {
		return $this->options;
	}

	/**
	 * @return int|null
	 */
	public function getNumberOfFragments() {
		return $this->numberOfFragments;
	}

	/**
	 * @return string
	 */
	public function getHighlighterType() {
		return $this->highlighterType;
	}

	/**
	 * @return string|null
	 */
	public function getFragmenter() {
		return $this->fragmenter;
	}

	/**
	 * @return int|null
	 */
	public function getFragmentSize() {
		return $this->fragmentSize;
	}

	/**
	 * @return int|null
	 */
	public function getNoMatchSize() {
		return $this->noMatchSize;
	}

	/**
	 * @return string[]
	 */
	public function getMatchedFields(): array {
		return $this->matchedFields;
	}

	/**
	 * @return string|null
	 */
	public function getOrder() {
		return $this->order;
	}

	/**
	 * @return array
	 */
	public function toArray() {
		$output = [
			'type' => $this->highlighterType
		];

		if ( $this->numberOfFragments !== null ) {
			$output['number_of_fragments'] = $this->numberOfFragments;
		}

		if ( $this->fragmenter !== null ) {
			$output['fragmenter'] = $this->fragmenter;
		}

		if ( $this->highlightQuery !== null ) {
			$output['highlight_query'] = $this->highlightQuery->toArray();
		}
		if ( $this->order !== null ) {
			$output['order'] = $this->order;
		}

		if ( $this->fragmentSize !== null ) {
			$output['fragment_size'] = $this->fragmentSize;
		}

		if ( $this->noMatchSize ) {
			$output['no_match_size'] = $this->noMatchSize;
		}

		if ( $this->options !== [] ) {
			$output['options'] = $this->options;
		}

		if ( $this->matchedFields !== [] ) {
			$output['matched_fields'] = $this->matchedFields;
		}

		return $output;
	}

	/**
	 * @return callable
	 */
	protected static function entireValue(): callable {
		return function ( SearchConfig $config, $fieldName, $target, $priority = self::DEFAULT_TARGET_PRIORITY ) {
			$self = new self( $fieldName, self::FVH_HL_TYPE, $target, $priority );
			$self->setNumberOfFragments( 0 );
			$self->setOrder( 'score' );
			$self->matchPlainFields();
			return $self;
		};
	}

	/**
	 * @return callable
	 */
	protected static function redirectAndHeadings(): callable {
		return function ( SearchConfig $config, $fieldName, $target, $priority = self::DEFAULT_TARGET_PRIORITY ) {
			$self = new self( $fieldName, self::FVH_HL_TYPE, $target, $priority );
			$self->setNumberOfFragments( 1 );
			$self->matchPlainFields();
			$self->setFragmentSize( 10000 ); // We want the whole value but more than this is crazy
			$self->setOrder( 'score' );
			return $self;
		};
	}

	/**
	 * @return callable
	 */
	protected static function text(): callable {
		return function ( SearchConfig $config, $fieldName, $target, $priority ) {
			$self = new self( $fieldName, self::FVH_HL_TYPE, $target, $priority );
			$self->setNumberOfFragments( 1 );
			$self->matchPlainFields();
			$self->setOrder( 'score' );
			$self->setFragmentSize( $config->get( 'CirrusSearchFragmentSize' ) );
			return $self;
		};
	}

	/**
	 * @return callable
	 */
	protected static function mainText(): callable {
		return function ( SearchConfig $config, $fieldName, $target, $priority ) {
			$self = ( self::text() )( $config, $fieldName, $target, $priority );
			/** @var BaseHighlightedField $self */
			$self->setNoMatchSize( $config->get( 'CirrusSearchFragmentSize' ) );
			return $self;
		};
	}

	/**
	 * Skip this field if the previous matched
	 * Optimization available only on the experimental highlighter.
	 * @return self
	 */
	public function skipIfLastMatched(): self {
		return $this;
	}

	public static function getFactories() {
		return [
			SearchQuery::SEARCH_TEXT => [
				'title' => self::entireValue(),
				'redirect.title' => self::redirectAndHeadings(),
				'category' => self::redirectAndHeadings(),
				'heading' => self::redirectAndHeadings(),
				'text' => self::mainText(),
				'source_text.plain' => self::mainText(),
				'auxiliary_text' => self::text(),
				'file_text' => self::text(),
			]
		];
	}

	/**
	 * Helper function to populate the matchedFields array with the additional .plain field.
	 * This only works if the getFieldName() denotes the actual elasticsearch field to highlight
	 * and is not already a plain field.
	 */
	protected function matchPlainFields() {
		if ( substr_compare( $this->getFieldName(), '.plain', -strlen( '.plain' ) ) !== 0 ) {
			$this->matchedFields = [ $this->getFieldName(), $this->getFieldName() . '.plain' ];
		}
	}
}