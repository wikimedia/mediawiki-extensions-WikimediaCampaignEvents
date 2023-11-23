<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\GrantsStore;
use MediaWiki\Extension\WikimediaCampaignEvents\Rest\GetGrantIdHandler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiUnitTestCase;

/**
 * @group Test
 * @covers \MediaWiki\Extension\WikimediaCampaignEvents\Rest\GetGrantIdHandler
 */
class GetGrantIdHandlerTest extends MediaWikiUnitTestCase {
	use HandlerTestTrait;

	private const DEFAULT_REQ_DATA = [
		'method' => 'DELETE',
		'pathParams' => [ 'id' => 42 ],
	];

	private function newHandler(
		IEventLookup $eventLookup = null,
		PermissionChecker $permissionChecker = null,
		GrantsStore $grantsStore = null
	): GetGrantIdHandler {
		return new GetGrantIdHandler(
			$eventLookup ?? $this->createMock( IEventLookup::class ),
			$permissionChecker ?? $this->createMock( PermissionChecker::class ),
			$grantsStore ??	$this->createMock( GrantsStore::class )
		);
	}

	/**
	 * @param int $expectedStatusCode
	 * @param string $expectedErrorKey
	 * @param bool $eventExists
	 * @param bool $userAllowed
	 * @dataProvider provideRequestDataWithErrors
	 */
	public function testRun__error(
		int $expectedStatusCode,
		string $expectedErrorKey,
		bool $eventExists = true,
		bool $userAllowed = true
	) {
		$eventLookup = $this->createMock( IEventLookup::class );
		if ( $eventExists ) {
			$eventLookup->method( 'getEventByID' )
				->willReturn( $this->createMock( ExistingEventRegistration::class ) );
		} else {
			$eventLookup->method( 'getEventByID' )
				->willThrowException( $this->createMock( EventNotFoundException::class ) );
		}

		$permissionChecker = $this->createMock( PermissionChecker::class );
		$permissionChecker->method( 'userCanEditRegistration' )->willReturn( $userAllowed );

		$handler = $this->newHandler( $eventLookup, $permissionChecker );
		try {
			$this->executeHandler( $handler, new RequestData( self::DEFAULT_REQ_DATA ) );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( $expectedStatusCode, $e->getCode() );
			$this->assertSame( $expectedErrorKey, $e->getMessageValue()->getKey() );
		}
	}

	/**
	 * @return Generator
	 */
	public static function provideRequestDataWithErrors(): Generator {
		yield 'Event does not exist' => [
			404,
			'campaignevents-rest-event-not-found',
			false
		];

		yield 'User cannot view grant ID' => [
			403,
			'wikimediacampaignevents-rest-grant-id-get-permission-denied',
			true,
			false
		];
	}

	public function testRun__noGrantID() {
		$permChecker = $this->createMock( PermissionChecker::class );
		$permChecker->method( 'userCanEditRegistration' )->willReturn( true );

		$eventLookup = $this->createMock( IEventLookup::class );
		$eventLookup->method( 'getEventByID' )->willReturn( $this->createMock( ExistingEventRegistration::class ) );

		$grantStore = $this->createMock( GrantsStore::class );
		$grantStore->method( 'getGrantID' )->willReturn( null );

		$handler = $this->newHandler( $eventLookup, $permChecker );
		$reqData = new RequestData( self::DEFAULT_REQ_DATA );
		$response = $this->executeHandler( $handler, $reqData );
		$this->assertSame( 404, $response->getStatusCode() );
	}

	public function testRun__successful() {
		$permChecker = $this->createMock( PermissionChecker::class );
		$permChecker->method( 'userCanEditRegistration' )->willReturn( true );

		$eventLookup = $this->createMock( IEventLookup::class );
		$eventLookup->method( 'getEventByID' )->willReturn( $this->createMock( ExistingEventRegistration::class ) );

		$expected = '1111-1111';
		$grantStore = $this->createMock( GrantsStore::class );
		$grantStore->method( 'getGrantID' )->willReturn( $expected );

		$handler = $this->newHandler( $eventLookup, $permChecker, $grantStore );
		$reqData = new RequestData( self::DEFAULT_REQ_DATA );
		$respData = $this->executeHandlerAndGetBodyData( $handler, $reqData );
		$this->assertSame( [ 'grant_id' => $expected ], $respData );
	}
}
