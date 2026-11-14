// State handoff regression, NOT processor sandbox payment verification.
// Copy fixtures/qa-card-handoff-probe.{php,js} into the disposable site's mu-plugins.
// The cookie-gated probe gateway renders in Blocks/classic but refuses every payment.
async (page) => {
  const origin = new URL(page.url()).origin;
  if (!['127.0.0.1', 'localhost'].includes(new URL(origin).hostname)) throw new Error('Local QA only.');
  const assert = (ok, message) => { if (!ok) throw new Error(message); };
  const results = [];
  for (const classic of [false, true]) {
    await page.context().clearCookies();
    await page.context().addCookies([{ name: 'wr_handoff_probe', value: '1', url: origin }]);
    await page.goto(origin + '/qa-menu/', { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => sessionStorage.clear());
    await page.getByRole('button', { name: 'Delivery', exact: true }).click();
    await page.getByRole('button', { name: 'Add QA Plain Soup', exact: true }).click();
    await page.getByRole('button', { name: /Checkout ·/ }).click();
    for (const [name, value] of Object.entries({ 'Email address': 'qa-handoff@example.test', 'Full name': 'Card Handoff', Phone: '+442079460123', 'Street address': '42 Handoff Street', City: 'London', Postcode: 'SW1A 1AA', 'Notes for the kitchen': "Don't ring the doorbell" })) {
      await page.getByRole('textbox', { name, exact: true }).fill(value);
    }
    await page.getByRole('checkbox', { name: 'Email me status updates for this order' }).setChecked(!classic);
    await page.getByRole('button', { name: 'Schedule', exact: true }).click();
    await page.getByRole('combobox', { name: 'Day', exact: true }).selectOption({ label: 'Tomorrow' });
    await page.getByRole('combobox', { name: 'Time', exact: true }).selectOption({ label: '12:30 pm' });
    const requested = await page.getByRole('combobox', { name: 'Time', exact: true }).inputValue();
    await page.getByRole('button', { name: 'QA card handoff probe (no payments)', exact: true }).click();
    if (classic) await page.evaluate(() => { window.WOWRESTRO.checkoutUrl = location.origin + '/qa-native-classic/'; });
    const checkoutRequests = [];
    const listener = request => { if (/\/wc\/store\/v1\/checkout(?:\?|$)/.test(request.url())) checkoutRequests.push(request.method()); };
    page.on('request', listener);
    let draft, updateStatus;
    const checkoutPattern = /\/wc\/store\/v1\/checkout(?:\?|$)/;
    await page.route(checkoutPattern, async route => {
      if (route.request().method() !== 'PUT' || draft) return route.continue();
      // Read the real response before redirect navigation discards its DevTools body.
      const actual = await route.fetch();
      draft = await actual.json();
      updateStatus = actual.status();
      await route.fulfill({ response: actual });
    });
    const updated = page.waitForResponse(r => /\/wc\/store\/v1\/checkout(?:\?|$)/.test(r.url()) && r.request().method() === 'PUT');
    await page.getByRole('button', { name: /Place order ·/ }).click();
    await updated;
    assert(updateStatus === 200, 'Checkout handoff update failed.');
    assert(draft.status === 'checkout-draft', 'Handoff placed an order instead of preparing checkout.');
    assert(draft.payment_method === 'wr_handoff_probe', 'Gateway selection was not persisted.');
    await page.waitForURL(/wowrestro_gateway=wr_handoff_probe/);
    const prefix = classic ? '#billing_' : '#shipping-';
    for (const [field, value] of Object.entries({ first_name: 'Card', last_name: 'Handoff', address_1: '42 Handoff Street', city: 'London', postcode: 'SW1A 1AA', phone: '+442079460123' })) {
      assert(await page.locator(prefix + field).inputValue() === value, 'Native ' + field + ' was lost.');
    }
    assert(await page.locator(classic ? '#billing_email' : '#email').inputValue() === 'qa-handoff@example.test', 'Email was lost.');
    assert(await page.locator(classic ? '#order_comments' : '.wc-block-checkout__order-notes textarea').inputValue() === "Don't ring the doorbell", 'Kitchen note was lost.');
    assert(await page.locator(classic ? '#wowrestro_status_updates' : '#order-wowrestro-status-updates').isChecked() === !classic, 'Email preference was changed.');
    assert(await page.locator(classic ? '#wowrestro_requested_time' : '#order-wowrestro-requested-time').inputValue() === requested, 'Scheduled time was changed.');
    assert(await page.locator(classic ? '#wowrestro_fulfillment_mode' : '#order-wowrestro-fulfillment-mode').inputValue() === 'delivery', 'Fulfillment was changed.');
    const payment = classic ? page.locator('#payment_method_wr_handoff_probe') : page.getByRole('radio', { name: 'QA card handoff probe (no payments)', exact: true });
    await payment.waitFor();
    assert(await payment.isChecked(), 'Native payment radio did not retain the selected gateway.');
    assert(!checkoutRequests.includes('POST'), 'Handoff submitted a payment request.');
    page.off('request', listener);
    await page.unroute(checkoutPattern);
    await page.screenshot({ path: 'output/playwright/card-handoff-' + (classic ? 'classic' : 'blocks') + '.png', fullPage: true });
    results.push({ checkout: classic ? 'classic' : 'Blocks', contact_address_note_preserved: true, gateway_selected: true, requested_time: requested, status_updates: !classic, payment_submitted: false });
  }
  return results;
}
