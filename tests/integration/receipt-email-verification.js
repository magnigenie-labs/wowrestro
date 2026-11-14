/**
 * Browser regression helper, using a real Playwright Page and an existing local
 * guest order older than WooCommerce's verification grace period (10 minutes).
 * No test framework or database/settings changes are required.
 */
async function verifyReceiptEmail( page, receiptUrl, billingEmail, expectedText, screenshotPath ) {
	const context = await page.context().browser().newContext();
	try {
		const receipt = await context.newPage();
		const navigationMethods = [];
		receipt.on( 'request', request => {
			if ( request.isNavigationRequest() ) navigationMethods.push( request.method() );
		} );
		await receipt.goto( receiptUrl, { waitUntil: 'domcontentloaded' } );
		await receipt.getByRole( 'textbox', { name: /Email address/ } ).fill( 'wrong-' + billingEmail );
		await receipt.getByRole( 'button', { name: 'Verify', exact: true } ).click();
		await receipt.waitForLoadState( 'domcontentloaded' );
		const denied = await receipt.locator( 'body' ).innerText();
		if ( ! denied.includes( 'unable to verify' ) || expectedText.some( value => denied.includes( value ) ) ) {
			throw new Error( 'Incorrect email must not expose receipt details.' );
		}
		if ( navigationMethods.at( -1 ) !== 'POST' ) throw new Error( 'Verification POST was redirected.' );
		await receipt.getByRole( 'textbox', { name: /Email address/ } ).fill( billingEmail );
		await receipt.getByRole( 'button', { name: 'Verify', exact: true } ).click();
		await receipt.waitForLoadState( 'domcontentloaded' );
		const accepted = await receipt.locator( 'body' ).innerText();
		if ( accepted.includes( 'unable to verify' ) || expectedText.some( value => ! accepted.includes( value ) ) ) {
			throw new Error( 'Correct email must show saved receipt details.' );
		}
		if ( navigationMethods.at( -1 ) !== 'POST' ) throw new Error( 'Successful verification was redirected.' );
		if ( screenshotPath ) await receipt.screenshot( { path: screenshotPath, fullPage: true } );
	} finally {
		await context.close();
	}
}

module.exports = verifyReceiptEmail;
