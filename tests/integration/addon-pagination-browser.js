// Run with playwright-cli run-code after addon-pagination-seed.php on an isolated local site.
// Start the browser on the fixture's menu_url. Uses real HTTP endpoints and WooCommerce sessions.
async (page) => {
  const origin = new URL(page.url()).origin;
  if (!/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/.test(origin)) throw new Error('Local QA site only.');
  const menuUrl = page.url();
  const assert = (value, message) => { if (!value) throw new Error(message); };
  const requests = [];
  page.on('response', response => requests.push({ url: response.url(), status: response.status() }));
  // Exercise WordPress's plain-permalink REST routing without changing shared site settings.
  await page.addInitScript(() => {
    let config;
    Object.defineProperty(window, 'WOWRESTRO', {
      configurable: true,
      get: () => config,
      set: value => {
        config = value;
        if (sessionStorage.getItem('wr-qa-plain-rest') === 'yes') {
          value.storeApi = location.origin + '/index.php?rest_route=/wc/store/v1/';
          value.pluginApi = location.origin + '/index.php?rest_route=/wowrestro/v1';
        }
      }
    });
  });
  const results = [];
  for (const plain of [false, true]) {
    await page.context().clearCookies();
    await page.evaluate(value => sessionStorage.setItem('wr-qa-plain-rest', value), plain ? 'yes' : 'no');
    requests.length = 0;
    await page.goto(menuUrl);
    await page.getByRole('button', { name: 'Customise ZZ Pagination required extra dish' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByRole('radio', { name: /Required decimal extra/ }).waitFor();
    await dialog.getByRole('button', { name: /^Add · / }).click();
    await dialog.getByRole('alert').getByText('Choose one option to add this item.').waitFor();
    assert(!requests.some(request => request.url.includes('/cart/add-item')), 'Missing required option reached the cart API.');
    await dialog.getByRole('radio', { name: /Required decimal extra/ }).check();
    assert(/13[,.]75/.test(await dialog.getByRole('button', { name: /^Add · / }).innerText()), 'Decimal add-on missing from displayed unit price.');
    await dialog.getByRole('button', { name: '+', exact: true }).click();
    await page.waitForFunction(() => /27[,.]50/.test(document.querySelector('[data-modaladd]')?.textContent || ''));
    const addedResponse = page.waitForResponse(response => response.url().includes('/cart/add-item') && response.request().method() === 'POST');
    await dialog.getByRole('button', { name: /^Add · / }).click();
    const response = await addedResponse;
    const cart = await response.json();
    assert(response.status() === 201, 'Adding the configured dish failed.');
    const item = cart.items.find(item => item.sku === 'wr-pagination-target');
    assert(item && item.quantity === 2 && item.prices.price === '1375' && item.totals.line_total === '2750', 'Incorrect WooCommerce add-on quantity or totals.');
    assert(item.item_data.some(data => data.value.includes('Required decimal extra')), 'Add-on metadata missing from cart.');
    assert(requests.some(request => request.url.includes('/menu') && request.url.includes('page=2') && request.status === 200), 'Metadata beyond the first 60 products was not fetched.');
    if (plain) assert(requests.some(request => request.url.includes('rest_route=/wowrestro/v1/menu&per_page=60&page=2')), 'Plain REST parameters were not preserved.');
    await page.reload();
    await page.locator('article').filter({ hasText: 'ZZ Pagination required extra dish' }).waitFor();
    const persisted = await page.evaluate(async () => (await fetch(window.WOWRESTRO.storeApi + 'cart', { credentials: 'same-origin' })).json());
    const saved = persisted.items.find(item => item.sku === 'wr-pagination-target');
    assert(saved && saved.quantity === 2 && saved.totals.line_total === '2750', 'Add-on cart totals did not survive reload.');
    results.push({ routing: plain ? 'plain REST' : 'site default', unit_price: item.prices.price, quantity: item.quantity, line_total: item.totals.line_total });
  }
  const catalogResponse = page.waitForResponse(response => /\/products[?&]per_page=100&catalog_visibility=catalog(?:&|$)/.test(response.url()));
  await page.goto(origin + '/addon-pagination-catalog-qa/');
  const catalog = await (await catalogResponse).json();
  assert(!catalog.some(product => product.catalog_visibility === 'hidden' || product.name.startsWith('A Pagination QA padding')), 'Hidden products leaked into the public menu.');
  await page.locator('article').filter({ hasText: 'ZZ Pagination required extra dish' }).waitFor();
  assert(await page.getByText(/A Pagination QA padding/).count() === 0, 'Hidden products are rendered in the menu.');
  assert(await page.getByRole('button', { name: /Pagination QA padding/ }).count() === 0, 'Empty hidden-product category is displayed.');
  return { results, hidden_catalog_products: 0, catalog_products: await page.locator('article').count() };
}
