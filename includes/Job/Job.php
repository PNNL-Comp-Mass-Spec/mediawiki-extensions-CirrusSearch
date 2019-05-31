<?php

namespace CirrusSearch\Job;

use CirrusSearch\SearchConfig;
use Job as MWJob;
use MediaWiki\MediaWikiServices;
use Title;

/**
 * Abstract job class used by all CirrusSearch*Job classes
 *
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
abstract class Job extends MWJob {
	use JobTraits;

	/**
	 * @var SearchConfig
	 */
	protected $searchConfig;

	/**
	 * @param Title $title
	 * @param array $params
	 */
	public function __construct( $title, $params ) {
		$params += [ 'cluster' => null ];
		// eg: DeletePages -> cirrusSearchDeletePages
		$jobName = self::buildJobName( static::class );

		parent::__construct( $jobName, $title, $params );

		// All CirrusSearch jobs are reasonably expensive.  Most involve parsing and it
		// is ok to remove duplicate _unclaimed_ cirrus jobs.  Once a cirrus job is claimed
		// it can't be deduplicated or else the search index will end up with out of date
		// data.  Luckily, this is how the JobQueue implementations work.
		$this->removeDuplicates = true;

		$this->searchConfig = MediaWikiServices::getInstance()
			->getConfigFactory()
			->makeConfig( 'CirrusSearch' );
	}

	/**
	 * @return SearchConfig
	 */
	public function getSearchConfig(): SearchConfig {
		return $this->searchConfig;
	}
}
