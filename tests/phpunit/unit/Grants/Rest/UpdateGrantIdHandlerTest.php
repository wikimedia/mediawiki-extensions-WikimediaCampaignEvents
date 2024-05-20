<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use Exception;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\Exception\FluxxRequestException;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\Exception\InvalidGrantIDException;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\GrantIDLookup;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\GrantsStore;
use MediaWiki\Extension\WikimediaCampaignEvents\Rest\UpdateGrantIdHandler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Session\Session;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiUnitTestCase;
use StatusValue;

/**
 * @group Test
 * @covers \MediaWiki\Extension\WikimediaCampaignEvents\Rest\UpdateGrantIdHandler
 */
class UpdateGrantIdHandlerTest extends MediaWikiUnitTestCase {
	use HandlerTestTrait;
	use CSRFTestHelperTrait;

	private const DEFAULT_REQ_DATA = [
		'method' => 'PUT',
		'pathParams' => [ 'id' => 42 ],
		'headers' => [ 'Content-Type' => 'application/json' ],
	];

	private static function getRequestData( string $grantID = '1111-1111' ): array {
		$data = self::DEFAULT_REQ_DATA;
		$data['bodyContents'] = json_encode( [ 'grant_id' => $grantID ] );

		return $data;
	}

	private function newHandler(
		IEventLookup $eventLookup = null,
		PermissionChecker $permissionChecker = null,
		GrantIDLookup $grantIdLookup = null,
		GrantsStore $grantsStore = null
	): UpdateGrantIdHandler {
		return new UpdateGrantIdHandler(
			$eventLookup ?? $this->createMock( IEventLookup::class ),
			$grantIdLookup ?? $this->createMock( GrantIDLookup::class ),
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
			self::getRequestData(),
			$session,
			$token,
			$exceptMsg
		);
	}

	/**
	 * @param int $expectedStatusCode
	 * @param string $expectedErrorKey
	 * @param array $reqData
	 * @param bool $eventExists
	 * @param bool $userAllowed
	 * @param Exception|true $grantIDLookupResult True for success, or exception to throw for failures
	 * @dataProvider provideRequestDataWithErrors
	 */
	public function testRun__error(
		int $expectedStatusCode,
		string $expectedErrorKey,
		array $reqData,
		bool $eventExists = true,
		bool $userAllowed = true,
		$grantIDLookupResult = true
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

		$grantIDLookup = $this->createMock( GrantIDLookup::class );
		if ( $grantIDLookupResult === true ) {
			$grantIDLookup->method( 'doLookup' )->willReturn( StatusValue::newGood() );
		} else {
			$grantIDLookup->method( 'doLookup' )->willThrowException( $grantIDLookupResult );
		}

		$handler = $this->newHandler( $eventLookup, $permissionChecker, $grantIDLookup );
		try {
			$this->executeHandler( $handler, new RequestData( $reqData ) );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( $expectedStatusCode, $e->getCode() );
			$this->assertSame( $expectedErrorKey, $e->getMessageValue()->getKey() );
		}
	}

	public static function provideRequestDataWithErrors() {
		yield 'Event does not exist' => [
			404,
			'campaignevents-rest-event-not-found',
			self::getRequestData(),
			false
		];

		yield 'User cannot edit grant ID' => [
			403,
			'wikimediacampaignevents-rest-grant-id-edit-permission-denied',
			self::getRequestData(),
			true,
			false
		];

		yield 'Empty grant ID' => [
			400,
			'rest-body-validation-error',
			self::getRequestData( '' ),
		];

		yield 'Invalid grant ID format' => [
			400,
			'wikimediacampaignevents-rest-grant-id-edit-invalid',
			self::getRequestData( 'garbage' ),
		];

		yield 'Invalid grant ID according to Fluxx' => [
			400,
			'wikimediacampaignevents-rest-grant-id-edit-invalid',
			self::getRequestData(),
			true,
			true,
			new InvalidGrantIDException()
		];

		yield 'Fluxx error' => [
			503,
			'wikimediacampaignevents-rest-grant-id-edit-api-error',
			self::getRequestData(),
			true,
			true,
			new FluxxRequestException()
		];
	}

	/**
	 * @dataProvider provideSuccessData
	 */
	public function testRun__successful( string $grantID, ?string $previousGrantID ) {
		$permChecker = $this->createMock( PermissionChecker::class );
		$permChecker->method( 'userCanEditRegistration' )->willReturn( true );

		$grantLookup = $this->createMock( GrantIDLookup::class );
		$grantLookup->method( 'doLookup' )->willReturn( StatusValue::newGood() );
		$grantLookup->method( 'getAgreementAt' )->willReturn( '20200101000000' );

		$grantStore = $this->createMock( GrantsStore::class );
		$grantStore->method( 'getGrantID' )->willReturn( $previousGrantID );

		$eventLookup = $this->createMock( IEventLookup::class );
		$eventLookup->method( 'getEventByID' )->willReturn( $this->createMock( ExistingEventRegistration::class ) );

		$handler = $this->newHandler( $eventLookup, $permChecker, $grantLookup, $grantStore );
		$reqData = new RequestData( self::getRequestData( $grantID ) );
		$response = $this->executeHandler( $handler, $reqData );
		$this->assertSame( 204, $response->getStatusCode() );
	}

	public static function provideSuccessData() {
		yield 'Same as previous grant ID' => [ '1111-1111', '1111-1111' ];
		yield 'Add grant ID' => [ '1111-1111', null ];
		yield 'Replace grant ID' => [ '2222-2222', '1111-1111' ];
	}
}
