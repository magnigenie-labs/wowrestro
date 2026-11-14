// Start on a local WowRestro menu with a real WooCommerce cart containing a test item.
// The cart response is fetched from WordPress unchanged, then delayed until after editing.
async (page) => {
  if (!/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?\//.test(page.url())) throw new Error('Local QA site only.');
  const checkoutUrl = await page.evaluate(async () => {
    sessionStorage.removeItem('wowrestroCheckout');
    const cfg = window.WOWRESTRO;
    const response = await fetch(cfg.storeApi + 'cart/update-customer', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Nonce: cfg.nonce },
      body: JSON.stringify({ billing_address: { email: 'stored-prefill@example.test', first_name: 'Stored', last_name: 'Customer', phone: '07700900123' } })
    });
    if (!response.ok) throw new Error('Could not prepare the real customer session.');
    return cfg.checkoutUrl;
  });
  if (!checkoutUrl) throw new Error('Checkout URL missing from menu configuration.');

  let release, received, sent;
  const gate = new Promise(resolve => { release = resolve; });
  const fetched = new Promise(resolve => { received = resolve; });
  const delivered = new Promise(resolve => { sent = resolve; });
  const routePattern = /\/wc\/store\/v1\/cart(?:\?.*)?$/;
  let held = false;
  await page.route(routePattern, async route => {
    if (held || route.request().method() !== 'GET') return route.continue();
    held = true;
    const response = await route.fetch();
    received();
    await gate;
    await route.fulfill({ response });
    sent();
  });
  try {
    await page.goto(checkoutUrl, { waitUntil: 'domcontentloaded' });
    await fetched;
    const email = page.locator('#oe-f-email');
    await email.fill('customer-started@example.test');
    await email.fill('');
    await page.locator('#oe-f-name').fill('Customer typed this');
    release();
    await delivered;
    await page.waitForFunction(() => document.querySelector('#oe-f-phone')?.value === '07700900123');
    const values = await page.locator('[data-field]').evaluateAll(fields => Object.fromEntries(fields.map(field => [field.dataset.field, field.value])));
    if (values.email !== '') throw new Error('Delayed cart prefill restored a deliberately cleared email: ' + values.email);
    if (values.name !== 'Customer typed this') throw new Error('Delayed cart prefill replaced the customer name.');
    await email.pressSequentially('new-address@example.test');
    if (await email.inputValue() !== 'new-address@example.test') throw new Error('Typing after prefill appended to stale customer details.');
    return { cleared_email_preserved: true, typed_name_preserved: true, untouched_phone_prefilled: true, replacement_email: await email.inputValue() };
  } finally {
    release();
    await page.unroute(routePattern);
  }
}
