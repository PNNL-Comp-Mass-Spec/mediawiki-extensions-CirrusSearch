<?php

namespace CirrusSearch\Search;

use File;
use LogicException;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use SearchResult;
use SearchResultTrait;

/**
 * Base class for SearchResult
 */
abstract class CirrusSearchResult extends SearchResult {
	use SearchResultTrait;

	/**
	 * @var Title
	 */
	private $title;

	/**
	 * @var ?File
	 */
	private $file;

	/**
	 * @var bool
	 */
	private $checkedForFile = false;

	/**
	 * @param Title $title
	 */
	public function __construct( Title $title ) {
		$this->title = $title;
	}

	/**
	 * Initialize from a Title and if possible initializes a corresponding
	 * File.
	 *
	 * @param Title $title
	 */
	final protected function initFromTitle( $title ) {
		// Everything is done in the constructor.
		// XXX: we do not call the SearchResultInitFromTitle hook
		// this hook is designed to fetch a particular revision
		// but the way cirrus works does not allow to vary the revision
		// text being displayed at query time.
	}

	/**
	 * Check if this is result points to an invalid title
	 *
	 * @return bool
	 */
	final public function isBrokenTitle() {
		// Title is mandatory in the constructor it would have failed earlier if the Title was broken
		return false;
	}

	/**
	 * Check if target page is missing, happens when index is out of date
	 *
	 * @return bool
	 */
	final public function isMissingRevision() {
		global $wgCirrusSearchDevelOptions;
		if ( isset( $wgCirrusSearchDevelOptions['ignore_missing_rev'] ) ) {
			return false;
		}
		return !$this->getTitle()->isKnown();
	}

	/**
	 * @return Title
	 */
	final public function getTitle() {
		return $this->title;
	}

	/**
	 * Get the file for this page, if one exists
	 * @return File|null
	 */
	final public function getFile() {
		if ( !$this->checkedForFile && $this->getTitle()->getNamespace() === NS_FILE ) {
			$this->checkedForFile = true;
			$this->file = MediaWikiServices::getInstance()->getRepoGroup()
				->findFile( $this->title );
		}
		return $this->file;
	}

	/**
	 * Lazy initialization of article text from DB
	 * @return never
	 */
	final protected function initText() {
		throw new LogicException( "initText() should not be called on CirrusSearchResult, " .
			"content must be fetched directly from the backend at query time." );
	}

	/**
	 * @return string
	 */
	abstract public function getDocId();

	/**
	 * @return float
	 */
	abstract public function getScore();

	/**
	 * @return array|null
	 */
	abstract public function getExplanation();
}
