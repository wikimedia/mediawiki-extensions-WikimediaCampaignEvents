<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\GrantsStore;
use MediaWiki\Extension\WikimediaCampaignEvents\Rest\DeleteGrantIdHandler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Session\Session;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiUnitTestCase;

/**
 * @group Test
 * @covers \MediaWiki\Extension\WikimediaCampaignEvents\Rest\DeleteGrantIdHandler
 */
class DeleteGrantIdHandlerTest extends MediaWikiUnitTestCase {
	use HandlerTestTrait;
	use CSRFTestHelperTrait;

	private const DEFAULT_REQ_DATA = [
		'method' => 'DELETE',
		'pathParams' => [ 'id' => 42 ],
	];

	private function newHandler(
		IEventLookup $eventLookup = null,
		PermissionChecker $permissionChecker = null,
		GrantsStore $grantsStore = null
	): DeleteGrantIdHandler {
		return new DeleteGrantIdHandler(
			$eventLookup ?? $this->createMock( IEventLookup::class ),
			$permissionChecker ?? $this->createMock( PermissionChecker::class ),
			$grantsStore ??	$this->createMock( GrantsStore::class )
		);
	}

	/**
	 * @dataProvider provideBadTokenSessions
	 */
	public function testRun__badToken( Session $session, string $exceptMsg, ?string $token ) {
		$this->assertCorrectBadTokenBehaviour(
			$this->newHandler(),
			self::DEFAULT_REQ_DATA,
			$session,
			$token,
			$exceptMsg
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

		yield 'User cannot delete grant' => [
			403,
			'wikimediacampaignevents-rest-grant-id-edit-permission-denied',
			true,
			false
		];
	}

	public function testRun__successful() {
		$permChecker = $this->createMock( PermissionChecker::class );
		$permChecker->method( 'userCanEditRegistration' )->willReturn( true );

		$eventLookup = $this->createMock( IEventLookup::class );
		$eventLookup->method( 'getEventByID' )->willReturn( $this->createMock( ExistingEventRegistration::class ) );

		$handler = $this->newHandler( $eventLookup, $permChecker );
		$reqData = new RequestData( self::DEFAULT_REQ_DATA );
		$response = $this->executeHandler( $handler, $reqData );
		$this->assertSame( 204, $response->getStatusCode() );
	}
}
