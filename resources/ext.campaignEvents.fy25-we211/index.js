/**
 * FY25 WE2.1.1 Module
 *
 * Simple JavaScript module for FY25 WE2.1.1 campaign.
 * Modifies Special:HomePage links to include source tracking.
 *
 * Read more https://phabricator.wikimedia.org/T402496
 */
( function () {
	'use strict';

	const source = 'fy25-we211-banner1';
	/**
	 * Check the following wiki text formats:
	 * [[Special:HomePage]] - Basic link
	 * [[Special:HomePage|Visit HomePage]] - Link with custom text
	 * [[Special:HomePage#section]] - Link with hash fragment
	 * [[:Special:HomePage]] - Link with colon prefix
	 * [[special:homepage]] - Lowercase
	 * [[SPECIAL:HOMEPAGE]] - Uppercase
	 * [[Special:homepage]] - Mixed case
	 */
	$( () => {
		const bodyContent = document.getElementById( 'mw-content-text' );
		const homePageLocalName = mw.config.get( 'wgSpecialHomepageLocalName' );
		const specialHomepageLinks = getLinksWithSpecialPage( homePageLocalName );

		specialHomepageLinks.forEach( ( link ) => {
			const href = link.getAttribute( 'href' );
			const separator = href.includes( '?' ) ? '&' : '?';
			link.setAttribute( 'href', href + separator + 'source=' + source );
		} );

		function getLinksWithSpecialPage( pageName ) {
			return Array.from( bodyContent.querySelectorAll( 'a[href]' ) ).filter( ( link ) => {
				try {
					// strip protocol, host, and any prefix before index.php or /wiki/
					const href = decodeURIComponent( link.getAttribute( 'href' ) )
						.replace( /^.*(?:\/wiki\/|index\.php\/)/, '' );
					const title = mw.Title.newFromText( href );
					return title && title.getNamespaceId() === -1 &&
						title.getMainText().toLowerCase() === pageName.toLowerCase();
				} catch ( e ) {
					return false;
				}
			} );
		}
	} );

}() );
