<?php

namespace CirrusSearch\Sanity;

use CirrusSearch\CirrusTestCase;
use MediaWiki\Title\Title;
use WikiPage;

/**
 * @covers \CirrusSearch\Sanity\BufferedRemediator
 */
class BufferedRemediatorTest extends CirrusTestCase {

	public function testGetActions() {
		$wp = $this->createMock( WikiPage::class );
		$docId = "123";
		$indexSuffix = "content";
		$title = Title::makeTitle( NS_MAIN, "Test" );

		$remediator = new BufferedRemediator();

		$remediator->ghostPageInIndex( $docId, $title );
		$remediator->oldDocument( $wp );
		$remediator->oldVersionInIndex( $docId, $wp, $indexSuffix );
		$remediator->pageInWrongIndex( $docId, $wp, $indexSuffix );
		$remediator->pageNotInIndex( $wp );
		$remediator->redirectInIndex( $wp );

		$expected = [
			[ 'ghostPageInIndex', [ $docId, $title ] ],
			[ 'oldDocument', [ $wp ] ],
			[ 'oldVersionInIndex', [ $docId, $wp, $indexSuffix ] ],
			[ 'pageInWrongIndex', [ $docId, $wp, $indexSuffix ] ],
			[ 'pageNotInIndex', [ $wp ] ],
			[ 'redirectInIndex', [ $wp ] ]
		];
		$this->assertEquals( $expected, $remediator->getActions() );
	}

	public function testResetActions() {
		$docId = "123";
		$title = Title::makeTitle( NS_MAIN, "Test" );

		$remediator = new BufferedRemediator();
		$remediator->ghostPageInIndex( $docId, $title );
		$this->assertNotEmpty( $remediator->getActions() );
		$remediator->resetActions();
		$this->assertSame( [], $remediator->getActions() );
	}

	public function testReplayOn() {
		$wp = $this->createMock( WikiPage::class );
		$docId = "123";
		$indexSuffix = "content";
		$title = Title::makeTitle( NS_MAIN, "Test" );

		$remediator = new BufferedRemediator();
		$remediator->ghostPageInIndex( $docId, $title );
		$remediator->oldDocument( $wp );
		$remediator->oldVersionInIndex( $docId, $wp, $indexSuffix );
		$remediator->pageInWrongIndex( $docId, $wp, $indexSuffix );
		$remediator->pageNotInIndex( $wp );
		$remediator->redirectInIndex( $wp );

		$mock = $this->createMock( Remediator::class );
		$mock->expects( $this->once() )
			->method( 'ghostPageInIndex' )
			->with( $docId, $title );
		$mock->expects( $this->once() )
			->method( 'oldDocument' )
			->with( $wp );
		$mock->expects( $this->once() )
			->method( 'oldVersionInIndex' )
			->with( $docId, $wp, $indexSuffix );
		$mock->expects( $this->once() )
			->method( 'pageInWrongIndex' )
			->with( $docId, $wp, $indexSuffix );
		$mock->expects( $this->once() )
			->method( 'pageNotInIndex' )
			->with( $wp );
		$mock->expects( $this->once() )
			->method( 'redirectInIndex' )
			->with( $wp );
		$remediator->replayOn( $mock );
	}

	public function testHasSameActions() {
		$wp = $this->createMock( WikiPage::class );
		$docId = "123";
		$title = Title::makeTitle( NS_MAIN, "Test" );

		$remediator = new BufferedRemediator();
		$remediator->ghostPageInIndex( $docId, $title );
		$remediator->oldDocument( $wp );

		$remediator2 = new BufferedRemediator();
		$this->assertFalse( $remediator->hasSameActions( $remediator2 ) );
		$this->assertFalse( $remediator2->hasSameActions( $remediator ) );

		$remediator2->ghostPageInIndex( $docId, $title );
		$this->assertFalse( $remediator->hasSameActions( $remediator2 ) );
		$this->assertFalse( $remediator2->hasSameActions( $remediator ) );

		$remediator2->oldDocument( $wp );
		$this->assertTrue( $remediator->hasSameActions( $remediator2 ) );
		$this->assertTrue( $remediator2->hasSameActions( $remediator ) );
	}
}
